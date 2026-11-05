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
    <meta name="theme-color" content="#2743ff">

    <!-- As duas fontes desta página são servidas daqui: a entrada não faz
         um único pedido a terceiros. `crossorigin` é obrigatório nos
         preloads de fontes, mesmo com o ficheiro na mesma origem. -->
    <link rel="preload" href="../assets/fonts/bricolage-grotesque-latin.woff2" as="font"
          type="font/woff2" crossorigin>
    <link rel="preload" href="../assets/fonts/inter-latin.woff2" as="font"
          type="font/woff2" crossorigin>

    <link rel="stylesheet" href="../assets/css/painel.css">
    <link rel="stylesheet" href="../assets/css/formulario.css">
    <link rel="stylesheet" href="../assets/css/login.css">

    <!-- O tema escolhido vive neste browser, não na conta. É lido antes do
         primeiro desenho para a página não aparecer clara e escurecer logo
         a seguir; o botão que o troca está no canto da folha. -->
    <script>
        try {
            var temaGuardado = localStorage.getItem('fixit-tema');
            if (temaGuardado === 'tinta' || temaGuardado === 'papel') {
                document.documentElement.setAttribute('data-tema', temaGuardado);
            }
        } catch (e) {}
    </script>
</head>
<body class="pagina-entrada">

