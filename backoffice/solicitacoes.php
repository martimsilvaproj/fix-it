<?php
declare(strict_types=1);

/**
 * solicitacoes.php — caixa de entrada dos pedidos de orçamento do site.
 *
 * O formulário público (frontoffice/orcamento.php) grava em `solicitacoes`.
 * Esta página é o outro lado: ver o que chegou, contactar, arquivar e — a
 * decisão que interessa — abrir o pedido de reparação.
 *
 * Estrutura:
 *   1. Sessão, CSRF e ações (marcar estado, converter).
 *   2. Leitura: filtros, contagens, lista e ficha pedida.
 *   3. HTML.
 *
 * Um pedido de orçamento não traz o que a tabela `clientes` exige: o site
 * pergunta nome e contacto, não NIF nem telefone confirmado. Por isso a
 * conversão tem formulário próprio, preenchido com o que o cliente
 * escreveu e completado no balcão. Nada é inventado: campo em branco é
 * campo que a oficina preenche.
 *
 * Sem direcção visual própria: usa os componentes e os tokens do painel.
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/texto.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';
require_once __DIR__ . '/../nucleo/layout-painel.php';

Sessao::iniciar();

// Cabeçalhos de segurança: o layout-painel.php também os envia (é o único
// ficheiro que todas as páginas carregam), mas esta página pode falhar
// antes de o incluir — ficam aqui, e repetir o header não faz mal.
Sessao::cabecalhosSeguranca();

/** @var array{id: int, nome: string, email: string, cargo: string} $utilizador */
$utilizador       = Sessao::exigir();
$solicitacoesNovas = solicitacoesNovas();

