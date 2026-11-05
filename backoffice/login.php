<?php
declare(strict_types=1);

/**
 * login.php — entrada na área da oficina.
 *
 * Fluxo:
 *   1. GET  → formulário de email + palavra-passe.
 *   2. POST → token CSRF, limite de tentativas, consulta do utilizador
 *             por email e password_verify(). Se as credenciais estiverem
 *             certas, abre a sessão (ver sessao.php) e redireciona (303)
 *             para o painel; se não, voltam os campos e uma mensagem.
 *
 * Segurança:
 *   • password_verify() sobre o hash guardado; nunca uma comparação de
 *     texto simples. As contas criadas com sql/utilizadores-iniciais.sql
 *     já trazem password_hash().
 *   • Mensagem única para email desconhecido e palavra-passe errada — não
 *     se revela que contas existem.
 *   • password_verify() corre sempre, mesmo sem conta, contra um hash de
 *     recurso: o tempo de resposta não distingue os dois casos.
 *   • Limite por sessão contra força bruta (MAX_TENTATIVAS na janela).
 *   • Hash atualizado automaticamente quando o custo do algoritmo muda.
 *   • O formulário tem token CSRF e o cookie de sessão é HttpOnly.
 */

require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/conexao.php';

Sessao::iniciar();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store');

// Quem já está dentro não precisa desta página.
if (Sessao::utilizador() !== null) {
    header('Location: painel.php', true, 303);
    exit;
}

/** Tentativas falhadas permitidas dentro da janela. */
const MAX_TENTATIVAS = 6;

/** Janela do limite de tentativas: 10 minutos. */
const JANELA_TENTATIVAS = 600;

/**
 * Hash de recurso. Verifica-se sempre uma palavra-passe, para que uma
 * conta inexistente e uma palavra-passe errada demorem o mesmo tempo.
 */
const HASH_SEM_CONTA = '$2y$12$qknyY09SK8KAFX/cpYfIEOPaKPcjVzUefcMEzEzkGGUAhfnMJj4SO';

