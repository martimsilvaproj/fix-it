<?php
declare(strict_types=1);

/**
 * utilizadores.php — contas com acesso à área da oficina.
 *
 * Secção de administração: Sessao::exigirAdministrador() recusa a conta
 * antes de qualquer consulta. Um técnico que escreva o endereço à mão
 * volta ao painel com um aviso; o link nem sequer lhe aparece na barra
 * lateral.
 *
 * Modos da página:
 *   • lista        (utilizadores.php)          — todas as contas;
 *   • nova conta   (utilizadores.php?novo=1)   — nome, email, cargo e palavra-passe;
 *   • editar       (utilizadores.php?editar=ID)— nome, email e cargo;
 *   • repor palavra(utilizadores.php?palavra=ID) — só a palavra-passe;
 *   • sessões      (utilizadores.php?sessoes=ID) — terminar as sessões abertas
 *     dessa conta, sem lhe tocar na palavra-passe.
 *
 * Todas as alterações são POST com token CSRF e terminam num
 * redirecionamento (POST → Redirect → GET), para que um refresh não repita
 * a gravação. As palavras-passe nunca são mostradas nem devolvidas ao HTML:
 * entram por password_hash() e só o login as confirma com password_verify().
 *
 * Regras:
 *   • o email é o identificador de login, por isso é único (sem distinguir
 *     maiúsculas) e guardado em minúsculas;
 *   • a palavra-passe tem de ter pelo menos 8 caracteres e ser repetida;
 *   • o último administrador não pode ser despromovido — sem isso a
 *     oficina ficava sem ninguém com acesso a esta secção;
 *   • terminar sessões grava uma `sessao_versao` nova para essa conta e as
 *     sessões com o valor antigo caem no pedido seguinte (a mesma mecânica
 *     do cartão "Sessões abertas" em conta.php). Se a conta for a de quem
 *     está a terminar, esta sessão recebe a versão nova e fica aberta.
 *
 * O número de sessões mostrado antes de as terminar vem de
 * Sessao::sessoesAbertas(): só contagens, nunca IDs de sessão.

 * Requisitos: PHP 8.0+, pdo_mysql e a base de dados fixit.
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/layout-painel.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';

Sessao::iniciar();

/** @var array{id: int, nome: string, email: string, cargo: string} $utilizador */
$utilizador = Sessao::exigirAdministrador();

// Contador do menu: quantos pedidos do site estão por contactar.
$solicitacoesNovas = solicitacoesNovas();

// =====================================================================
//  CONFIGURAÇÃO
// =====================================================================
const CARGO_ADMIN = 'Administrador';
const CARGO_TECNICO = 'Tecnico';
const MIN_PALAVRA = 8;
const MAX_PALAVRA = 200;

// =====================================================================
//  FUNÇÕES AUXILIARES
// =====================================================================