<main class="porta" id="conteudo">

    <!-- =============================================================
         A METADE DO COBALTO
         ============================================================= -->
    <section class="porta__marca" aria-label="FixIt, assistência técnica">

        <a class="porta__logotipo" href="../frontoffice/index.php">
            <span class="porta__simbolo" aria-hidden="true">
                <!-- Marca: cabeça de parafuso em cruz. -->
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round">
                    <circle cx="12" cy="12" r="8.25"/>
                    <path d="M12 7.5v9M7.5 12h9"/>
                </svg>
            </span>
            <span>
                <span class="porta__nome">FixIt</span>
                <span class="porta__descritor">Assistência técnica</span>
            </span>
        </a>

        <div class="porta__discurso">
            <h1 class="porta__titulo">
                Cada reparação, do balcão à <span class="porta__sublinhado">entrega</span>.
            </h1>

            <p class="porta__apoio">
                Receção com fotografias, diagnóstico, orçamento antes de mexer e o
                PIN do cliente para acompanhar cada passo. Tudo no mesmo sítio.
            </p>

            <!-- No lugar do depoimento: o que é verdade sobre o balcão. Não
                 há estrelas nem citações que ninguém disse. -->
            <figure class="porta__facto">
                <span class="porta__facto-rotulo">No balcão</span>
                <p>O equipamento é entregue e levantado na oficina. Não há recolha nem envio.</p>
                <figcaption>FixIt · assistência técnica</figcaption>
            </figure>

            <!-- As cinco fases do ciclo de vida, as mesmas que o cliente vê
                 no talão. Decorativas para quem ouve a página: a informação
                 está no site público e não acrescenta nada ao formulário. -->
            <div class="porta__estagios" aria-hidden="true">
                <ol>
                    <li><span>01</span> Recebido</li>
                    <li><span>02</span> Diagnóstico</li>
                    <li><span>03</span> Reparação</li>
                    <li><span>04</span> Pronto</li>
                    <li><span>05</span> Entregue</li>
                </ol>
            </div>
        </div>

        <div class="porta__gama">
            <p class="porta__gama-rotulo">Entram na oficina</p>
            <ul>
                <li>Portáteis</li>
                <li>Desktops</li>
                <li>Telemóveis</li>
                <li>Tablets</li>
            </ul>
        </div>
    </section>

    <!-- =============================================================
         A METADE DO PAPEL
         ============================================================= -->
    <section class="porta__lado" aria-labelledby="titulo-entrada">

        <div class="porta__topo">
            <button class="tema-botao" type="button" data-alternar-tema
                    aria-pressed="false" aria-label="Tema escuro (tinta)">
                <svg class="tema-botao__sol" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="3.6"/>
                    <path d="M12 3v2.2M12 18.8V21M3 12h2.2M18.8 12H21M5.6 5.6l1.6 1.6M16.8 16.8l1.6 1.6M18.4 5.6l-1.6 1.6M7.2 16.8l-1.6 1.6"/>
                </svg>
                <svg class="tema-botao__lua" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 14.2A8.2 8.2 0 0 1 9.8 4a8.4 8.4 0 1 0 10.2 10.2Z"/>
                </svg>
                <span class="tema-botao__texto">Tema</span>
            </button>

            <a class="porta__voltar" href="../frontoffice/index.php">
                Voltar ao site
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M7 17 17 7"/>
                    <path d="M9 7h8v8"/>
                </svg>
            </a>
        </div>

        <div class="porta__caixa">

            <h2 class="porta__titulo-form" id="titulo-entrada">Bem-vindo de volta</h2>
            <p class="porta__sub">Entra na área da oficina para continuares o dia.</p>

            <?php if ($erroGeral !== ''): ?>
                <div class="alerta porta__alerta porta__alerta--erro" role="alert">
                    <p class="alerta__texto"><?= h($erroGeral) ?></p>
                </div>
            <?php elseif ($aviso !== ''): ?>
                <div class="alerta porta__alerta porta__alerta--ok" role="status">
                    <p class="alerta__texto"><?= h($aviso) ?></p>
                </div>
            <?php endif; ?>

            <form class="porta__form" method="post" action="login.php" novalidate>

                <input type="hidden" name="csrf" value="<?= h(Sessao::csrf('csrf_entrada')) ?>">

                <div class="campo<?= $erroEmail !== '' ? ' campo--invalido' : '' ?>">
                    <label class="porta__campo-label" for="email">Email</label>
                    <div class="porta__controlo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="5" width="18" height="14" rx="2.5"/>
                            <path d="m3.8 7.2 8.2 5.8 8.2-5.8"/>
                        </svg>
                        <input class="porta__input" type="email" id="email" name="email"
                               value="<?= h($email) ?>"
                               placeholder="nome@fixit.pt"
                               autocomplete="username" inputmode="email"
                               maxlength="190" spellcheck="false"
                               aria-describedby="erro-email"
                               <?= $erroEmail !== '' ? 'aria-invalid="true"' : '' ?>
                               required>
                    </div>
                    <p class="campo__erro" id="erro-email" <?= $erroEmail === '' ? 'hidden' : '' ?>><?= h($erroEmail) ?></p>
                </div>

                <div class="campo<?= $erroPassword !== '' ? ' campo--invalido' : '' ?>">
                    <label class="porta__campo-label" for="palavra_passe">Palavra-passe</label>
                    <div class="porta__controlo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="4" y="10" width="16" height="10" rx="2.5"/>
                            <path d="M8 10V7.6a4 4 0 0 1 8 0V10"/>
                        </svg>
                        <input class="porta__input" type="password" id="palavra_passe" name="palavra_passe"
                               placeholder="A tua palavra-passe"
                               autocomplete="current-password"
                               maxlength="200"
                               aria-describedby="erro-palavra-passe"
                               <?= $erroPassword !== '' ? 'aria-invalid="true"' : '' ?>
                               required>
                        <button class="porta__revelar" type="button"
                                data-alternar-palavra-passe
                                aria-pressed="false" aria-controls="palavra_passe">Mostrar</button>
                    </div>
                    <p class="campo__erro" id="erro-palavra-passe" <?= $erroPassword === '' ? 'hidden' : '' ?>><?= h($erroPassword) ?></p>
                    <p class="porta__aviso-caps" id="aviso-caps" role="status" hidden>
                        A tecla Caps Lock está ligada.
                    </p>
                </div>

                <p class="porta__ajuda">
                    Esqueceste-te da palavra-passe? Um administrador repõe-na em
                    <strong>Utilizadores</strong>, dentro do painel.
                </p>

                <button class="porta__botao" type="submit">Entrar</button>
            </form>

            <p class="porta__nota">
                <strong>Sem conta?</strong> É um administrador que a cria, em
                Utilizadores — esta entrada não tem registo próprio.
            </p>

            <p class="porta__legal">
                <a href="../frontoffice/privacidade.php">Privacidade</a>
                <a href="../frontoffice/index.php">Site público</a>
            </p>
        </div>
    </section>
</main>

<script src="../assets/js/tema.js" defer></script>
<script src="../assets/js/entrada.js" defer></script>

</body>
</html>