/** Escapa texto para HTML. */
function h(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$email       = '';
$password    = '';
$erroGeral   = '';
$erroEmail   = '';
$erroPassword = '';
$motivo      = is_string($_GET['motivo'] ?? null) ? $_GET['motivo'] : '';
$aviso       = '';

$tentativas = $_SESSION['tentativas_login'] ?? ['n' => 0, 'desde' => time()];

// Janela terminada: o contador volta a zero.
if ((time() - (int) $tentativas['desde']) > JANELA_TENTATIVAS) {
    $tentativas = ['n' => 0, 'desde' => time()];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['palavra_passe'] ?? null) ? $_POST['palavra_passe'] : '';

    $token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null;

    if (!Sessao::csrfValido($token, 'csrf_entrada')) {
        $erroGeral = 'O formulário expirou. Tenta outra vez.';
    } elseif ($tentativas['n'] >= MAX_TENTATIVAS) {
        $erroGeral = 'Demasiadas tentativas falhadas. Espera alguns minutos antes de tentar outra vez.';
    } else {
        if ($email === '') {
            $erroEmail = 'Indica o email da tua conta.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $erroEmail = 'Este email não parece estar completo.';
        }

        if ($password === '') {
            $erroPassword = 'Indica a tua palavra-passe.';
        }

        if ($erroEmail === '' && $erroPassword === '') {
            try {
                $pdo = obterConexao();

                try {
                    $consulta = $pdo->prepare(
                        'SELECT id, nome, email, palavra_passe, cargo, sessao_versao
                           FROM utilizadores
                          WHERE email = ?
                          LIMIT 1'
                    );
                    $consulta->execute([$email]);
                } catch (PDOException $excecao) {
                    // Base de dados sem a migração sql/migracao-sessoes.sql:
                    // entra na mesma, sem a versão de sessão.
                    if ($excecao->getCode() !== '42S22') {
                        throw $excecao;
                    }

                    $consulta = $pdo->prepare(
                        'SELECT id, nome, email, palavra_passe, cargo
                           FROM utilizadores
                          WHERE email = ?
                          LIMIT 1'
                    );
                    $consulta->execute([$email]);
                }

                $utilizador = $consulta->fetch();

                if (is_array($utilizador)) {
                    $utilizador['sessao_versao'] = (string) ($utilizador['sessao_versao'] ?? '');
                }

                $hash = is_array($utilizador)
                    ? (string) $utilizador['palavra_passe']
                    : HASH_SEM_CONTA;

                $aceite = password_verify($password, $hash);

                if (is_array($utilizador) && $aceite) {
                    // Algoritmo ou custo mudou desde que o hash foi criado:
                    // aproveita o único momento em que há a palavra-passe.
                    $hashAtual = $hash;

                    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                        $novoHash = password_hash($password, PASSWORD_DEFAULT);

                        $atualizar = $pdo->prepare(
                            'UPDATE utilizadores SET palavra_passe = ? WHERE id = ?'
                        );
                        $atualizar->execute([$novoHash, (int) $utilizador['id']]);

                        $hashAtual = $novoHash;
                    }

                    // A sessão fica presa ao hash com que se entrou.
                    Sessao::entrar($utilizador, $hashAtual);

                    header('Location: painel.php', true, 303);
                    exit;
                }

                // Falha: conta-se a tentativa e responde-se uma única vez.
                $tentativas['n'] = (int) $tentativas['n'] + 1;
                $_SESSION['tentativas_login'] = $tentativas;

                // Atraso curto: encarece cada tentativa sem castigar o uso normal.
                usleep(250000);

                $erroGeral = 'Email ou palavra-passe incorretos.';
                $erroPassword = 'Confirma a palavra-passe e tenta outra vez.';
            } catch (Throwable $excecao) {
                error_log('[FixIt][Entrada] ' . $excecao->getMessage());
                $erroGeral = 'Não foi possível ligar à base de dados. Tenta novamente daqui a pouco.';
            }
        }
    }
} elseif ($motivo === 'expirada') {
    $erroGeral = 'A sessão expirou. Entra outra vez para continuares.';
} elseif ($motivo === 'saida') {
    $aviso = 'Sessão terminada.';
} elseif ($motivo === 'terminada') {
    $erroGeral = 'A palavra-passe desta conta mudou, por isso esta sessão terminou. Entra com a palavra-passe nova.';
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar — FixIt</title>
    <meta name="description" content="Entrada na área da oficina FixIt.">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0C2038">

    <link rel="stylesheet" href="../assets/css/painel.css">
    <link rel="stylesheet" href="../assets/css/formulario.css">
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body class="pagina-entrada">

<main class="acesso" id="conteudo">

    <!-- =============================================================
         APRESENTAÇÃO
         ============================================================= -->
    <section class="acesso__apresentacao" aria-label="FixIt, assistência técnica">
        <a class="marca" href="../frontoffice/index.php">
            <span class="marca__simbolo" aria-hidden="true">
                <!-- Marca: cabeça de parafuso em cruz. -->
                <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round">
                    <circle cx="12" cy="12" r="8.25"/>
                    <path d="M12 7.5v9M7.5 12h9"/>
                </svg>
            </span>
            <span class="marca__texto">
                <span class="marca__nome">FixIt</span>
                <span class="marca__descricao">Assistência técnica</span>
            </span>
        </a>

        <p class="acesso__frase">
            Cada pedido, orçamento e reparação com o histórico à mão.
        </p>

        <p class="acesso__rodape">
            <a href="../frontoffice/index.php">Consulta pública de reparações</a>
        </p>
    </section>

    <!-- =============================================================
         FORMULÁRIO
         ============================================================= -->
    <section class="acesso__zona" aria-labelledby="titulo-entrada">

        <div class="acesso__caixa">

            <h1 id="titulo-entrada">Entra na oficina</h1>
            <p class="acesso__sub">
                Usa a conta que foi criada para ti na área da oficina.
            </p>

            <?php if ($erroGeral !== ''): ?>
                <div class="alerta acesso__alerta" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 4.5 21 20H3L12 4.5Z"/>
                        <path d="M12 10v4"/>
                        <path d="M12 17h.01"/>
                    </svg>
                    <p class="alerta__texto"><?= h($erroGeral) ?></p>
                </div>
            <?php elseif ($aviso !== ''): ?>
                <div class="alerta alerta--sucesso acesso__alerta" role="status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m5 12.5 4.5 4.5L19 7"/>
                    </svg>
                    <p class="alerta__texto"><?= h($aviso) ?></p>
                </div>
            <?php endif; ?>

            <form class="acesso__formulario" method="post" action="login.php" novalidate>

                <input type="hidden" name="csrf" value="<?= h(Sessao::csrf('csrf_entrada')) ?>">

                <div class="campo<?= $erroEmail !== '' ? ' campo--invalido' : '' ?>">
                    <label class="campo__rotulo" for="email">
                        Email<span class="obrigatorio" aria-hidden="true"> *</span>
                    </label>
                    <input class="entrada" type="email" id="email" name="email"
                           value="<?= h($email) ?>"
                           autocomplete="username" inputmode="email"
                           maxlength="190" spellcheck="false"
                           aria-describedby="erro-email"
                           <?= $erroEmail !== '' ? 'aria-invalid="true"' : '' ?>
                           required>
                    <p class="campo__erro" id="erro-email" <?= $erroEmail === '' ? 'hidden' : '' ?>><?= h($erroEmail) ?></p>
                </div>

                <div class="campo<?= $erroPassword !== '' ? ' campo--invalido' : '' ?>">
                    <label class="campo__rotulo" for="palavra_passe">
                        Palavra-passe<span class="obrigatorio" aria-hidden="true"> *</span>
                    </label>
                    <input class="entrada" type="password" id="palavra_passe" name="palavra_passe"
                           autocomplete="current-password"
                           maxlength="200"
                           aria-describedby="erro-palavra-passe"
                           <?= $erroPassword !== '' ? 'aria-invalid="true"' : '' ?>
                           required>
                    <p class="campo__erro" id="erro-palavra-passe" <?= $erroPassword === '' ? 'hidden' : '' ?>><?= h($erroPassword) ?></p>
                </div>

                <button class="botao botao--primario acesso__submeter" type="submit">
                    Entrar
                </button>
            </form>

        </div>
    </section>
</main>

</body>
</html>