/** Escapa texto para HTML. */
function h(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Data curta em português, no formato do painel ("22 out 2026"). */
function dataCurta(?string $dataHora): string
{
    if ($dataHora === null || $dataHora === '') {
        return '—';
    }

    $momento = strtotime($dataHora);

    if ($momento === false) {
        return '—';
    }

    static $meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun',
                     'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    return date('d', $momento) . ' '
         . $meses[(int) date('n', $momento) - 1] . ' '
         . date('Y', $momento);
}

/** Comprimento em caracteres (mbstring quando existe), não em bytes. */
function comprimento(string $texto): int
{
    return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : strlen($texto);
}

/** Token CSRF desta secção, criado na primeira utilização. */
function csrfToken(): string
{
    return Sessao::csrf('csrf_utilizadores');
}

/** Campo de texto do POST, já sem espaços nas pontas. */
function textoPost(string $campo): string
{
    $valor = $_POST[$campo] ?? '';

    return is_string($valor) ? trim($valor) : '';
}

/** Campo inteiro do POST ou GET; 0 quando falta ou não é um número. */
function inteiroPedido(string $campo, string $origem = 'post'): int
{
    $fonte = $origem === 'get' ? $_GET : $_POST;
    $valor = $fonte[$campo] ?? '';

    return is_string($valor) && ctype_digit($valor) ? (int) $valor : 0;
}

/** Classe de erro do campo, para o HTML. */
function classeCampo(array $erros, string $campo): string
{
    return isset($erros[$campo]) ? ' campo--invalido' : '';
}

/** Parágrafo de erro de um campo (sempre presente, oculto sem erro). */
function erroCampo(array $erros, string $campo, string $id): string
{
    if (isset($erros[$campo])) {
        return '<p class="campo__erro" id="' . h($id) . '">' . h($erros[$campo]) . '</p>';
    }

    return '<p class="campo__erro" id="' . h($id) . '" hidden></p>';
}

/** Carrega uma conta por id, sem nunca pedir a palavra-passe. */
function carregarConta(PDO $pdo, int $id): ?array
{
    $consulta = $pdo->prepare(
        'SELECT id, nome, email, cargo, criado_em
           FROM utilizadores
          WHERE id = ?
          LIMIT 1'
    );
    $consulta->execute([$id]);
    $linha = $consulta->fetch();

    return is_array($linha) ? $linha : null;
}

/**
 * `sessao_versao` de uma conta, ou null quando a coluna ainda não existe
 * (base de dados sem sql/migracao-sessoes.sql). Sem a coluna não há como
 * terminar sessões sem mexer na palavra-passe.
 */
function carregarVersao(PDO $pdo, int $id): ?string
{
    try {
        $consulta = $pdo->prepare(
            'SELECT sessao_versao FROM utilizadores WHERE id = ? LIMIT 1'
        );
        $consulta->execute([$id]);
        $valor = $consulta->fetchColumn();
    } catch (PDOException $excecao) {
        if ($excecao->getCode() !== '42S22') {
            throw $excecao;
        }

        return null;
    }

    return is_string($valor) ? $valor : null;
}

/** Confirmação depois de terminar as sessões de uma conta. */
function mensagemTerminar(string $nome, ?int $antes, bool $propria): string
{
    if ($propria) {
        if ($antes === 0) {
            return 'Não havia outras sessões abertas na tua conta.';
        }

        if ($antes === 1) {
            return 'A outra sessão da tua conta foi terminada. Esta continua aberta.';
        }

        if ($antes !== null && $antes > 1) {
            return 'As outras ' . $antes . ' sessões da tua conta foram terminadas. Esta continua aberta.';
        }

        return 'As outras sessões da tua conta foram terminadas. Esta continua aberta.';
    }

    if ($antes === 0) {
        return 'Não havia sessões abertas na conta de ' . $nome . '.';
    }

    if ($antes === 1) {
        return 'A sessão de ' . $nome . ' foi terminada.';
    }

    if ($antes !== null && $antes > 1) {
        return 'As ' . $antes . ' sessões de ' . $nome . ' foram terminadas.';
    }

    return 'As sessões abertas na conta de ' . $nome . ' foram terminadas.';
}

/** Existe outro administrador além desta conta? */
function haOutroAdministrador(PDO $pdo, int $exceptoId): bool
{
    $consulta = $pdo->prepare(
        'SELECT COUNT(*) FROM utilizadores WHERE cargo = ? AND id <> ?'
    );
    $consulta->execute([CARGO_ADMIN, $exceptoId]);

    return (int) $consulta->fetchColumn() > 0;
}

/**
 * Valida nome, email e cargo. O email é normalizado em minúsculas e
 * comparado com as outras contas (a coluna é UNIQUE e a collation não
 * distingue maiúsculas).
 *
 * @param array{nome: string, email: string, cargo: string} $valores
 *
 * @return array<string, string>
 */
function validarConta(PDO $pdo, array $valores, int $idAtual): array
{
    $erros = [];

    if ($valores['nome'] === '') {
        $erros['nome'] = 'Indica o nome de quem vai usar a conta.';
    } elseif (comprimento($valores['nome']) < 3) {
        $erros['nome'] = 'O nome precisa de pelo menos 3 caracteres.';
    } elseif (comprimento($valores['nome']) > 120) {
        $erros['nome'] = 'O nome não pode passar de 120 caracteres.';
    }

    if ($valores['email'] === '') {
        $erros['email'] = 'Indica o email da conta.';
    } elseif (filter_var($valores['email'], FILTER_VALIDATE_EMAIL) === false) {
        $erros['email'] = 'Este email não parece estar completo.';
    } elseif (comprimento($valores['email']) > 190) {
        $erros['email'] = 'O email não pode passar de 190 caracteres.';
    } else {
        $consulta = $pdo->prepare(
            'SELECT 1 FROM utilizadores WHERE email = ? AND id <> ? LIMIT 1'
        );
        $consulta->execute([$valores['email'], $idAtual]);

        if ($consulta->fetchColumn() !== false) {
            $erros['email'] = 'Este email já pertence a outra conta.';
        }
    }

    if (!in_array($valores['cargo'], [CARGO_ADMIN, CARGO_TECNICO], true)) {
        $erros['cargo'] = 'Escolhe Administrador ou Técnico.';
    }

    return $erros;
}

/**
 * Valida a palavra-passe e a repetição.
 *
 * @return array<string, string>
 */
function validarPalavra(string $palavra, string $confirmar): array
{
    $erros = [];

    if ($palavra === '') {
        $erros['palavra_passe'] = 'Define uma palavra-passe para a conta.';
    } elseif (comprimento($palavra) < MIN_PALAVRA) {
        $erros['palavra_passe'] = 'A palavra-passe precisa de pelo menos ' . MIN_PALAVRA . ' caracteres.';
    } elseif (comprimento($palavra) > MAX_PALAVRA) {
        $erros['palavra_passe'] = 'A palavra-passe não pode passar de ' . MAX_PALAVRA . ' caracteres.';
    } elseif ($confirmar !== $palavra) {
        $erros['confirmar'] = 'As duas palavras-passe não coincidem.';
    }

    if ($palavra !== '' && $confirmar === '') {
        $erros['confirmar'] = 'Repete a palavra-passe.';
    }

    return $erros;
}

/** Confirmação que sobrevive ao redirecionamento. */
function guardarFlash(string $mensagem): void
{
    $_SESSION['utilizadores_flash'] = $mensagem;
}

/** Volta à lista (PRG) e termina o pedido. */
function irParaLista(): void
{
    header('Location: utilizadores.php#lista', true, 303);
    exit;
}

// =====================================================================
//  ESTADO DA PÁGINA
// =====================================================================
$modo            = 'lista';   // lista | novo | editar | palavra
$erros           = [];
$alerta          = '';
$conta           = null;
$valores         = ['nome' => '', 'email' => '', 'cargo' => CARGO_TECNICO];
$flash           = $_SESSION['utilizadores_flash'] ?? '';
$erroDados       = '';
$contas          = [];
$totalContas     = 0;
$totalAdmins     = 0;
$totalTecnicos   = 0;
$pedidosAtivos   = 0;

unset($_SESSION['utilizadores_flash']);

try {
    $pdo = obterConexao();
} catch (Throwable $excecao) {
    error_log('[FixIt][Utilizadores] ' . $excecao->getMessage());
    $pdo = null;
    $erroDados = 'A base de dados não respondeu. Tenta novamente dentro de momentos.';
}

if ($pdo instanceof PDO) {
    // -----------------------------------------------------------------
    //  POST — criar, editar, repor a palavra-passe ou terminar sessões
    // -----------------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $acao = is_string($_POST['acao'] ?? null) ? $_POST['acao'] : '';
        $id = inteiroPedido('id');

        if ($acao === 'criar') {
            $modo = 'novo';
        } elseif ($acao === 'guardar' || $acao === 'repor' || $acao === 'terminar') {
            $modo = $acao === 'repor'
                ? 'palavra'
                : ($acao === 'terminar' ? 'sessoes' : 'editar');
            $conta = $id > 0 ? carregarConta($pdo, $id) : null;
        }

        $token = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null;

        if (!Sessao::csrfValido($token, 'csrf_utilizadores')) {
            $alerta = 'O formulário expirou. Volta a preencher e tenta outra vez.';
        } elseif (!in_array($acao, ['criar', 'guardar', 'repor', 'terminar'], true)) {
            $alerta = 'Não foi possível reconhecer o pedido. Recarrega a página.';
        } elseif ($acao !== 'criar' && $conta === null) {
            $alerta = 'Essa conta já não existe. A lista foi atualizada.';
            $modo = 'lista';
        } elseif ($acao === 'criar' || $acao === 'guardar') {
            $valores = [
                'nome'  => textoPost('nome'),
                'email' => strtolower(textoPost('email')),
                'cargo' => textoPost('cargo'),
            ];

            $erros = validarConta($pdo, $valores, $acao === 'guardar' ? (int) $conta['id'] : 0);

            // O último administrador não pode sair do cargo.
            if ($acao === 'guardar'
                && (string) $conta['cargo'] === CARGO_ADMIN
                && $valores['cargo'] !== CARGO_ADMIN
                && !haOutroAdministrador($pdo, (int) $conta['id'])
            ) {
                $erros['cargo'] = 'Tem de ficar pelo menos um administrador com acesso à oficina.';
            }

            if ($acao === 'criar') {
                $erros += validarPalavra(textoPost('palavra_passe'), textoPost('confirmar'));
            }

            if ($erros === []) {
                if ($acao === 'criar') {
                    try {
                        $inserir = $pdo->prepare(
                            'INSERT INTO utilizadores (nome, email, palavra_passe, cargo)
                             VALUES (?, ?, ?, ?)'
                        );
                        $inserir->execute([
                            $valores['nome'],
                            $valores['email'],
                            password_hash(textoPost('palavra_passe'), PASSWORD_DEFAULT),
                            $valores['cargo'],
                        ]);

                        guardarFlash('Conta de ' . $valores['nome'] . ' criada.');
                        irParaLista();
                    } catch (PDOException $excecao) {
                        // Corrida entre duas criações com o mesmo email.
                        if ($excecao->getCode() === '23000') {
                            $erros['email'] = 'Este email já pertence a outra conta.';
                        } else {
                            throw $excecao;
                        }
                    }
                } else {
                    try {
                        $atualizar = $pdo->prepare(
                            'UPDATE utilizadores SET nome = ?, email = ?, cargo = ? WHERE id = ?'
                        );
                        $atualizar->execute([
                            $valores['nome'],
                            $valores['email'],
                            $valores['cargo'],
                            (int) $conta['id'],
                        ]);
                    } catch (PDOException $excecao) {
                        if ($excecao->getCode() === '23000') {
                            $erros['email'] = 'Este email já pertence a outra conta.';
                        } else {
                            throw $excecao;
                        }
                    }

                    if ($erros === []) {
                        // Se a conta editada é a de quem está a usar a
                        // sessão, o cabeçalho e a guarda têm de acompanhar
                        // já, sem esperar por um novo login.
                        if ((int) $conta['id'] === (int) $utilizador['id']) {
                            Sessao::atualizarPerfil(
                                $valores['nome'],
                                $valores['email'],
                                $valores['cargo']
                            );
                        }

                        guardarFlash('Conta de ' . $valores['nome'] . ' atualizada.');
                        irParaLista();
                    }
                }
            }
        } elseif ($acao === 'terminar') {
            // Terminar as sessões: não toca em nome, email, cargo nem
            // palavra-passe. Só a versão da conta muda.
            $contaId = (int) $conta['id'];
            $propria = $contaId === (int) $utilizador['id'];
            $versao  = carregarVersao($pdo, $contaId);

            if ($versao === null) {
                $alerta = 'Falta aplicar a migração sql/migracao-sessoes.sql a esta base de dados; sem ela não é possível terminar as sessões.';
            } else {
                $antes      = Sessao::sessoesAbertas($contaId, $versao, $propria);
                $novaVersao = Sessao::novaVersao();

                try {
                    $pdo->prepare(
                        'UPDATE utilizadores SET sessao_versao = ? WHERE id = ?'
                    )->execute([$novaVersao, $contaId]);

                    // Se a conta é a de quem está a terminar, esta sessão
                    // recebe a versão nova: as outras caem, esta fica.
                    if ($propria) {
                        Sessao::registarVersao($novaVersao);
                    }

                    guardarFlash(mensagemTerminar((string) $conta['nome'], $antes, $propria));
                    irParaLista();
                } catch (PDOException $excecao) {
                    if ($excecao->getCode() !== '42S22') {
                        throw $excecao;
                    }

                    $alerta = 'Falta aplicar a migração sql/migracao-sessoes.sql a esta base de dados; sem ela não é possível terminar as sessões.';
                }
            }
        } else {
            // Repor palavra-passe: não toca em nome, email nem cargo.
            $erros = validarPalavra(textoPost('palavra_passe'), textoPost('confirmar'));

            if ($erros === []) {
                $novoHash   = password_hash(textoPost('palavra_passe'), PASSWORD_DEFAULT);
                $novaVersao = Sessao::novaVersao();

                // Versão nova na mesma alteração: as sessões abertas dessa
                // conta caem já, e a contagem deixa de as mostrar.
                try {
                    $pdo->prepare(
                        'UPDATE utilizadores SET palavra_passe = ?, sessao_versao = ? WHERE id = ?'
                    )->execute([$novoHash, $novaVersao, (int) $conta['id']]);

                    $versaoGravada = true;
                } catch (PDOException $excecao) {
                    if ($excecao->getCode() !== '42S22') {
                        throw $excecao;
                    }

                    $pdo->prepare(
                        'UPDATE utilizadores SET palavra_passe = ? WHERE id = ?'
                    )->execute([$novoHash, (int) $conta['id']]);

                    $versaoGravada = false;
                }

                // A reposição fecha as sessões abertas dessa conta. Se a
                // conta for a de quem está a repor, esta sessão continua.
                if ((int) $conta['id'] === (int) $utilizador['id']) {
                    Sessao::registarCredencial($novoHash);

                    if ($versaoGravada) {
                        Sessao::registarVersao($novaVersao);
                    }
                }

                guardarFlash('Palavra-passe de ' . $conta['nome'] . ' reposta.');
                irParaLista();
            }
        }
    } else {
        // -----------------------------------------------------------------
        //  GET — abrir um formulário
        // -----------------------------------------------------------------
        if (isset($_GET['novo'])) {
            $modo = 'novo';
        } else {
            $idPedido = inteiroPedido('editar', 'get');
            $palavra = isset($_GET['palavra']);
            $idPalavra = $palavra ? inteiroPedido('palavra', 'get') : 0;
            $sessoes = isset($_GET['sessoes']);
            $idSessoes = $sessoes ? inteiroPedido('sessoes', 'get') : 0;
            $id = $idPedido > 0 ? $idPedido : ($idSessoes > 0 ? $idSessoes : $idPalavra);

            if ($id > 0) {
                $conta = carregarConta($pdo, $id);

                if ($conta === null) {
                    guardarFlash('Essa conta já não existe. A lista foi atualizada.');
                    irParaLista();
                }

                $modo = $sessoes ? 'sessoes' : ($palavra ? 'palavra' : 'editar');
            }
        }

        if ($conta !== null) {
            $valores = [
                'nome'  => (string) $conta['nome'],
                'email' => (string) $conta['email'],
                'cargo' => (string) $conta['cargo'],
            ];
        }
    }

    // -----------------------------------------------------------------
    //  LISTA
    // -----------------------------------------------------------------
    try {
        try {
            $consulta = $pdo->prepare(
                'SELECT id, nome, email, cargo, criado_em, sessao_versao
                   FROM utilizadores
                  ORDER BY nome'
            );
            $consulta->execute();
        } catch (PDOException $excecao) {
            // Base de dados sem a migração sql/migracao-sessoes.sql: a
            // lista abre na mesma, sem a contagem de sessões.
            if ($excecao->getCode() !== '42S22') {
                throw $excecao;
            }

            $consulta = $pdo->prepare(
                'SELECT id, nome, email, cargo, criado_em
                   FROM utilizadores
                  ORDER BY nome'
            );
            $consulta->execute();
        }

        $contas = $consulta->fetchAll();

        foreach ($contas as $linhaConta) {
            if ((string) $linhaConta['cargo'] === CARGO_ADMIN) {
                $totalAdmins++;
            } else {
                $totalTecnicos++;
            }
        }

        $totalContas = count($contas);

        $consulta = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE estado <> 'Entregue'");
        $consulta->execute();
        $pedidosAtivos = (int) $consulta->fetchColumn();
    } catch (Throwable $excecao) {
        error_log('[FixIt][Utilizadores] ' . $excecao->getMessage());
        $erroDados = 'A base de dados não respondeu. Tenta novamente dentro de momentos.';
    }
}
?>
<?php painelAbrir(
    'utilizadores',
    'Utilizadores',
    'Contas com acesso à área da oficina FixIt.',
    [
        ['Painel', 'painel.php'],
        ['Utilizadores', null],
    ],
    $erroDados,
    true,
    $pedidosAtivos ?? -1
); ?>

        <main class="principal" id="conteudo" tabindex="-1">

            <div class="cabecalho">
                <div class="cabecalho__texto">
                    <h1>Utilizadores</h1>
                    <p class="cabecalho__descricao">
                        As contas que podem entrar na oficina. Só o administrador
                        vê esta página.
                    </p>
                </div>

                <?php if ($modo === 'lista'): ?>
                    <a class="botao botao--primario" href="utilizadores.php?novo=1">
                        <svg aria-hidden="true"><use href="#icone-mais"/></svg>
                        <span>Nova conta</span>
                    </a>
                <?php else: ?>
                    <a class="botao botao--fantasma" href="utilizadores.php#lista">Voltar à lista</a>
                <?php endif; ?>
            </div>

            <?php if ($flash !== ''): ?>
                <div class="alerta alerta--sucesso" role="status">
                    <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                    <p class="alerta__texto"><?= h($flash) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($erroDados !== ''): ?>
                <section class="painel" aria-labelledby="titulo-erro">
                    <div class="aviso aviso--erro">
                        <span class="aviso__icone" aria-hidden="true">
                            <svg><use href="#icone-info"/></svg>
                        </span>
                        <p class="aviso__titulo" id="titulo-erro">Não foi possível carregar as contas</p>
                        <p class="aviso__texto"><?= h($erroDados) ?></p>
                        <a class="botao botao--fantasma" href="utilizadores.php">Tentar novamente</a>
                    </div>
                </section>
            <?php elseif ($modo === 'novo' || $modo === 'editar'): ?>
                <!-- =====================================================
                     CRIAR / EDITAR CONTA
                     ===================================================== -->
                <section class="painel" id="formulario" aria-labelledby="titulo-formulario">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-formulario">
                                <?= $modo === 'novo' ? 'Nova conta' : 'Editar conta' ?>
                            </h2>
                            <p class="painel__descricao">
                                <?php if ($modo === 'novo'): ?>
                                    A pessoa entra com este email e a palavra-passe que definires.
                                <?php else: ?>
                                    <?= h($conta['nome']) ?> · entrou a <?= h(dataCurta($conta['criado_em'])) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <form class="acao-estado" method="post" action="utilizadores.php" novalidate>
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="acao" value="<?= $modo === 'novo' ? 'criar' : 'guardar' ?>">
                        <?php if ($modo === 'editar'): ?>
                            <input type="hidden" name="id" value="<?= (int) $conta['id'] ?>">
                        <?php endif; ?>

                        <?php if ($alerta !== ''): ?>
                            <div class="alerta" role="alert">
                                <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                                <p class="alerta__texto"><?= h($alerta) ?></p>
                            </div>
                        <?php endif; ?>

                        <div class="grelha acao-estado__campos">
                            <div class="campo<?= classeCampo($erros, 'nome') ?>">
                                <label class="campo__rotulo" for="conta-nome">Nome</label>
                                <input class="entrada" type="text" id="conta-nome" name="nome"
                                       value="<?= h($valores['nome']) ?>"
                                       autocomplete="off" maxlength="120"
                                       aria-describedby="erro-conta-nome"
                                       <?= isset($erros['nome']) ? 'aria-invalid="true"' : '' ?>
                                       required>
                                <?= erroCampo($erros, 'nome', 'erro-conta-nome') ?>
                            </div>

                            <div class="campo<?= classeCampo($erros, 'email') ?>">
                                <label class="campo__rotulo" for="conta-email">Email</label>
                                <input class="entrada" type="email" id="conta-email" name="email"
                                       value="<?= h($valores['email']) ?>"
                                       autocomplete="off" inputmode="email" spellcheck="false"
                                       maxlength="190"
                                       aria-describedby="erro-conta-email"
                                       <?= isset($erros['email']) ? 'aria-invalid="true"' : '' ?>
                                       required>
                                <?= erroCampo($erros, 'email', 'erro-conta-email') ?>
                            </div>

                            <div class="campo<?= classeCampo($erros, 'cargo') ?>">
                                <label class="campo__rotulo" for="conta-cargo">Cargo</label>
                                <select class="entrada" id="conta-cargo" name="cargo"
                                        aria-describedby="erro-conta-cargo ajuda-conta-cargo"
                                        <?= isset($erros['cargo']) ? 'aria-invalid="true"' : '' ?>>
                                    <option value="Tecnico" <?= $valores['cargo'] === CARGO_TECNICO ? 'selected' : '' ?>>
                                        Técnico
                                    </option>
                                    <option value="Administrador" <?= $valores['cargo'] === CARGO_ADMIN ? 'selected' : '' ?>>
                                        Administrador
                                    </option>
                                </select>
                                <p class="campo__ajuda" id="ajuda-conta-cargo">
                                    O administrador vê esta secção; o técnico trata dos pedidos.
                                </p>
                                <?= erroCampo($erros, 'cargo', 'erro-conta-cargo') ?>
                            </div>
                        </div>

                        <?php if ($modo === 'novo'): ?>
                            <div class="grelha acao-estado__campos">
                                <div class="campo<?= classeCampo($erros, 'palavra_passe') ?>">
                                    <label class="campo__rotulo" for="conta-palavra">Palavra-passe</label>
                                    <input class="entrada" type="password" id="conta-palavra"
                                           name="palavra_passe" autocomplete="new-password"
                                           maxlength="<?= MAX_PALAVRA ?>"
                                           aria-describedby="erro-conta-palavra ajuda-conta-palavra"
                                           <?= isset($erros['palavra_passe']) ? 'aria-invalid="true"' : '' ?>
                                           required>
                                    <p class="campo__ajuda" id="ajuda-conta-palavra">
                                        Pelo menos <?= MIN_PALAVRA ?> caracteres. Fica guardada como hash.
                                    </p>
                                    <?= erroCampo($erros, 'palavra_passe', 'erro-conta-palavra') ?>
                                </div>

                                <div class="campo<?= classeCampo($erros, 'confirmar') ?>">
                                    <label class="campo__rotulo" for="conta-confirmar">Repetir palavra-passe</label>
                                    <input class="entrada" type="password" id="conta-confirmar"
                                           name="confirmar" autocomplete="new-password"
                                           maxlength="<?= MAX_PALAVRA ?>"
                                           aria-describedby="erro-conta-confirmar"
                                           <?= isset($erros['confirmar']) ? 'aria-invalid="true"' : '' ?>
                                           required>
                                    <?= erroCampo($erros, 'confirmar', 'erro-conta-confirmar') ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="acao-estado__vazio">
                                Para mudar a palavra-passe desta conta, fecha este
                                formulário e usa <strong>Repor palavra-passe</strong> na lista.
                            </p>
                        <?php endif; ?>

                        <div class="acao-estado__rodape">
                            <p class="acao-estado__nota">
                                A palavra-passe é guardada como hash: depois de gravada,
                                ninguém a consegue ler — só repor.
                            </p>
                            <div class="acao-estado__botoes">
                                <a class="botao botao--fantasma" href="utilizadores.php#lista">Cancelar</a>
                                <button class="botao botao--primario" type="submit">
                                    <?= $modo === 'novo' ? 'Criar conta' : 'Guardar alterações' ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </section>
            <?php elseif ($modo === 'palavra'): ?>
                <!-- =====================================================
                     REPOR PALAVRA-PASSE
                     ===================================================== -->
                <section class="painel" id="formulario" aria-labelledby="titulo-formulario">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-formulario">Repor palavra-passe</h2>
                            <p class="painel__descricao">
                                <?= h($conta['nome']) ?> · <?= h($conta['email']) ?>
                            </p>
                        </div>
                    </div>

                    <form class="acao-estado" method="post" action="utilizadores.php" novalidate>
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="acao" value="repor">
                        <input type="hidden" name="id" value="<?= (int) $conta['id'] ?>">

                        <?php if ($alerta !== ''): ?>
                            <div class="alerta" role="alert">
                                <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                                <p class="alerta__texto"><?= h($alerta) ?></p>
                            </div>
                        <?php endif; ?>

                        <div class="grelha acao-estado__campos">
                            <div class="campo<?= classeCampo($erros, 'palavra_passe') ?>">
                                <label class="campo__rotulo" for="conta-palavra">Nova palavra-passe</label>
                                <input class="entrada" type="password" id="conta-palavra"
                                       name="palavra_passe" autocomplete="new-password"
                                       maxlength="<?= MAX_PALAVRA ?>"
                                       aria-describedby="erro-conta-palavra ajuda-conta-palavra"
                                       <?= isset($erros['palavra_passe']) ? 'aria-invalid="true"' : '' ?>
                                       required>
                                <p class="campo__ajuda" id="ajuda-conta-palavra">
                                    Pelo menos <?= MIN_PALAVRA ?> caracteres.
                                </p>
                                <?= erroCampo($erros, 'palavra_passe', 'erro-conta-palavra') ?>
                            </div>

                            <div class="campo<?= classeCampo($erros, 'confirmar') ?>">
                                <label class="campo__rotulo" for="conta-confirmar">Repetir a nova palavra-passe</label>
                                <input class="entrada" type="password" id="conta-confirmar"
                                       name="confirmar" autocomplete="new-password"
                                       maxlength="<?= MAX_PALAVRA ?>"
                                       aria-describedby="erro-conta-confirmar"
                                       <?= isset($erros['confirmar']) ? 'aria-invalid="true"' : '' ?>
                                       required>
                                <?= erroCampo($erros, 'confirmar', 'erro-conta-confirmar') ?>
                            </div>
                        </div>

                        <div class="acao-estado__rodape">
                            <p class="acao-estado__nota">
                                A palavra-passe anterior deixa de funcionar assim que
                                esta for gravada. Diz a nova à pessoa — não é enviada
                                por email.
                            </p>
                            <div class="acao-estado__botoes">
                                <a class="botao botao--fantasma" href="utilizadores.php#lista">Cancelar</a>
                                <button class="botao botao--primario" type="submit">
                                    Repor palavra-passe
                                </button>
                            </div>
                        </div>
                    </form>
                </section>
            <?php elseif ($modo === 'sessoes'): ?>
                <!-- =====================================================
                     TERMINAR AS SESSÕES DE UMA CONTA
                     ===================================================== -->
                <?php
                $contaId      = (int) $conta['id'];
                $contaPropria = $contaId === (int) $utilizador['id'];
                $versaoConta  = carregarVersao($pdo, $contaId);
                $sessoesConta = $versaoConta === null
                    ? null
                    : Sessao::sessoesAbertas($contaId, $versaoConta, $contaPropria);
                ?>
                <section class="painel" id="formulario" aria-labelledby="titulo-formulario">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-formulario">Terminar sessões</h2>
                            <p class="painel__descricao">
                                <?= h($conta['nome']) ?> · <?= h($conta['email']) ?><?= $contaPropria ? ' · a tua conta' : '' ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($alerta !== ''): ?>
                        <div class="alerta" role="alert">
                            <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                            <p class="alerta__texto"><?= h($alerta) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($versaoConta === null || $sessoesConta === 0): ?>
                        <!-- Sem nada para terminar não há formulário: um botão
                             que não faz nada só confunde. Com a migração em
                             falta e um aviso já visível, o aviso chega — não
                             se repete a explicação. -->
                        <?php if ($versaoConta !== null || $alerta === ''): ?>
                            <div class="acao-estado acao-estado--simples">
                                <p class="sessoes__contagem">
                                    <?php if ($versaoConta === null): ?>
                                        Esta base de dados ainda não tem a coluna das
                                        sessões. Importa <strong>sql/migracao-sessoes.sql</strong>
                                        para poder terminar sessões sem mudar a palavra-passe.
                                    <?php elseif ($contaPropria): ?>
                                        Só esta sessão está aberta.
                                    <?php else: ?>
                                        Não há sessões abertas nesta conta.
                                    <?php endif; ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <form class="acao-estado acao-estado--simples" method="post" action="utilizadores.php">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="acao" value="terminar">
                            <input type="hidden" name="id" value="<?= $contaId ?>">

                            <p class="sessoes__contagem">
                                <?php if ($sessoesConta === null): ?>
                                    Não foi possível contar as sessões nesta instalação,
                                    mas o botão termina todas as que estiverem abertas.
                                <?php elseif ($contaPropria): ?>
                                    <?php if ($sessoesConta === 1): ?>
                                        Há mais <strong>1</strong> sessão aberta além desta.
                                    <?php else: ?>
                                        Há mais <strong><?= $sessoesConta ?></strong> sessões abertas além desta.
                                    <?php endif; ?>
                                <?php elseif ($sessoesConta === 1): ?>
                                    <?= h($conta['nome']) ?> tem <strong>1</strong> sessão aberta agora.
                                <?php else: ?>
                                    <?= h($conta['nome']) ?> tem <strong><?= $sessoesConta ?></strong> sessões abertas agora.
                                <?php endif; ?>
                            </p>

                            <div class="acao-estado__rodape">
                                <p class="acao-estado__nota">
                                    <?php if ($contaPropria && $sessoesConta === 1): ?>
                                        Quem estiver na outra sessão tem de entrar outra vez,
                                        com a mesma palavra-passe. Esta continua aberta.
                                    <?php elseif ($contaPropria): ?>
                                        Quem estiver nas outras sessões tem de entrar outra vez,
                                        com a mesma palavra-passe. Esta continua aberta.
                                    <?php else: ?>
                                        Quem estiver a usar a conta tem de entrar outra vez,
                                        com a mesma palavra-passe. A palavra-passe não muda.
                                    <?php endif; ?>
                                </p>
                                <div class="acao-estado__botoes">
                                    <a class="botao botao--fantasma" href="utilizadores.php#lista">Cancelar</a>
                                    <button class="botao botao--perigo" type="submit">
                                        <svg aria-hidden="true"><use href="#icone-sair"/></svg>
                                        <?php if ($contaPropria): ?>
                                            Terminar outras sessões
                                        <?php elseif ($sessoesConta === 1): ?>
                                            Terminar a sessão
                                        <?php elseif ($sessoesConta !== null): ?>
                                            Terminar as <?= $sessoesConta ?> sessões
                                        <?php else: ?>
                                            Terminar as sessões abertas
                                        <?php endif; ?>
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <!-- =====================================================
                     LISTA DE CONTAS
                     ===================================================== -->
                <?php if ($alerta !== ''): ?>
                    <div class="alerta" role="alert">
                        <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                        <p class="alerta__texto"><?= h($alerta) ?></p>
                    </div>
                <?php endif; ?>

                <section class="painel" id="lista" aria-labelledby="titulo-lista">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-lista">Contas da oficina</h2>
                            <p class="painel__descricao">
                                <?= $totalContas ?> <?= $totalContas === 1 ? 'conta' : 'contas' ?>
                                · <?= $totalAdmins ?> <?= $totalAdmins === 1 ? 'administrador' : 'administradores' ?>
                                · <?= $totalTecnicos ?> <?= $totalTecnicos === 1 ? 'técnico' : 'técnicos' ?>.
                            </p>
                        </div>
                    </div>

                    <?php if ($contas): ?>
                        <div class="tabela__contentor">
                            <table class="tabela">
                                <caption class="visualmente-oculto">
                                    Contas com acesso à oficina, por ordem alfabética,
                                    com email, cargo, sessões abertas, data de criação
                                    e ações.
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Utilizador</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Cargo</th>
                                        <th scope="col">Sessões</th>
                                        <th scope="col">Conta desde</th>
                                        <th scope="col" class="tabela__acao">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contas as $linhaConta): ?>
                                        <?php
                                        $linhaEAdmin = (string) $linhaConta['cargo'] === CARGO_ADMIN;
                                        $linhaId     = (int) $linhaConta['id'];
                                        $linhaNome   = (string) $linhaConta['nome'];
                                        // Sessões abertas agora: só as que ainda
                                        // abrem o painel. null = desconhecido.
                                        $linhaSessoes = Sessao::sessoesAbertas(
                                            $linhaId,
                                            (string) ($linhaConta['sessao_versao'] ?? '')
                                        );
                                        // A própria linha só interessa com mais
                                        // do que esta sessão; as outras contas
                                        // bastam ter uma.
                                        $linhaTerminar = $linhaSessoes !== null
                                            && $linhaSessoes > ($linhaId === (int) $utilizador['id'] ? 1 : 0);
                                        ?>
                                        <tr>
                                            <td class="tabela__cliente">
                                                <?= h($linhaNome) ?>
                                                <?php if ($linhaId === (int) $utilizador['id']): ?>
                                                    <span class="conta-atual">tu</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h($linhaConta['email']) ?></td>
                                            <td>
                                                <span class="cargo<?= $linhaEAdmin ? ' cargo--administrador' : '' ?>">
                                                    <?= h($linhaConta['cargo']) ?>
                                                </span>
                                            </td>
                                            <td class="tabela__data">
                                                <?php if ($linhaSessoes === null || $linhaSessoes === 0): ?>
                                                    <span class="visualmente-oculto">Sem sessões abertas</span>
                                                    <span aria-hidden="true">—</span>
                                                <?php else: ?>
                                                    <span class="visualmente-oculto"><?= (int) $linhaSessoes ?> <?= $linhaSessoes === 1 ? 'sessão aberta' : 'sessões abertas' ?></span>
                                                    <span aria-hidden="true"><?= (int) $linhaSessoes ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="tabela__data"><?= h(dataCurta($linhaConta['criado_em'])) ?></td>
                                            <td class="tabela__acao">
                                                <span class="tabela__acoes">
                                                    <a class="ligacao-acao" href="utilizadores.php?editar=<?= $linhaId ?>#formulario">
                                                        Editar<span class="visualmente-oculto"> a conta de <?= h($linhaNome) ?></span>
                                                    </a>
                                                    <a class="ligacao-acao" href="utilizadores.php?palavra=<?= $linhaId ?>#formulario">
                                                        Repor palavra-passe<span class="visualmente-oculto"> de <?= h($linhaNome) ?></span>
                                                    </a>
                                                    <?php if ($linhaTerminar): ?>
                                                        <a class="ligacao-acao" href="utilizadores.php?sessoes=<?= $linhaId ?>#formulario">
                                                            Terminar sessões<span class="visualmente-oculto"> de <?= h($linhaNome) ?></span>
                                                        </a>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="aviso">
                            <span class="aviso__icone" aria-hidden="true">
                                <svg><use href="#icone-utilizadores"/></svg>
                            </span>
                            <p class="aviso__titulo">Ainda não há contas registadas</p>
                            <p class="aviso__texto">
                                Cria a primeira conta com o botão Nova conta.
                            </p>
                            <a class="botao botao--primario" href="utilizadores.php?novo=1">
                                <svg aria-hidden="true"><use href="#icone-mais"/></svg>
                                <span>Nova conta</span>
                            </a>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

        </main>
    </div>