/** Escapa texto para HTML. */
function h(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Data e hora curtas, no formato do painel. */
function dataHoraCurta(?string $dataHora): string
{
    if ($dataHora === null || $dataHora === '') {
        return '—';
    }

    $momento = strtotime($dataHora);

    return $momento === false ? '—' : date('d/m/Y H:i', $momento);
}

/** Data curta sem hora, para a linha da conversão. */
function dataCurta(?string $dataHora): string
{
    if ($dataHora === null || $dataHora === '') {
        return '—';
    }

    $momento = strtotime($dataHora);

    return $momento === false ? '—' : date('d/m/Y', $momento);
}

/** Valor de um campo do formulário de conversão, já como texto. */
function valorCampo(array $origem, string $chave): string
{
    $valor = $origem[$chave] ?? '';

    return is_string($valor) ? trim($valor) : '';
}

/**
 * Um campo de texto do formulário de conversão.
 *
 * @param array<string, string> $erros
 */
function campoTexto(
    string $nome,
    string $rotulo,
    string $valor,
    array $erros,
    string $tipo = 'text',
    bool $obrigatorio = false,
    int $maximo = 120
): void {
    $id     = 'conv-' . $nome;
    $temErro = isset($erros[$nome]);
    ?>
    <div class="campo<?= $temErro ? ' campo--invalido' : '' ?>">
        <label class="campo__rotulo" for="<?= h($id) ?>"><?= h($rotulo) ?></label>
        <input class="entrada"
               id="<?= h($id) ?>"
               name="<?= h($nome) ?>"
               type="<?= h($tipo) ?>"
               value="<?= h($valor) ?>"
               maxlength="<?= (int) $maximo ?>"
               <?= $obrigatorio ? 'required' : '' ?>
               <?= $temErro ? 'aria-invalid="true"' : '' ?>
               <?= $temErro ? 'aria-describedby="erro-' . h($nome) . '"' : '' ?>>
        <p class="campo__erro" id="erro-<?= h($nome) ?>"
           <?= $temErro ? '' : 'hidden' ?>><?= h($erros[$nome] ?? '') ?></p>
    </div>
    <?php
}

// =====================================================================
//  1. AÇÕES
// =====================================================================
$token = Sessao::csrf('csrf_solicitacoes');

$erro       = '';
$sucesso    = '';
$errosCampos = [];
$idEmFoco   = 0;      // ficha a abrir depois de um erro de conversão
$valores    = [];     // o que foi submetido, para não obrigar a reescrever

$flash = $_SESSION['solicitacoes_flash'] ?? null;
unset($_SESSION['solicitacoes_flash']);

if (is_array($flash) && isset($flash['texto'])) {
    if (($flash['tipo'] ?? '') === 'erro') {
        $erro = (string) $flash['texto'];
    } else {
        $sucesso = (string) $flash['texto'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enviado = $_POST['csrf'] ?? null;
    $acao    = $_POST['acao'] ?? null;
    $id      = $_POST['id'] ?? null;

    if (!Sessao::csrfValido(is_string($enviado) ? $enviado : null, 'csrf_solicitacoes')) {
        $erro = 'A sessão expirou. Recarrega a página e tenta outra vez.';
    } elseif (!is_string($id) || !ctype_digit($id)) {
        $erro = 'Pedido inválido.';
    } elseif ($acao === 'estado') {
        $novoEstado = $_POST['estado'] ?? null;

        if (!is_string($novoEstado) || !in_array($novoEstado, SOLICITACOES_ESTADOS_MANUAIS, true)) {
            $erro = 'Estado inválido. «Convertida» só se aplica ao abrir o pedido.';
        } else {
            try {
                $pdo       = obterConexao();
                $atualizar = $pdo->prepare(
                    'UPDATE solicitacoes SET estado = ? WHERE id = ? AND pedido_id IS NULL'
                );
                $atualizar->execute([$novoEstado, (int) $id]);

                if ($atualizar->rowCount() === 0) {
                    $erro = 'Este pedido de orçamento já foi convertido (ou já não existe); '
                        . 'o estado não pode ser mudado à mão.';
                } else {
                    $_SESSION['solicitacoes_flash'] = [
                        'tipo'  => 'sucesso',
                        'texto' => 'Pedido de orçamento #' . (int) $id
                            . ' marcado como «' . $novoEstado . '».',
                    ];
                    header('Location: solicitacoes.php?ver=' . (int) $id, true, 303);
                    exit;
                }
            } catch (Throwable $excecao) {
                error_log('[FixIt][Solicitacoes][Estado] ' . $excecao->getMessage());
                $erro = 'Não foi possível guardar. Confirma o MySQL e a migração das solicitações.';
            }
        }
    } elseif ($acao === 'converter') {
        $valores  = $_POST;
        $idEmFoco = (int) $id;

        try {
            $pdo      = obterConexao();
            $resultado = converterSolicitacaoEmPedido(
                $pdo,
                (int) $id,
                (int) $utilizador['id'],
                [
                    'modo_cliente'   => (int) ($_POST['cliente_id'] ?? 0) > 0 ? 'existente' : 'novo',
                    'cliente_id'     => $_POST['cliente_id'] ?? 0,
                    'nome'           => valorCampo($_POST, 'nome'),
                    'email'          => valorCampo($_POST, 'email'),
                    'telefone'       => valorCampo($_POST, 'telefone'),
                    'nif'            => valorCampo($_POST, 'nif'),
                    'tipo'           => valorCampo($_POST, 'tipo'),
                    'marca_modelo'   => valorCampo($_POST, 'marca_modelo'),
                    'numero_serie'   => valorCampo($_POST, 'numero_serie'),
                    'descricao'      => valorCampo($_POST, 'descricao'),
                    'orcamento'      => valorCampo($_POST, 'orcamento'),
                ]
            );

            $_SESSION['solicitacoes_flash'] = [
                'tipo'  => 'sucesso',
                'texto' => 'Pedido de orçamento #' . (int) $id . ' deu origem ao pedido #'
                    . $resultado['pedido_id']
                    . ($resultado['cliente_criado'] ? ', com um cliente novo.' : ', no cliente escolhido.'),
            ];

            // Segue para a ficha do pedido: é lá que a oficina continua
            // (técnico, diagnóstico, fotografia).
            header('Location: pedido.php?id=' . $resultado['pedido_id'], true, 303);
            exit;
        } catch (SolicitacaoInvalida $excecao) {
            $errosCampos = $excecao->erros;
            $erro        = 'Falta corrigir os campos assinalados. Nada foi guardado.';
        } catch (Throwable $excecao) {
            error_log('[FixIt][Solicitacoes][Converter] ' . $excecao->getMessage());
            $erro = $excecao instanceof RuntimeException
                ? $excecao->getMessage()
                : 'Não foi possível abrir o pedido. Nada foi guardado. Tenta outra vez.';
        }
    } else {
        $erro = 'Ação desconhecida.';
    }
}

// =====================================================================
//  2. LEITURA
// =====================================================================
$pdo       = null;
$erroDados = '';

try {
    $pdo = obterConexao();
} catch (Throwable $excecao) {
    error_log('[FixIt][Solicitacoes][Ligação] ' . $excecao->getMessage());
    $erroDados = 'Não foi possível ligar à base de dados. Confirma o MySQL e o ficheiro '
        . 'nucleo/conexao.php.';
}

$pesquisa     = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$estadoFiltro = is_string($_GET['estado'] ?? null) ? $_GET['estado'] : '';

if (!in_array($estadoFiltro, SOLICITACOES_ESTADOS, true)) {
    $estadoFiltro = '';
}

$idFicha = 0;

if (isset($_GET['ver']) && is_string($_GET['ver']) && ctype_digit($_GET['ver'])) {
    $idFicha = (int) $_GET['ver'];
}

if ($idEmFoco > 0) {
    $idFicha = $idEmFoco;
}

$solicitacoes = [];
$contagens    = array_fill_keys(SOLICITACOES_ESTADOS, 0);
$total        = 0;
$ficha        = null;
$clientes     = [];

if ($pdo instanceof PDO && $erroDados === '') {
    try {
        $consulta = $pdo->query('SELECT estado, COUNT(*) AS total FROM solicitacoes GROUP BY estado');

        foreach ($consulta->fetchAll() as $linha) {
            $estado = (string) $linha['estado'];

            if (isset($contagens[$estado])) {
                $contagens[$estado] = (int) $linha['total'];
            }

            $total += (int) $linha['total'];
        }

        // Filtros combinados: texto em qualquer campo útil e estado exacto.
        $condicoes = [];
        $parametros = [];

        if ($pesquisa !== '') {
            $termo = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $pesquisa) . '%';

            $condicoes[] = '(nome LIKE ? OR contacto LIKE ? OR equipamento LIKE ?'
                . ' OR marca_modelo LIKE ? OR descricao LIKE ?)';

            $parametros = array_merge($parametros, [$termo, $termo, $termo, $termo, $termo]);
        }

        if ($estadoFiltro !== '') {
            $condicoes[]  = 'estado = ?';
            $parametros[] = $estadoFiltro;
        }

        $sql = 'SELECT id, nome, contacto, equipamento, marca_modelo, descricao,
                       estado, pedido_id, convertida_em, criado_em
                  FROM solicitacoes'
            . ($condicoes !== [] ? ' WHERE ' . implode(' AND ', $condicoes) : '')
            . ' ORDER BY criado_em DESC, id DESC LIMIT 200';

        $consulta = $pdo->prepare($sql);
        $consulta->execute($parametros);
        $solicitacoes = $consulta->fetchAll();

        if ($idFicha > 0) {
            $ficha = buscarSolicitacao($pdo, $idFicha);

            if ($ficha === null) {
                $erro = 'O pedido de orçamento #' . $idFicha . ' já não existe.';
            } elseif ($ficha['pedido_id'] === null && $erroDados === '') {
                $consulta = $pdo->query('SELECT id, nome, nif FROM clientes ORDER BY nome LIMIT 500');
                $clientes = $consulta->fetchAll();
            }
        }
    } catch (Throwable $excecao) {
        error_log('[FixIt][Solicitacoes][Dados] ' . $excecao->getMessage());
        $erroDados = 'Não foi possível ler os pedidos. Confirma o MySQL e as migrações '
            . 'sql/migracao-solicitacoes.sql e sql/migracao-solicitacoes-fluxo.sql.';
    }
}

// Valores por omissão do formulário: o que foi submetido (com erro) ou, na
// primeira abertura, o que o cliente escreveu no site.
$contacto = classificarContacto((string) ($ficha['contacto'] ?? ''));

$form = [
    'modo_cliente' => 'novo',
    'cliente_id'   => '',
    'nome'         => (string) ($ficha['nome'] ?? ''),
    'email'        => $contacto['tipo'] === 'email' ? $contacto['valor'] : '',
    'telefone'     => $contacto['tipo'] === 'telefone' ? $contacto['valor'] : '',
    'nif'          => '',
    'tipo'         => (string) ($ficha['equipamento'] ?? ''),
    'marca_modelo' => (string) ($ficha['marca_modelo'] ?? ''),
    'numero_serie' => '',
    'descricao'    => (string) ($ficha['descricao'] ?? ''),
    'orcamento'    => '',
];

if ($valores !== []) {
    foreach (array_keys($form) as $chave) {
        if (isset($valores[$chave])) {
            $form[$chave] = valorCampo($valores, $chave);
        }
    }

    // A escolha do cliente é o que decide o modo: com um cliente
    // seleccionado reutiliza-se, sem ele cria-se um novo.
    $form['modo_cliente'] = (int) $form['cliente_id'] > 0 ? 'existente' : 'novo';
}

// Liga de volta à lista, mantendo os filtros.
$urlLista = 'solicitacoes.php';

if ($pesquisa !== '' || $estadoFiltro !== '') {
    $urlLista .= '?' . http_build_query(array_filter([
        'q'      => $pesquisa,
        'estado' => $estadoFiltro,
    ]));
}
// A moldura é a mesma das outras páginas do painel: barra lateral com o
// menu, barra de topo com as migalhas e o perfil. Esta página chegou a
// desenhar o seu próprio <head> e um <div class="conteudo"> sem o menu —
// quem entrava aqui ficava sem navegação nenhuma.
painelAbrir(
    'solicitacoes',
    'Pedidos do site',
    'Pedidos de orçamento enviados pelo formulário público do site.',
    [
        ['Painel', 'painel.php'],
        ['Pedidos do site', null],
    ],
    $erroDados,
    true
);
?>

<main class="principal" id="conteudo" tabindex="-1">

        <header class="principal__cabecalho">
            <div>
                <h1>Pedidos do site</h1>
                <p class="principal__descricao">
                    Pedidos de orçamento enviados pelo formulário público.
                    <?php if ($contagens['Nova'] > 0): ?>
                        <strong><?= (int) $contagens['Nova'] ?> por contactar.</strong>
                    <?php else: ?>
                        Nenhum por contactar.
                    <?php endif; ?>
                </p>
            </div>

            <p class="principal__acao">
                <a class="botao botao--fantasma" href="pedido-novo.php">Novo pedido no balcão</a>
            </p>
        </header>

        <?php if ($sucesso !== ''): ?>
            <div class="alerta alerta--sucesso" role="status">
                <p class="alerta__texto"><?= h($sucesso) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($erro !== ''): ?>
            <div class="alerta alerta--erro" role="alert">
                <p class="alerta__texto"><?= h($erro) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($erroDados !== ''): ?>
            <section class="painel">
                <div class="alerta alerta--erro" role="alert">
                    <p class="alerta__texto"><?= h($erroDados) ?></p>
                </div>
            </section>
        <?php else: ?>

            <?php if ($ficha !== null): ?>
                <!-- =====================================================
                     Ficha da solicitação
                     ===================================================== -->
                <section class="painel" aria-labelledby="titulo-ficha">
                    <div class="painel__cabecalho">
                        <div>
                            <p class="migalhas">
                                <a href="<?= h($urlLista) ?>">Todos os pedidos do site</a>
                            </p>
                            <h2 id="titulo-ficha">
                                Pedido de orçamento #<?= (int) $ficha['id'] ?>
                            </h2>
                            <p class="painel__descricao">
                                Recebido a <?= h(dataHoraCurta($ficha['criado_em'])) ?>
                                · <?= h($ficha['estado']) ?>
                            </p>
                        </div>
                        <p class="principal__acao">
                            <a class="botao botao--fantasma" href="<?= h($urlLista) ?>">Voltar à lista</a>
                        </p>
                    </div>

                    <div class="solicitacao__corpo">
                    <dl class="solicitacao__dados">
                        <dt class="campo__rotulo">Nome</dt>
                        <dd><?= h($ficha['nome']) ?></dd>

                        <dt class="campo__rotulo">Contacto</dt>
                        <dd>
                            <?= h($ficha['contacto']) ?>
                            <?php if ($contacto['tipo'] === 'telefone'): ?>
                                <span class="solicitacao__nota">· telefone</span>
                            <?php elseif ($contacto['tipo'] === 'email'): ?>
                                <span class="solicitacao__nota">· email</span>
                            <?php endif; ?>
                        </dd>

                        <dt class="campo__rotulo">Equipamento</dt>
                        <dd>
                            <?= h($ficha['equipamento']) ?>
                            <?php if (!empty($ficha['marca_modelo'])): ?>
                                · <?= h($ficha['marca_modelo']) ?>
                            <?php endif; ?>
                        </dd>

                        <dt class="campo__rotulo">O que está avariado</dt>
                        <dd><?= nl2br(h($ficha['descricao'])) ?></dd>

                        <?php if ($ficha['pedido_id'] !== null): ?>
                            <dt class="campo__rotulo">Pedido aberto</dt>
                            <dd>
                                <a href="pedido.php?id=<?= (int) $ficha['pedido_id'] ?>">
                                    #<?= (int) $ficha['pedido_id'] ?>
                                </a>
                                · <?= h(dataCurta($ficha['convertida_em'])) ?>
                            </dd>
                        <?php endif; ?>
                    </dl>

                    <?php if ($ficha['pedido_id'] === null): ?>
                        <div class="principal__acao solicitacao__acoes">
                            <?php foreach (SOLICITACOES_ESTADOS_MANUAIS as $opcao): ?>
                                <?php if ($ficha['estado'] === $opcao) { continue; } ?>
                                <form method="post" action="solicitacoes.php">
                                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                    <input type="hidden" name="acao" value="estado">
                                    <input type="hidden" name="id" value="<?= (int) $ficha['id'] ?>">
                                    <input type="hidden" name="estado" value="<?= h($opcao) ?>">
                                    <button class="botao botao--fantasma" type="submit">
                                        Marcar como «<?= h($opcao) ?>»
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>

                        <!-- =============================================
                             Conversão em pedido de reparação
                             ============================================= -->
                        <h3>Abrir pedido de reparação</h3>
                        <p class="painel__descricao">
                            O site não pede NIF nem confirma o telefone. Completa o que falta
                            com o cliente à frente: o pedido nasce em «Recebido», com PIN novo,
                            sem técnico atribuído, e a solicitação fica marcada como convertida.
                        </p>

                        <form method="post" action="solicitacoes.php" novalidate>
                            <input type="hidden" name="csrf" value="<?= h($token) ?>">
                            <input type="hidden" name="acao" value="converter">
                            <input type="hidden" name="id" value="<?= (int) $ficha['id'] ?>">

                            <?php if (isset($errosCampos['cliente_id'])): ?>
                                <p class="campo__erro"><?= h($errosCampos['cliente_id']) ?></p>
                            <?php endif; ?>

                            <div class="campo">
                                <label class="campo__rotulo" for="conv-cliente">Cliente</label>
                                <select class="entrada" id="conv-cliente" name="cliente_id">
                                    <option value="0">— criar cliente novo com os dados abaixo —</option>
                                    <?php foreach ($clientes as $clienteLista): ?>
                                        <option value="<?= (int) $clienteLista['id'] ?>"
                                            <?= $form['modo_cliente'] === 'existente'
                                                && (string) $clienteLista['id'] === $form['cliente_id'] ? 'selected' : '' ?>>
                                            <?= h($clienteLista['nome']) ?> · NIF <?= h($clienteLista['nif']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="solicitacao__dica">
                                    Com um cliente escolhido, os campos abaixo são ignorados.
                                </p>
                            </div>

                            <div class="grelha">
                                <?php
                                campoTexto('nome', 'Nome do cliente', $form['nome'], $errosCampos, 'text', true, 120);
                                campoTexto('nif', 'NIF (9 dígitos)', $form['nif'], $errosCampos, 'text', true, 9);
                                campoTexto('telefone', 'Telefone', $form['telefone'], $errosCampos, 'tel', true, 20);
                                campoTexto('email', 'Email (opcional)', $form['email'], $errosCampos, 'email', false, 190);
                                campoTexto('tipo', 'Tipo de equipamento', $form['tipo'], $errosCampos, 'text', true, 60);
                                campoTexto('marca_modelo', 'Marca e modelo', $form['marca_modelo'], $errosCampos, 'text', true, 120);
                                campoTexto('numero_serie', 'Número de série (opcional)', $form['numero_serie'], $errosCampos, 'text', false, 100);
                                campoTexto('orcamento', 'Orçamento em euros (opcional)', $form['orcamento'], $errosCampos, 'text', false, 12);
                                ?>
                            </div>

                            <div class="campo<?= isset($errosCampos['descricao']) ? ' campo--invalido' : '' ?>">
                                <label class="campo__rotulo" for="conv-descricao">Avaria comunicada</label>
                                <textarea class="entrada" id="conv-descricao" name="descricao" rows="5"
                                          maxlength="2000"
                                          <?= isset($errosCampos['descricao']) ? 'aria-invalid="true" aria-describedby="erro-descricao"' : '' ?>><?= h($form['descricao']) ?></textarea>
                                <p class="campo__erro" id="erro-descricao"
                                   <?= isset($errosCampos['descricao']) ? '' : 'hidden' ?>><?= h($errosCampos['descricao'] ?? '') ?></p>
                            </div>

                            <div class="principal__acao">
                                <a class="botao botao--fantasma" href="<?= h($urlLista) ?>">Cancelar</a>
                                <button class="botao botao--primario" type="submit">
                                    Abrir pedido e continuar
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <p class="painel__descricao">
                            Este pedido de orçamento já deu origem ao pedido
                            <a href="pedido.php?id=<?= (int) $ficha['pedido_id'] ?>">#<?= (int) $ficha['pedido_id'] ?></a>.
                            Para o corrigir, usa a ficha do pedido.
                        </p>
                    <?php endif; ?>
                    </div><!-- /.solicitacao__corpo -->
                </section>
            <?php endif; ?>

            <!-- =========================================================
                 Lista e filtros
                 ========================================================= -->
            <section class="painel" id="lista" aria-labelledby="titulo-lista">
                <div class="painel__cabecalho">
                    <div>
                        <h2 id="titulo-lista">Recebidos</h2>
                        <p class="painel__descricao">
                            <?= count($solicitacoes) ?>
                            <?= count($solicitacoes) === 1 ? 'pedido' : 'pedidos' ?>
                            <?= ($pesquisa !== '' || $estadoFiltro !== '') ? 'no filtro' : 'no total' ?>
                            · <?= $total ?> na caixa de entrada.
                        </p>
                    </div>
                </div>

                <?php
                // A barra de filtros veste o mesmo molde da lista de pedidos:
                // pesquisa, acções e, por baixo, o resumo por estado com as
                // contagens — cada número é o atalho para esse recorte. Antes
                // disto era uma linha de ligações soltas e um formulário com
                // margens escritas à mão, que não seguia o resto do painel.
                $rotulosEstado = ['' => 'Todas'] + array_combine(
                    SOLICITACOES_ESTADOS,
                    SOLICITACOES_ESTADOS
                );
                ?>

                <form class="filtros filtros--pesquisa" method="get" action="solicitacoes.php#lista"
                      role="search" aria-label="Filtrar pedidos do site">
                    <?php if ($estadoFiltro !== ''): ?>
                        <input type="hidden" name="estado" value="<?= h($estadoFiltro) ?>">
                    <?php endif; ?>

                    <div class="campo filtros__campo filtros__campo--pesquisa">
                        <label class="campo__rotulo" for="filtro-q">Procurar</label>
                        <input class="entrada" type="search" id="filtro-q" name="q"
                               value="<?= h($pesquisa) ?>"
                               placeholder="Nome, contacto, equipamento ou avaria"
                               autocomplete="off">
                    </div>

                    <div class="filtros__acoes">
                        <button class="botao botao--primario" type="submit">Procurar</button>
                        <?php if ($pesquisa !== '' || $estadoFiltro !== ''): ?>
                            <a class="botao botao--fantasma" href="solicitacoes.php#lista">Limpar</a>
                        <?php endif; ?>
                    </div>

                    <p class="filtros__resumo">
                        <span class="filtros__resumo-rotulo">Estado</span>
                        <?php $primeiroEstado = true; ?>
                        <?php foreach ($rotulosEstado as $valorEstado => $rotuloEstado): ?>
                            <?php if (!$primeiroEstado): ?><span aria-hidden="true">·</span><?php endif; ?>
                            <?php $primeiroEstado = false; ?>
                            <?php
                            $contagem = $valorEstado === ''
                                ? $total
                                : (int) ($contagens[$valorEstado] ?? 0);
                            $urlEstado = 'solicitacoes.php?' . http_build_query(array_filter([
                                'q'      => $pesquisa,
                                'estado' => $valorEstado,
                            ])) . '#lista';
                            ?>
                            <?php if ($estadoFiltro === $valorEstado): ?>
                                <span class="filtros__resumo-atual"><?= h($rotuloEstado) ?> <strong><?= $contagem ?></strong></span>
                            <?php else: ?>
                                <a href="<?= h($urlEstado) ?>"><?= h($rotuloEstado) ?> <strong><?= $contagem ?></strong><span class="visualmente-oculto"> — mostrar só estes pedidos</span></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </p>
                </form>

                <?php if ($solicitacoes === []): ?>
                    <p class="painel__descricao">
                        <?php if ($pesquisa !== '' || $estadoFiltro !== ''): ?>
                            Nenhum pedido corresponde a este filtro.
                            <a href="solicitacoes.php">Ver todos</a>.
                        <?php else: ?>
                            Ainda não chegou nenhum pedido pelo site. Quando chegar,
                            aparece aqui.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <div class="tabela__contentor">
                        <table class="tabela">
                            <caption class="visualmente-oculto">
                                Pedidos de orçamento recebidos pelo site, do mais recente
                                para o mais antigo.
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Quando</th>
                                    <th scope="col">Cliente</th>
                                    <th scope="col">Contacto</th>
                                    <th scope="col">Equipamento</th>
                                    <th scope="col">Estado</th>
                                    <th scope="col"><span class="visualmente-oculto">Abrir</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($solicitacoes as $linha): ?>
                                    <tr>
                                        <td data-rotulo="Quando" class="solicitacao__quando"><?= h(dataHoraCurta($linha['criado_em'])) ?></td>
                                        <td data-rotulo="Cliente"><?= h($linha['nome']) ?></td>
                                        <td data-rotulo="Contacto"><?= h($linha['contacto']) ?></td>
                                        <td data-rotulo="Equipamento">
                                            <?= h($linha['equipamento']) ?>
                                            <?php if (!empty($linha['marca_modelo'])): ?>
                                                <span class="solicitacao__marca"><?= h($linha['marca_modelo']) ?></span>
                                            <?php endif; ?>
                                            <?php if (trim((string) $linha['descricao']) !== ''): ?>
                                                <span class="solicitacao__avaria"><?= h($linha['descricao']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-rotulo="Estado">
                                            <?php
                                            // A etiqueta diz o estado da caixa de
                                            // entrada e, quando já houve conversão, o
                                            // número do pedido ao lado. O estado
                                            // continua a ser «Convertida», que é o que
                                            // o resumo por estado conta.
                                            $classeEstado = match ((string) $linha['estado']) {
                                                'Nova'        => 'estado--nova',
                                                'Em contacto' => 'estado--contacto',
                                                'Convertida'  => 'estado--convertida',
                                                'Arquivada'   => 'estado--arquivada',
                                                default       => '',
                                            };
                                            ?>
                                            <span class="estado <?= h($classeEstado) ?>"><?= h($linha['estado']) ?></span>
                                            <?php if ($linha['pedido_id'] !== null): ?>
                                                <a class="solicitacao__pedido"
                                                   href="pedido.php?id=<?= (int) $linha['pedido_id'] ?>"
                                                   aria-label="Abrir o pedido #<?= (int) $linha['pedido_id'] ?>">#<?= (int) $linha['pedido_id'] ?></a>
                                            <?php endif; ?>
                                        </td>
                                        <td data-rotulo="Abrir">
                                            <a class="botao botao--pequeno"
                                               href="<?= h('solicitacoes.php?' . http_build_query(array_filter([
                                                   'q'      => $pesquisa,
                                                   'estado' => $estadoFiltro,
                                                   'ver'    => (int) $linha['id'],
                                               ]))) ?>">Abrir</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    </main>

    </div><!-- /.conteudo -->

</div><!-- /.aplicacao -->

</body>
</html>
