<?php
declare(strict_types=1);

/**
 * consulta.php — Consulta pública do estado de uma reparação.
 *
 * O cliente introduz o PIN de 6 dígitos entregue na receção do
 * equipamento (é único na tabela pedidos). Se existir, a página mostra o
 * percurso do pedido.
 *
 * Fluxo:
 *   1. POST valida o PIN e procura o pedido.
 *   2. Se encontrar, guarda apenas o ID na sessão e redireciona (303) para
 *      consulta.php#resultado — o PIN não fica no URL nem no HTML.
 *   3. O GET lê o ID da sessão, carrega o pedido e desenha o resultado.
 *
 * Segurança:
 *   • Prepared statements em todas as consultas.
 *   • Mensagem única para PIN errado (não revela se o pedido existe).
 *   • Sem o NIF como segundo fator, o PIN sozinho tem de ser protegido:
 *     limite de tentativas por sessão E por endereço de rede (um atacante
 *     que apague o cookie muda de sessão, mas não de IP).
 *   • O resultado não mostra o nome do cliente — quem tem o PIN reconhece
 *     o pedido pelo equipamento e pelo número. Os dados pessoais ficam de
 *     fora de uma página que só pede 6 algarismos.
 *   • Nenhum dado pessoal é escrito no log nem no URL; os ficheiros do
 *     limite por IP guardam só um resumo do endereço, não o endereço.
 */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-store');
header(
    "Content-Security-Policy: default-src 'self'; base-uri 'none'; "
    . "form-action 'self'; frame-ancestors 'none'; object-src 'none'; "
    . "img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
    . "script-src 'self' 'unsafe-inline'"
);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once __DIR__ . '/../nucleo/conexao.php';

// =====================================================================
//  CONFIGURAÇÃO
// =====================================================================
const DURACAO_CONSULTA   = 1800;  // 30 minutos de resultado em sessão
const MAX_TENTATIVAS     = 8;     // por sessão
const MAX_TENTATIVAS_IP  = 20;    // por endereço de rede, partilhado entre sessões
const JANELA_TENTATIVAS  = 600;   // 10 minutos

/** Ciclo de vida do pedido, na ordem do ENUM da base de dados. */
const PASSOS = [
    ['estado' => 'Recebido',       'etiqueta' => 'Recebido'],
    ['estado' => 'Em Diagnostico', 'etiqueta' => 'Em diagnóstico'],
    ['estado' => 'Em Reparacao',   'etiqueta' => 'Em reparação'],
    ['estado' => 'Pronto',         'etiqueta' => 'Pronto'],
    ['estado' => 'Entregue',       'etiqueta' => 'Entregue'],
];

/**
 * O que acontece a seguir, por fase.
 *
 * Só o que o sistema faz mesmo: nada de prazos, preços ou promessas. É a
 * resposta à pergunta que o cliente traz à consulta quando o estado não
 * muda há uns dias.
 */
const SEGUINTE = [
    'Recebido'       => 'Abrimos o equipamento e testamos. O orçamento é comunicado antes de mexermos em nada.',
    'Em Diagnostico' => 'O orçamento fica definido depois dos testes. Quando estiver, decides aqui mesmo.',
    'Em Reparacao'   => 'No fim dos testes, o pedido passa a pronto a levantar.',
    'Pronto'         => 'Vens buscar o equipamento ao balcão. O pedido fecha no momento da entrega.',
    'Entregue'       => 'Pedido fechado.',
];


// =====================================================================
//  FUNÇÕES AUXILIARES
// =====================================================================