</div>

<script>
/* =====================================================================
   Menu lateral em ecrãs pequenos.
   Em desktop a sidebar é fixa e o botão não existe (CSS esconde-o).
   ===================================================================== */
(function () {
    "use strict";

    var corpo   = document.body;
    var sidebar = document.getElementById("sidebar");
    var abrir   = document.getElementById("abrir-nav");
    var fechar  = document.getElementById("fechar-nav");
    var cortina = document.getElementById("cortina");
    var ecraGrande = window.matchMedia("(min-width: 1024px)");

    function estaAberto() {
        return corpo.classList.contains("nav-aberto");
    }

    function abrirMenu() {
        corpo.classList.add("nav-aberto");
        sidebar.removeAttribute("inert");
        abrir.setAttribute("aria-expanded", "true");

        var primeiroItem = sidebar.querySelector(".navegacao__ligacao");
        if (primeiroItem) {
            primeiroItem.focus({ preventScroll: true });
        }
    }

    function fecharMenu(devolverFoco) {
        corpo.classList.remove("nav-aberto");
        abrir.setAttribute("aria-expanded", "false");

        if (!ecraGrande.matches) {
            sidebar.setAttribute("inert", "");
        }

        if (devolverFoco) {
            abrir.focus({ preventScroll: true });
        }
    }

    /* Quanto tempo a gaveta leva a sair. Lido do CSS em vez de repetido
       aqui: com movimento reduzido a transição é quase nula, e a espera
       também tem de ser. */
    function duracaoDaSaida() {
        var valores = String(window.getComputedStyle(sidebar).transitionDuration || "0s").split(",");
        var maior = 0;

        valores.forEach(function (valor) {
            var numero = parseFloat(valor) || 0;

            if (valor.indexOf("ms") > -1) {
                numero = numero / 1000;
            }

            maior = Math.max(maior, numero);
        });

        return maior * 1000;
    }

    /* Tocar numa ligação com a gaveta aberta: a gaveta fecha primeiro, com a
       animação que já tem, e só depois a página nova entra. Sem esta espera o
       toque trocava de documento no mesmo fotograma e o menu desaparecia de
       repente — a travessia entre páginas não pinta a gaveta no telemóvel,
       por isso não havia nada para ver a sair. */
    function navegarDepoisDeFechar(destino) {
        /* O destino já está escolhido: a gaveta sai mais depressa do que no
           fecho normal. Sem isto, o toque esperava a travessia inteira da
           gaveta antes de a página nova começar a carregar, e a navegação
           parecia lenta. A classe só vale neste caminho — fechar a gaveta
           sem sair da página mantém a animação de sempre. */
        corpo.classList.add("nav-a-navegar");

        var seguiu = false;

        function seguir() {
            if (seguiu) {
                return;
            }

            seguiu = true;
            sidebar.removeEventListener("transitionend", aoTerminar);
            window.location.href = destino;
        }

        /* A transição do `transform` é a da gaveta a sair; a da cortina é
           mais curta e chegaria primeiro. */
        function aoTerminar(evento) {
            if (evento.target === sidebar && evento.propertyName === "transform") {
                seguir();
            }
        }

        sidebar.addEventListener("transitionend", aoTerminar);
        fecharMenu(false);

        /* Rede de segurança: se o `transitionend` não vier — gaveta já fora do
           ecrã, transição saltada —, a página nova entra mesmo assim. */
        window.setTimeout(seguir, duracaoDaSaida() + 80);
    }

    /* Mantém o estado coerente ao rodar o ecrã ou redimensionar. */
    function sincronizar() {
        if (ecraGrande.matches) {
            corpo.classList.remove("nav-aberto");
            sidebar.removeAttribute("inert");
            abrir.setAttribute("aria-expanded", "false");
        } else if (!estaAberto()) {
            sidebar.setAttribute("inert", "");
        }
    }

    abrir.addEventListener("click", abrirMenu);
    fechar.addEventListener("click", function () { fecharMenu(true); });
    cortina.addEventListener("click", function () { fecharMenu(true); });

    /* A gaveta não desaparece debaixo do dedo: a ligação espera que o menu
       saia. Ctrl/⌘, botão do meio, outra aba e descarregamentos seguem sem
       espera — são do browser, não desta página. */
    sidebar.addEventListener("click", function (evento) {
        var ligacao = evento.target.closest ? evento.target.closest("a[href]") : null;

        if (!ligacao || !estaAberto() || ecraGrande.matches ||
                evento.defaultPrevented || evento.button !== 0 ||
                evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey ||
                (ligacao.target && ligacao.target !== "_self") ||
                ligacao.hasAttribute("download")) {
            return;
        }

        evento.preventDefault();
        navegarDepoisDeFechar(ligacao.href);
    });

    document.addEventListener("keydown", function (evento) {
        if (evento.key === "Escape" && estaAberto()) {
            fecharMenu(true);
        }
    });

    if (typeof ecraGrande.addEventListener === "function") {
        ecraGrande.addEventListener("change", sincronizar);
    } else {
        ecraGrande.addListener(sincronizar);
    }

    sincronizar();
})();
</script>
</body>
</html>