/** Escapa texto para HTML. */
function h(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formata um valor monetário para leitura. */
function euros(float $valor): string
{
    return number_format($valor, 2, ',', ' ') . ' €';
}

/** Data curta em português (dd/mm/aaaa); vazia se não for válida. */
function dataCurta(?string $data): string
{
    if ($data === null || $data === '') {
        return '';
    }

    $marca = strtotime($data);

    return $marca === false ? '' : date('d/m/Y', $marca);
}

/** Lê um campo do POST, sem espaços nas pontas. */
function valorPost(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

/** Mantém apenas algarismos (o PIN é numérico). */
function apenasDigitos(string $valor): string
{
    return preg_replace('/[^0-9]/', '', $valor) ?? '';
}

/** Endereço de rede do pedido, ou '' quando não é um IP válido. */
function enderecoIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

/**
 * Ficheiro do limite de tentativas de um endereço de rede.
 *
 * O nome é um resumo (sha256) do IP: a pasta temporária não fica com uma
 * lista de endereços de visitantes. Devolve null quando a pasta não pode
 * ser criada ou escrita — nesse caso só o limite por sessão se aplica.
 */
function ficheiroTentativasIp(string $ip): ?string
{
    $pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixit-consulta';

    if (!is_dir($pasta) && !@mkdir($pasta, 0700, true) && !is_dir($pasta)) {
        return null;
    }

    return $pasta . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json';
}

/**
 * Tentativas falhadas deste endereço dentro da janela atual.
 *
 * @return array{n: int, inicio: int}
 */
function lerTentativasIp(string $ip): array
{
    $agora  = time();
    $vazio  = ['n' => 0, 'inicio' => $agora];
    $ficheiro = $ip === '' ? null : ficheiroTentativasIp($ip);

    if ($ficheiro === null) {
        return $vazio;
    }

    $bruto = @file_get_contents($ficheiro);
    $dados = is_string($bruto) ? json_decode($bruto, true) : null;

    if (!is_array($dados) || !isset($dados['n'], $dados['inicio'])) {
        return $vazio;
    }

    $inicio = (int) $dados['inicio'];

    if ($agora - $inicio > JANELA_TENTATIVAS) {
        return $vazio;
    }

    return ['n' => (int) $dados['n'], 'inicio' => $inicio];
}

/** Grava uma tentativa falhada do endereço, sem nunca bloquear a página. */
function gravarTentativasIp(string $ip, int $total, int $inicio): void
{
    $ficheiro = $ip === '' ? null : ficheiroTentativasIp($ip);

    if ($ficheiro === null) {
        return;
    }

    @file_put_contents(
        $ficheiro,
        json_encode(['n' => $total, 'inicio' => $inicio]),
        LOCK_EX
    );
}

/** Depois de um PIN certo, o endereço deixa de estar em falta. */
function limparTentativasIp(string $ip): void
{
    $ficheiro = $ip === '' ? null : ficheiroTentativasIp($ip);

    if ($ficheiro !== null) {
        @unlink($ficheiro);
    }
}

/** Aviso de bloqueio, com os minutos que faltam para a janela abrir. */
function mensagemBloqueio(int $segundos): string
{
    $minutos = max(1, (int) ceil($segundos / 60));

    return 'Demasiadas tentativas. Por segurança, tenta novamente daqui a '
        . $minutos . ' minuto' . ($minutos === 1 ? '' : 's') . '.';
}

/** Carrega um pedido completo, já com o equipamento (sem dados do cliente). */
function carregarPedido(PDO $pdo, int $id): ?array
{
    $consulta = $pdo->prepare(
        'SELECT p.id, p.estado, p.descricao_avaria, p.orcamento,
                p.data_entrada, p.data_conclusao,
                e.tipo, e.marca_modelo, e.numero_serie
           FROM pedidos p
     INNER JOIN equipamentos e ON e.id = p.equipamento_id
          WHERE p.id = ?
          LIMIT 1'
    );
    $consulta->execute([$id]);

    $linha = $consulta->fetch();

    return $linha ?: null;
}

/**
 * Datas de entrada em cada fase, lidas do histórico.
 *
 * Uma reparação reaberta pode passar duas vezes pelo mesmo estado; o
 * stepper mostra a passagem mais recente (MAX), a que corresponde ao
 * ciclo atual do pedido. Se a tabela ainda não existir na base de
 * dados, o percurso aparece sem datas em vez de quebrar a consulta.
 *
 * @return array<string, string> estado => data/hora
 */
function carregarDatasEstados(PDO $pdo, int $idPedido): array
{
    try {
        $consulta = $pdo->prepare(
            'SELECT estado, MAX(registado_em) AS entrada
               FROM historico_pedidos
              WHERE pedido_id = ?
           GROUP BY estado'
        );
        $consulta->execute([$idPedido]);
    } catch (PDOException $excecao) {
        error_log('FixIt: histórico de estados indisponível — ' . $excecao->getMessage());
        return [];
    }

    $datas = [];

    foreach ($consulta->fetchAll() as $linha) {
        if (is_string($linha['entrada']) && $linha['entrada'] !== '') {
            $datas[$linha['estado']] = $linha['entrada'];
        }
    }

    return $datas;
}

/** Token CSRF da decisão sobre o orçamento (muda a cada decisão). */
function tokenDecisao(): string
{
    if (empty($_SESSION['csrf_decisao'])) {
        $_SESSION['csrf_decisao'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_decisao'];
}

/**
 * Última decisão do cliente sobre o pedido, se existir.
 *
 * Best-effort, como o histórico: se a migração ainda não foi importada, a
 * consulta aparece sem a linha da decisão em vez de falhar.
 *
 * @return array{decisao: string, valor: float, registado_em: string}|null
 */
function carregarDecisao(PDO $pdo, int $idPedido): ?array
{
    try {
        $consulta = $pdo->prepare(
            'SELECT decisao, valor, registado_em
               FROM decisoes_orcamento
              WHERE pedido_id = ?
           ORDER BY registado_em DESC, id DESC
              LIMIT 1'
        );
        $consulta->execute([$idPedido]);
    } catch (PDOException $excecao) {
        error_log('FixIt: decisões do orçamento indisponíveis — ' . $excecao->getMessage());
        return null;
    }

    $linha = $consulta->fetch();

    return $linha ?: null;
}

/**
 * Regista a decisão do cliente e move o pedido.
 *
 * Aceitar leva o pedido a «Em Reparação»; recusar leva-o a «Pronto», onde
 * fica a aguardar levantamento sem ter sido mexido.
 *
 * O estado é relido dentro da transacção (FOR UPDATE): se o balcão mexeu
 * no pedido entretanto, ou se o cliente carregou duas vezes, a segunda
 * tentativa não decide nada — devolve o motivo e não escreve.
 *
 * @return array{ok: bool, mensagem: string}
 */
function registarDecisao(PDO $pdo, int $idPedido, string $decisao): array
{
    $aceitou    = $decisao === 'aceitar';
    $novoEstado = $aceitou ? 'Em Reparacao' : 'Pronto';

    try {
        $pdo->beginTransaction();

        $consulta = $pdo->prepare(
            'SELECT estado, orcamento FROM pedidos WHERE id = ? FOR UPDATE'
        );
        $consulta->execute([$idPedido]);
        $linha = $consulta->fetch();

        if ($linha === false) {
            $pdo->rollBack();

            return ['ok' => false, 'mensagem' => 'Este pedido já não existe. Fala com a oficina.'];
        }

        if ($linha['estado'] !== 'Em Diagnostico') {
            $pdo->rollBack();

            return [
                'ok' => false,
                'mensagem' => 'Este pedido já não está à espera de decisão. '
                    . 'Se precisares de o mudar, fala com a oficina.',
            ];
        }

        if ($linha['orcamento'] === null) {
            $pdo->rollBack();

            return [
                'ok' => false,
                'mensagem' => 'O orçamento ainda não está definido para este pedido. Tenta mais tarde.',
            ];
        }

        $registar = $pdo->prepare(
            'INSERT INTO decisoes_orcamento (pedido_id, decisao, valor) VALUES (?, ?, ?)'
        );
        $registar->execute([$idPedido, $aceitou ? 'aceite' : 'recusado', $linha['orcamento']]);

        // O trigger de historico_pedidos regista a passagem de estado.
        $mover = $pdo->prepare('UPDATE pedidos SET estado = ? WHERE id = ?');
        $mover->execute([$novoEstado, $idPedido]);

        $pdo->commit();
    } catch (PDOException $excecao) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[FixIt][Consulta][Decisao] ' . $excecao->getMessage());

        return [
            'ok' => false,
            'mensagem' => 'Não foi possível registar a decisão agora. '
                . 'Tenta novamente daqui a pouco ou fala com a oficina.',
        ];
    }

    return [
        'ok' => true,
        'mensagem' => $aceitou
            ? 'Aceitaste o orçamento. A reparação vai começar — o estado deste pedido acompanha o resto do percurso.'
            : 'Recusaste o orçamento. O equipamento fica pronto a levantar, sem ser mexido. Passa na oficina com o talão.',
    ];
}


// =====================================================================
//  LIGAÇÃO À BASE DE DADOS
// =====================================================================
$pdo = null;

try {
    $pdo = obterConexao();
} catch (RuntimeException $excecao) {
    // O detalhe técnico fica no error_log feito por conexao.php.
    $pdo = null;
}

$indisponivel = !($pdo instanceof PDO);


// =====================================================================
//  TERMINAR CONSULTA (?limpar=1)
// =====================================================================
if (isset($_GET['limpar'])) {
    unset($_SESSION['consulta']);
    header('Location: consulta.php', true, 303);
    exit;
}


// =====================================================================
//  ESTADO DA PÁGINA
// =====================================================================
$erros        = [];
$erroGeral    = '';
$pedido       = null;
$datasEstados = [];
$decisao      = null;
$flash        = '';


// =====================================================================
//  PROCESSAMENTO DO FORMULÁRIO
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['decisao'])) {
    // --- Decisão sobre o orçamento -----------------------------------
    // Só depois de o PIN ter aberto o pedido nesta sessão: o ID vem do
    // servidor, nunca do formulário.
    $decisaoPedida = valorPost('decisao');
    $token         = valorPost('csrf');
    $guardado      = $_SESSION['consulta'] ?? null;
    $idSessao      = 0;

    if (is_array($guardado) && isset($guardado['id'], $guardado['expira'])
        && (int) $guardado['expira'] >= time()) {
        $idSessao = (int) $guardado['id'];
    }

    if (!in_array($decisaoPedida, ['aceitar', 'recusar'], true)) {
        $erroGeral = 'Não foi possível registar a decisão. Tenta novamente.';
    } elseif ($indisponivel || $idSessao === 0) {
        $erroGeral = 'A consulta expirou. Introduz o PIN outra vez para decidires.';
    } elseif (empty($_SESSION['csrf_decisao']) || !hash_equals($_SESSION['csrf_decisao'], $token)) {
        $erroGeral = 'A sessão expirou. Recarrega a página e decide outra vez.';
    } else {
        $resultado = registarDecisao($pdo, $idSessao, $decisaoPedida);

        if ($resultado['ok']) {
            unset($_SESSION['csrf_decisao']);
            $_SESSION['consulta_flash'] = $resultado['mensagem'];

            header('Location: consulta.php#resultado', true, 303);
            exit;
        }

        $erroGeral = $resultado['mensagem'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Uma nova tentativa substitui sempre o resultado anterior.
    unset($_SESSION['consulta']);

    $pin = apenasDigitos(valorPost('pin'));

    if ($pin === '') {
        $erros['pin'] = 'Indica o PIN de consulta.';
    } elseif (strlen($pin) !== 6) {
        $erros['pin'] = 'O PIN tem 6 dígitos.';
    }

    // --- Limite de tentativas: por sessão e por endereço de rede ---
    $tentativas = $_SESSION['consulta_tentativas'] ?? ['n' => 0, 'inicio' => time()];

    if (!is_array($tentativas) || !isset($tentativas['n'], $tentativas['inicio'])) {
        $tentativas = ['n' => 0, 'inicio' => time()];
    }

    if (time() - (int) $tentativas['inicio'] > JANELA_TENTATIVAS) {
        $tentativas = ['n' => 0, 'inicio' => time()];
    }

    $ip           = enderecoIp();
    $tentativasIp = lerTentativasIp($ip);

    // Espera o maior dos dois bloqueios ativos (0 = nenhum).
    $esperaSessao = (int) $tentativas['n'] >= MAX_TENTATIVAS
        ? JANELA_TENTATIVAS - (time() - (int) $tentativas['inicio'])
        : 0;
    $esperaIp = $tentativasIp['n'] >= MAX_TENTATIVAS_IP
        ? JANELA_TENTATIVAS - (time() - $tentativasIp['inicio'])
        : 0;
    $espera = max(0, $esperaSessao, $esperaIp);

    if ($espera > 0) {
        $erroGeral = mensagemBloqueio($espera);
    } elseif (!$indisponivel && $erros === []) {
        // O PIN é único na tabela: identifica o pedido sozinho.
        $consulta = $pdo->prepare(
            'SELECT id FROM pedidos WHERE pin_consulta = ? LIMIT 1'
        );
        $consulta->execute([$pin]);
        $idEncontrado = $consulta->fetchColumn();

        if ($idEncontrado === false) {
            $tentativas['n'] = (int) $tentativas['n'] + 1;
            $_SESSION['consulta_tentativas'] = $tentativas;

            gravarTentativasIp($ip, $tentativasIp['n'] + 1, $tentativasIp['inicio']);

            // Mensagem única: não confirma se o PIN existe.
            $erroGeral = 'Não encontrámos nenhum pedido com esse PIN. '
                . 'Confirma os 6 dígitos do talão de entrada e tenta novamente.';
        } else {
            unset($_SESSION['consulta_tentativas']);
            limparTentativasIp($ip);

            // Registo da consulta para o contador da ficha do pedido.
            // Best-effort: se a tabela ainda não existir (migração por
            // importar) ou a escrita falhar, o cliente vê o pedido na
            // mesma — a falha fica só no log.
            try {
                $registarConsulta = $pdo->prepare(
                    'INSERT INTO consultas_publicas (pedido_id) VALUES (?)'
                );
                $registarConsulta->execute([(int) $idEncontrado]);
            } catch (PDOException $erroRegisto) {
                error_log('[FixIt][Consulta][Registo] ' . $erroRegisto->getMessage());
            }

            $_SESSION['consulta'] = [
                'id'     => (int) $idEncontrado,
                'expira' => time() + DURACAO_CONSULTA,
            ];

            header('Location: consulta.php#resultado', true, 303);
            exit;
        }
    }
}


// =====================================================================
//  RESULTADO GUARDADO NA SESSÃO (GET)
// =====================================================================
if (!$indisponivel) {
    $guardada = $_SESSION['consulta'] ?? null;

    if (is_array($guardada) && isset($guardada['id'], $guardada['expira'])) {
        if ((int) $guardada['expira'] < time()) {
            unset($_SESSION['consulta']);
        } else {
            $pedido = carregarPedido($pdo, (int) $guardada['id']);

            if ($pedido === null) {
                // O pedido foi eliminado entretanto.
                unset($_SESSION['consulta']);
            } else {
                $datasEstados = carregarDatasEstados($pdo, (int) $pedido['id']);
                $decisao      = carregarDecisao($pdo, (int) $pedido['id']);
            }
        }
    } elseif ($guardada !== null) {
        unset($_SESSION['consulta']);
    }
}

// Confirmação de uma decisão tomada agora (sobrevive ao redirect).
if (isset($_SESSION['consulta_flash'])) {
    $flash = (string) $_SESSION['consulta_flash'];
    unset($_SESSION['consulta_flash']);
}

$aviso = $erroGeral;

if ($indisponivel) {
    $aviso = 'A consulta está temporariamente indisponível. Tenta novamente daqui a pouco.';
}

// --- Posição do pedido no percurso ---
$indiceAtual = 0;

if ($pedido !== null) {
    foreach (PASSOS as $indice => $passo) {
        if ($passo['estado'] === $pedido['estado']) {
            $indiceAtual = $indice;
            break;
        }
    }
}

$etiquetaAtual = PASSOS[$indiceAtual]['etiqueta'];
$progresso     = $indiceAtual * 25; // percentagem da linha já percorrida

// «A seguir» segue o estado atual, excepto quando houve uma recusa: nesse
// caso o próximo passo é levantar o equipamento, não avançar no percurso.
$seguinte = $pedido !== null ? (SEGUINTE[$pedido['estado']] ?? '') : '';

if ($pedido !== null && $decisao !== null && $decisao['decisao'] === 'recusado') {
    $seguinte = 'Recusaste o orçamento: o equipamento ficou no estado em que o deixaste, '
        . 'à espera de que o levantes no balcão.';
} elseif ($pedido !== null && $decisao === null
    && $pedido['estado'] === 'Em Diagnostico' && $pedido['orcamento'] !== null) {
    $seguinte = 'O orçamento está definido. Aceita ou recusa aqui em baixo.';
}
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consulta de reparação — FixIt</title>
    <meta name="description" content="Consulta o estado da reparação do teu equipamento na oficina FixIt com o PIN de 6 dígitos do talão de entrada.">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#0C2038">

    <link rel="stylesheet" href="../assets/css/painel.css">
    <link rel="stylesheet" href="../assets/css/formulario.css">
    <link rel="stylesheet" href="../assets/css/publico.css">
</head>
<body class="pagina-publica">

<a class="saltar-conteudo" href="#conteudo">Saltar para o conteúdo</a>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <g id="icone-visto" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="m5 12.5 4.5 4.5L19 7"/>
        </g>
        <g id="icone-alerta" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 4.5 21 20H3L12 4.5Z"/>
            <path d="M12 10v4"/>
            <path d="M12 17h.01"/>
        </g>
    </defs>
</svg>

<header class="topo-publico">
    <div class="topo-publico__interior">
        <a class="marca marca--clara" href="consulta.php">
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

    </div>
</header>

<main class="publico" id="conteudo" tabindex="-1">

    <!-- =============================================================
         CONSULTA
         ============================================================= -->
    <section class="hero" aria-labelledby="titulo-consulta">
        <h1 id="titulo-consulta">Consulta o estado da tua reparação</h1>
        <p class="hero__texto">
            Introduz o PIN de 6 dígitos que recebeste quando deixaste o
            equipamento na oficina.
        </p>

        <form class="cartao" id="form-consulta" method="post" action="consulta.php" novalidate>
            <?php if ($aviso !== ''): ?>
                <div class="alerta alerta--erro" role="alert">
                    <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                    <div>
                        <p class="alerta__titulo">
                            <?= $indisponivel ? 'Serviço indisponível' : 'Não foi possível consultar' ?>
                        </p>
                        <p class="alerta__texto"><?= h($aviso) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="cartao__campos cartao__campos--unico">
                <div class="campo<?= isset($erros['pin']) ? ' campo--invalido' : '' ?>">
                    <label class="campo__rotulo" for="pin">PIN de consulta</label>
                    <input class="entrada entrada--pin" type="text" id="pin" name="pin"
                           inputmode="numeric" autocomplete="one-time-code" maxlength="10"
                           pattern="[0-9]{6}" placeholder="000000"
                           aria-describedby="ajuda-pin erro-pin"
                           <?= isset($erros['pin']) ? 'aria-invalid="true"' : '' ?>
                           <?= $indisponivel ? 'disabled' : '' ?> required>
                    <p class="campo__ajuda" id="ajuda-pin">
                        Está no talão de entrada que recebeste na oficina.
                    </p>
                    <p class="campo__erro" id="erro-pin"
                       <?= isset($erros['pin']) ? '' : 'hidden' ?>><?= h($erros['pin'] ?? '') ?></p>
                </div>
            </div>

            <button class="botao botao--primario cartao__botao" type="submit"
                    <?= $indisponivel ? 'disabled' : '' ?>>
                Consultar estado
            </button>

            <p class="cartao__nota">PIN com 6 dígitos, sem letras</p>
        </form>

        <p class="hero__nota">
            O PIN foi entregue com o talão de entrada. Se o perderes, passa pela
            oficina com esse talão.
        </p>
    </section>

    <?php if ($pedido !== null): ?>
        <!-- =========================================================
             RESULTADO
             ========================================================= -->
        <section class="resultado" id="resultado" aria-labelledby="estado-atual">
            <p class="resultado__impresso" aria-hidden="true">
                FixIt · Assistência técnica — Pedido #<?= (int) $pedido['id'] ?>
            </p>

            <?php if ($flash !== ''): ?>
                <div class="alerta alerta--sucesso" role="status">
                    <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                    <div>
                        <p class="alerta__titulo">Decisão registada</p>
                        <p class="alerta__texto"><?= h($flash) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <h2 class="resultado__titulo" id="estado-atual"><?= h($etiquetaAtual) ?></h2>
            <p class="resultado__equipamento">
                <?= h($pedido['marca_modelo']) ?> · <?= h($pedido['tipo']) ?>
            </p>

            <ol class="progresso" style="--progresso: <?= (int) $progresso ?>%"
                aria-label="Percurso do pedido">
                <?php foreach (PASSOS as $indice => $passo): ?>
                    <?php
                    $classe = $indice < $indiceAtual
                        ? 'is-concluido'
                        : ($indice === $indiceAtual ? 'is-atual' : '');

                    // Só fases já alcançadas mostram data; um histórico
                    // inconsistente nunca adiciona uma data a uma fase vazia.
                    $dataBruta = $indice <= $indiceAtual
                        ? ($datasEstados[$passo['estado']] ?? null)
                        : null;
                    $dataFase  = $dataBruta !== null ? dataCurta($dataBruta) : '';
                    ?>
                    <li class="progresso__passo <?= $classe ?>"
                        <?= $indice === $indiceAtual ? 'aria-current="step"' : '' ?>>
                        <span class="progresso__marca" aria-hidden="true">
                            <?php if ($indice < $indiceAtual): ?>
                                <svg><use href="#icone-visto"/></svg>
                            <?php endif; ?>
                        </span>
                        <span class="progresso__texto">
                            <span class="progresso__nome"><?= h($passo['etiqueta']) ?></span>
                            <?php if ($dataFase !== ''): ?>
                                <time class="progresso__data"
                                      datetime="<?= h(date('Y-m-d', strtotime($dataBruta))) ?>"><span class="visualmente-oculto">Entrou a </span><?= h($dataFase) ?></time>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php if ($seguinte !== ''): ?>
                <p class="resultado__seguinte">
                    <span class="resultado__seguinte-rotulo">A seguir</span>
                    <?= h($seguinte) ?>
                </p>
            <?php endif; ?>

            <dl class="detalhes">
                <div class="detalhes__item detalhes__item--largo">
                    <dt>Avaria descrita</dt>
                    <dd class="detalhes__avaria"><?= h($pedido['descricao_avaria']) ?></dd>
                </div>

                <div class="detalhes__item">
                    <dt>Pedido</dt>
                    <dd>#<?= (int) $pedido['id'] ?></dd>
                </div>

                <div class="detalhes__item">
                    <dt>Número de série</dt>
                    <dd><?= h($pedido['numero_serie'] !== null && $pedido['numero_serie'] !== ''
                        ? $pedido['numero_serie']
                        : 'Não indicado') ?></dd>
                </div>

                <div class="detalhes__item">
                    <dt>Orçamento</dt>
                    <dd><?= $pedido['orcamento'] !== null
                        ? h(euros((float) $pedido['orcamento']))
                        : 'A definir após diagnóstico' ?></dd>
                </div>

                <div class="detalhes__item">
                    <dt>Entrada</dt>
                    <dd><?= h(date('d/m/Y', strtotime((string) $pedido['data_entrada']))) ?></dd>
                </div>

                <div class="detalhes__item">
                    <dt>Conclusão</dt>
                    <dd><?= $pedido['data_conclusao'] !== null
                        ? h(date('d/m/Y', strtotime((string) $pedido['data_conclusao'])))
                        : 'Ainda em curso' ?></dd>
                </div>
                <?php if ($decisao !== null): ?>
                    <?php $dataDecisao = dataCurta((string) $decisao['registado_em']); ?>
                    <div class="detalhes__item">
                        <dt>Decisão do cliente</dt>
                        <dd>
                            <?= $decisao['decisao'] === 'aceite' ? 'Aceite' : 'Recusada' ?><?php
                            if ($dataDecisao !== '') {
                                echo ' em ' . h($dataDecisao);
                            }
                            ?>
                        </dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if ($pedido['estado'] === 'Em Diagnostico'
                && $pedido['orcamento'] !== null
                && $decisao === null): ?>
                <section class="decisao" aria-labelledby="titulo-decisao">
                    <h3 class="decisao__titulo" id="titulo-decisao">O orçamento está à tua espera</h3>
                    <p class="decisao__texto">
                        São <?= h(euros((float) $pedido['orcamento'])) ?>. A reparação só
                        começa depois de aceitares. Se recusares, o equipamento fica
                        pronto a levantar e não é mexido.
                    </p>

                    <form class="decisao__acoes" method="post" action="consulta.php">
                        <input type="hidden" name="csrf" value="<?= h(tokenDecisao()) ?>">

                        <button class="botao botao--primario" type="submit"
                                name="decisao" value="aceitar">Aceitar e reparar</button>
                        <button class="botao" type="submit"
                                name="decisao" value="recusar">Recusar e levantar</button>
                    </form>
                </section>
            <?php endif; ?>

            <div class="resultado__acoes">
                <p class="resultado__nota">
                    Por segurança, esta consulta não mostra o nome do cliente.
                </p>
                <button class="ligacao-discreta resultado__imprimir" type="button"
                        id="imprimir-estado">Imprimir estado</button>
                <a class="ligacao-discreta" href="consulta.php?limpar=1">Terminar consulta</a>
            </div>
        </section>
    <?php endif; ?>

</main>

<script>
/* =====================================================================
   consulta.php — só validação de formato no browser.
   O servidor volta a validar tudo; isto serve para dar resposta imediata.
   ===================================================================== */
(function () {
    "use strict";

    var formulario = document.getElementById("form-consulta");

    if (!formulario) {
        return;
    }

    var pin = document.getElementById("pin");

    function marcar(campo, mensagem) {
        var grupo = campo.closest(".campo");
        var erro = document.getElementById("erro-" + campo.id);

        if (grupo) {
            grupo.classList.add("campo--invalido");
        }

        campo.setAttribute("aria-invalid", "true");

        if (erro) {
            erro.textContent = mensagem;
            erro.hidden = false;
        }
    }

    function limpar(campo) {
        var grupo = campo.closest(".campo");
        var erro = document.getElementById("erro-" + campo.id);

        if (grupo) {
            grupo.classList.remove("campo--invalido");
        }

        campo.removeAttribute("aria-invalid");

        if (erro) {
            erro.textContent = "";
            erro.hidden = true;
        }
    }

    // O maxlength do HTML aceita separadores ("123 456"); aqui ficam só
    // os algarismos, até ao limite real do PIN.
    pin.addEventListener("input", function () {
        var limpo = pin.value.replace(/\D+/g, "").slice(0, 6);

        if (pin.value !== limpo) {
            pin.value = limpo;
        }

        limpar(pin);
    });

    formulario.addEventListener("submit", function (evento) {
        if (pin.value.length !== 6) {
            evento.preventDefault();
            marcar(pin, "O PIN tem 6 dígitos.");
            pin.focus();
        }
    });
})();

/* Imprimir o estado: só o diálogo do browser. Nada é gerado no servidor e
   a folha de impressão esconde a interface (ver publico.css, §08). */
(function () {
    "use strict";

    var botao = document.getElementById("imprimir-estado");

    if (!botao) {
        return;
    }

    botao.addEventListener("click", function () {
        window.print();
    });
})();
</script>
</body>
</html>
