<?php
declare(strict_types=1);

/**
 * clientes.php — Lista dos clientes da oficina e do número de pedidos de
 * cada um.
 *
 * Estrutura:
 *   1. Ligação e consultas (total de clientes, clientes da página, pedidos
 *      por entregar para o contador da barra lateral).
 *   2. HTML + PHP de apresentação.
 *
 * A contagem de pedidos vem de um LEFT JOIN: um cliente sem pedidos
 * aparece com 0, não desaparece da lista. O link de cada linha leva à
 * página Pedidos já filtrada pelo NIF do cliente.
 *
 * A pesquisa (nome, NIF ou contacto) e a ordenação (nome, cliente desde e
 * número de pedidos) vivem no URL, como na lista de pedidos: o formulário,
 * a paginação e os atalhos do cabeçalho levam-nas consigo, e um
 * recarregamento não perde o recorte.
 *
 * Requisitos: PHP 8.0+, pdo_mysql e a base de dados fixit.
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/layout-painel.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';

// A lista de clientes é área da oficina: sem sessão válida, Sessao::exigir()
// manda para o login.php antes de qualquer consulta.
Sessao::iniciar();

/** @var array{id: int, nome: string, email: string, cargo: string} $utilizador */
$utilizador = Sessao::exigir();

// Contador do menu: quantos pedidos do site estão por contactar.
$solicitacoesNovas = solicitacoesNovas();

const POR_PAGINA = 20;

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

/** NIF em blocos de três algarismos ("123 456 789"), como nos documentos. */
function formatarNif(?string $nif): string
{
    $digitos = preg_replace('/\D+/', '', (string) $nif);

    if ($digitos === null || strlen($digitos) !== 9) {
        return (string) $nif;
    }

    return substr($digitos, 0, 3) . ' '
         . substr($digitos, 3, 3) . ' '
         . substr($digitos, 6, 3);
}

/** Página pedida na query string; 1 quando falta ou é inválida. */
$paginaPedida = $_GET['pagina'] ?? null;

$pagina = is_string($paginaPedida)
    && ctype_digit($paginaPedida)
    && (int) $paginaPedida > 0
        ? (int) $paginaPedida
        : 1;

// Pesquisa: nome, NIF, email ou telefone. Os algarismos contam também
// sozinhos, para um NIF escrito como nos documentos («123 456 789»)
// encontrar o registo, que guarda os nove dígitos seguidos.
$pesquisa = $_GET['q'] ?? null;
$pesquisa = is_string($pesquisa) ? trim($pesquisa) : '';
$pesquisaDigitos = preg_replace('/\D+/', '', $pesquisa) ?? '';

// Ordenação da lista: nome (por omissão), cliente desde ou número de
// pedidos. A chave por omissão não vai no URL — quem abre a lista quer os
// clientes por nome, não um endereço com a ordem escrita.
$chavesOrdem = ['nome', 'entrada', 'pedidos'];

$ordemPedida = $_GET['ordem'] ?? null;
$ordemFiltro = is_string($ordemPedida) && in_array($ordemPedida, $chavesOrdem, true)
    ? $ordemPedida
    : 'nome';

// Sentido: o primeiro extremo da chave (A–Z, mais recentes, mais pedidos)
// ou o inverso. Vale para qualquer chave, por isso não depende de `ordem`.
$sentidoPedido = $_GET['sentido'] ?? null;
$sentidoFiltro = is_string($sentidoPedido) && $sentidoPedido === 'antigos'
    ? 'antigos'
    : '';

$clientes      = [];
$totalClientes = 0;   // clientes do recorte (pesquisa incluída)
$totalGeral    = 0;   // clientes da oficina, sem recorte
$totalPaginas  = 1;
$pedidosAtivos = 0;
$erroDados     = '';

try {
    $pdo = obterConexao();

    // 1. Total sem recorte: é o que distingue a oficina vazia do recorte
    //    sem resultados, e serve a frase do topo e o cartão da lista.
    $totalGeral = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();

    // A pesquisa procura em quatro colunas, e nos algarismos sozinhos
    // quando o NIF vem pontuado. Uma condição só, entre parênteses, para o
    // `AND` de outros filtros não a partir ao meio.
    $condicoes  = [];
    $parametros = [];

    if ($pesquisa !== '') {
        $termo  = '%' . $pesquisa . '%';
        $partes = ['c.nome LIKE ?', 'c.email LIKE ?', 'c.telefone LIKE ?', 'c.nif LIKE ?'];

        $parametros = array_merge($parametros, array_fill(0, 4, $termo));

        if ($pesquisaDigitos !== '' && $pesquisaDigitos !== $pesquisa) {
            $partes[] = 'c.nif LIKE ?';
            $partes[] = 'c.telefone LIKE ?';
            $parametros[] = '%' . $pesquisaDigitos . '%';
            $parametros[] = '%' . $pesquisaDigitos . '%';
        }

        $condicoes[] = '(' . implode(' OR ', $partes) . ')';
    }

    $onde = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

    // 2. Total do recorte (cabeçalho do painel e paginação).
    $consulta = $pdo->prepare('SELECT COUNT(*) FROM clientes c' . $onde);
    $consulta->execute($parametros);
    $totalClientes = (int) $consulta->fetchColumn();

    $totalPaginas = max(1, (int) ceil($totalClientes / POR_PAGINA));

    // Um link antigo para uma página que deixou de existir cai na última.
    $pagina = min($pagina, $totalPaginas);

    $offset = ($pagina - 1) * POR_PAGINA;

    // 3. Clientes da página com o número de pedidos de cada um. As
    //    colunas do GROUP BY acompanham o SELECT para funcionar também
    //    com ONLY_FULL_GROUP_BY ativo (predefinição do MySQL 8). O sentido
    //    inverte a chave escolhida e, dentro dela, o nome desempata sempre
    //    de A a Z — dois clientes com os mesmos pedidos não trocam de
    //    lugar entre recarregamentos.
    $ordensSql = [
        'nome'    => 'c.nome ' . ($sentidoFiltro === 'antigos' ? 'DESC' : 'ASC') . ', c.id',
        'entrada' => 'c.criado_em ' . ($sentidoFiltro === 'antigos' ? 'ASC' : 'DESC') . ', c.nome, c.id',
        'pedidos' => 'total_pedidos ' . ($sentidoFiltro === 'antigos' ? 'ASC' : 'DESC') . ', c.nome, c.id',
    ];

    $consulta = $pdo->prepare(
        'SELECT c.id, c.nome, c.nif, c.telefone, c.email, c.criado_em,
                COUNT(p.id) AS total_pedidos
           FROM clientes c
      LEFT JOIN equipamentos e ON e.cliente_id = c.id
      LEFT JOIN pedidos p      ON p.equipamento_id = e.id'
        . $onde . '
       GROUP BY c.id, c.nome, c.nif, c.telefone, c.email, c.criado_em
       ORDER BY ' . $ordensSql[$ordemFiltro] . '
          LIMIT ? OFFSET ?'
    );

    $indice = 1;

    foreach ($parametros as $valor) {
        $consulta->bindValue($indice++, $valor, PDO::PARAM_STR);
    }

    $consulta->bindValue($indice++, POR_PAGINA, PDO::PARAM_INT);
    $consulta->bindValue($indice++, $offset, PDO::PARAM_INT);
    $consulta->execute();

    $clientes = $consulta->fetchAll();

    // 4. Contador da barra lateral: pedidos ainda por entregar.
    $pedidosAtivos = (int) $pdo->query(
        'SELECT COUNT(*) FROM pedidos WHERE estado <> \'Entregue\''
    )->fetchColumn();
} catch (Throwable $erro) {
    error_log('[FixIt][Clientes] ' . $erro->getMessage());
    $erroDados = 'Confirma que o servidor MySQL está ligado e que a base de dados fixit foi importada.';
}

// Uma coisa é a oficina não ter clientes, outra é a pesquisa não trazer
// nenhum: à primeira não há pesquisa que a explique. O erro de base de
// dados fica de fora — aí quem fala é o aviso.
$oficinaVazia = $erroDados === '' && $totalGeral === 0;

/**
 * URL da lista, sempre com a página explícita, a pesquisa e a ordem que
 * estiverem ativas: a paginação, o formulário e os atalhos do cabeçalho
 * não perdem o recorte.
 */
function urlClientes(int $pagina, array $extras = [], array $remover = []): string
{
    global $pesquisa, $ordemFiltro, $sentidoFiltro;

    $parametros = [];

    if ($pesquisa !== '' && !in_array('q', $remover, true)) {
        $parametros['q'] = $pesquisa;
    }

    if ($ordemFiltro !== 'nome' && !in_array('ordem', $remover, true)) {
        $parametros['ordem'] = $ordemFiltro;
    }

    if ($sentidoFiltro !== '' && !in_array('sentido', $remover, true)) {
        $parametros['sentido'] = $sentidoFiltro;
    }

    return 'clientes.php?' . http_build_query(array_merge(
        $parametros,
        ['pagina' => $pagina],
        $extras
    )) . '#lista';
}

// Como cada chave se lê dentro de uma frase, e como se chamam os dois
// extremos no controlo do sentido: uma data vai dos mais recentes aos mais
// antigos, um número vai dos que têm mais aos que têm menos, e o nome vai
// de A a Z. Os extremos dizem-se como as frases, para o botão e a frase
// não terem dois vocabulários.
$frasesOrdem = [
    'nome' => $sentidoFiltro === 'antigos'
        ? 'pelo nome, de Z a A'
        : 'pelo nome, de A a Z',
    'entrada' => $sentidoFiltro === 'antigos'
        ? 'pela data de criação, dos mais antigos para os mais recentes'
        : 'pela data de criação, dos mais recentes para os mais antigos',
    'pedidos' => $sentidoFiltro === 'antigos'
        ? 'pelo número de pedidos, dos que têm menos para os que têm mais'
        : 'pelo número de pedidos, dos que têm mais para os que têm menos',
];

$fraseOrdem = $frasesOrdem[$ordemFiltro];

if ($ordemFiltro === 'nome') {
    $sentidoInicial = 'A–Z';
    $sentidoInverso = 'Z–A';
} elseif ($ordemFiltro === 'pedidos') {
    $sentidoInicial = 'Mais pedidos';
    $sentidoInverso = 'Menos pedidos';
} else {
    $sentidoInicial = 'Recentes';
    $sentidoInverso = 'Antigos';
}

// A frase do topo diz o que se pediu — sem recorte, descreve a lista; com a
// pesquisa vazia, diz que é a pesquisa que não trouxe ninguém.
if ($oficinaVazia) {
    $descricaoTopo = 'A oficina ainda não tem clientes registados.';
} elseif ($totalClientes === 0) {
    $descricaoTopo = 'Nenhum cliente corresponde à pesquisa «' . $pesquisa . '».';
} elseif ($pesquisa !== '') {
    $descricaoTopo = 'Clientes que correspondem a «' . $pesquisa . '», ' . $fraseOrdem . '.';
} else {
    /* A frase já nomeia o número de pedidos quando é essa a ordem: repeti-lo
       no fim dizia duas vezes a mesma coisa. Nesse caso o fecho descreve o
       que a lista mostra além da chave. */
    $fecho = $ordemFiltro === 'pedidos'
        ? 'com o contacto e a data de registo'
        : 'com o número de pedidos de cada um';

    $descricaoTopo = 'Os clientes da oficina, ' . $fraseOrdem . ', ' . $fecho . '.';
}

// Com a oficina vazia o título é o da secção, sem recorte: nomear uma
// pesquisa por cima de zero clientes prometia um recorte que não existe.
if ($oficinaVazia) {
    $tituloLista = 'Clientes';
} elseif ($pesquisa !== '') {
    $tituloLista = 'Clientes que correspondem a «' . $pesquisa . '»';
} else {
    $tituloLista = 'Todos os clientes';
}

// Na migalha e no separador a página é "Clientes"; a pesquisa acrescenta-se
// quando existe, para quem tem várias abas abertas saber qual está a ver.
$tituloRecorte = $pesquisa === '' || $oficinaVazia
    ? 'Clientes'
    : 'Clientes — ' . $pesquisa;
?>
<?php painelAbrir(
    'clientes',
    $tituloRecorte,
    'Todos os clientes da oficina FixIt, com NIF, contacto e número de pedidos.',
    [
        ['Painel', 'painel.php'],
        ['Clientes', null],
    ],
    $erroDados,
    false,
    $pedidosAtivos ?? -1
); ?>

<main class="principal" id="conteudo" tabindex="-1">

    <div class="cabecalho">
        <div class="cabecalho__texto">
            <h1>Clientes</h1>
            <p class="cabecalho__descricao"><?= h($descricaoTopo) ?></p>
        </div>
    </div>

    <?php if ($erroDados !== ''): ?>
        <section class="painel" aria-labelledby="titulo-erro">
            <div class="aviso aviso--erro">
                <span class="aviso__icone" aria-hidden="true">
                    <svg><use href="#icone-info"/></svg>
                </span>
                <p class="aviso__titulo" id="titulo-erro">Não foi possível carregar os clientes</p>
                <p class="aviso__texto"><?= h($erroDados) ?></p>
                <a class="botao botao--fantasma" href="clientes.php">Tentar novamente</a>
            </div>
        </section>
    <?php else: ?>

        <section class="painel" id="lista" aria-labelledby="titulo-lista">
            <div class="painel__cabecalho">
                <div>
                    <h2 id="titulo-lista"><?= h($tituloLista) ?></h2>
                    <p class="painel__descricao">
                        <?= $totalClientes ?> <?= $pesquisa !== ''
                            ? ($totalClientes === 1 ? 'cliente encontrado' : 'clientes encontrados')
                            : ($totalClientes === 1 ? 'cliente registado' : 'clientes registados') ?>.
                    </p>
                </div>

                <?php /* A mesma gramática da lista de pedidos: a chave
                         escolhida e o sentido, cada um no seu grupo, e a
                         opção ativa como texto em vez de ligação para si. */ ?>
                <div class="ordenacao" role="group" aria-label="Ordenar a lista">
                    <div class="ordenacao__grupo">
                        <span class="ordenacao__rotulo">Ordenar</span>
                        <?php if ($ordemFiltro === 'nome'): ?>
                            <span class="ordenacao__atual" aria-current="true">Nome</span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlClientes(1, [], ['ordem'])) ?>">Nome</a>
                        <?php endif; ?>

                        <?php if ($ordemFiltro === 'entrada'): ?>
                            <span class="ordenacao__atual" aria-current="true">Cliente desde</span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlClientes(1, ['ordem' => 'entrada'])) ?>">Cliente desde</a>
                        <?php endif; ?>

                        <?php if ($ordemFiltro === 'pedidos'): ?>
                            <span class="ordenacao__atual" aria-current="true">Pedidos</span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlClientes(1, ['ordem' => 'pedidos'])) ?>">Pedidos</a>
                        <?php endif; ?>
                    </div>

                    <?php
                    // A chave escolhida acompanha o sentido: mudar de sentido
                    // não muda o que se está a ordenar.
                    $extrasOrdem = $ordemFiltro !== 'nome' ? ['ordem' => $ordemFiltro] : [];
                    ?>
                    <div class="ordenacao__grupo">
                        <span class="ordenacao__rotulo">Sentido</span>
                        <?php if ($sentidoFiltro === ''): ?>
                            <span class="ordenacao__atual" aria-current="true"><?= h($sentidoInicial) ?></span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlClientes(1, $extrasOrdem, ['sentido'])) ?>"><?= h($sentidoInicial) ?></a>
                        <?php endif; ?>
                        <?php if ($sentidoFiltro === 'antigos'): ?>
                            <span class="ordenacao__atual" aria-current="true"><?= h($sentidoInverso) ?></span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlClientes(1, array_merge($extrasOrdem, ['sentido' => 'antigos']))) ?>"><?= h($sentidoInverso) ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php /* A barra leva só a pesquisa: a lista de clientes não tem
                     os recortes da lista de pedidos, e a ordem vive no
                     cabeçalho. A chave e o sentido vão escondidos, para
                     filtrar não os perder. */ ?>
            <form class="filtros filtros--pesquisa" method="get" action="clientes.php#lista"
                  role="search" aria-label="Pesquisar clientes">
                <?php if ($ordemFiltro !== 'nome'): ?>
                    <input type="hidden" name="ordem" value="<?= h($ordemFiltro) ?>">
                <?php endif; ?>

                <?php if ($sentidoFiltro !== ''): ?>
                    <input type="hidden" name="sentido" value="<?= h($sentidoFiltro) ?>">
                <?php endif; ?>

                <div class="campo filtros__campo filtros__campo--pesquisa">
                    <label class="campo__rotulo" for="filtro-q">Cliente, NIF ou contacto</label>
                    <input class="entrada" type="search" id="filtro-q" name="q"
                           value="<?= h($pesquisa) ?>" placeholder="Nome, NIF ou contacto"
                           autocomplete="off">
                </div>

                <div class="filtros__acoes">
                    <button class="botao botao--primario" type="submit">Pesquisar</button>
                    <?php if ($pesquisa !== ''): ?>
                        <a class="botao botao--fantasma" href="clientes.php#lista">Limpar</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($clientes): ?>
                <div class="tabela__contentor" id="resultados">
                    <table class="tabela">
                        <caption class="visualmente-oculto">
                            Clientes da oficina, página <?= $pagina ?> de <?= $totalPaginas ?>,
                            ordenados <?= h($fraseOrdem) ?>, com NIF, contacto, número de
                            pedidos e data de registo.
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Cliente</th>
                                <th scope="col">NIF</th>
                                <th scope="col">Contacto</th>
                                <th scope="col">Pedidos</th>
                                <th scope="col">Cliente desde</th>
                                <th scope="col" class="tabela__acao">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($clientes as $cliente): ?>
                                <?php
                                $pedidosDoCliente = (int) $cliente['total_pedidos'];
                                $email            = (string) ($cliente['email'] ?? '');
                                ?>
                                <tr>
                                    <td class="tabela__cliente"><?= h($cliente['nome']) ?></td>
                                    <td class="tabela__nif"><?= h(formatarNif($cliente['nif'])) ?></td>
                                    <td>
                                        <span class="contacto">
                                            <span><?= h($cliente['telefone']) ?></span>
                                            <?php if ($email !== ''): ?>
                                                <span class="contacto__email"><?= h($email) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="tabela__numero">
                                        <?= $pedidosDoCliente ?>
                                        <span class="visualmente-oculto">
                                            <?= $pedidosDoCliente === 1 ? 'pedido' : 'pedidos' ?>
                                        </span>
                                    </td>
                                    <td class="tabela__data"><?= h(dataCurta($cliente['criado_em'])) ?></td>
                                    <td class="tabela__acao">
                                        <?php if ($pedidosDoCliente > 0): ?>
                                            <a class="ligacao-acao"
                                               href="pedidos.php?q=<?= h((string) $cliente['nif']) ?>#lista"
                                               aria-label="Ver os pedidos de <?= h($cliente['nome']) ?>">
                                                <span>Ver pedidos</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="tabela__sem-acoes">Sem pedidos</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="painel__rodape">
                    <span>
                        Página <?= $pagina ?> de <?= $totalPaginas ?>
                        · <?= $totalClientes ?> <?= $totalClientes === 1 ? 'cliente' : 'clientes' ?>
                    </span>

                    <div class="paginacao__botoes">
                        <?php if ($pagina > 1): ?>
                            <a class="botao botao--fantasma botao--pequeno"
                               href="<?= h(urlClientes($pagina - 1)) ?>" rel="prev">
                                Anterior
                            </a>
                        <?php else: ?>
                            <span class="botao botao--fantasma botao--pequeno" aria-disabled="true">Anterior</span>
                        <?php endif; ?>

                        <?php if ($pagina < $totalPaginas): ?>
                            <a class="botao botao--fantasma botao--pequeno"
                               href="<?= h(urlClientes($pagina + 1)) ?>" rel="next">
                                Seguinte
                            </a>
                        <?php else: ?>
                            <span class="botao botao--fantasma botao--pequeno" aria-disabled="true">Seguinte</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($oficinaVazia): ?>
                <div class="aviso">
                    <span class="aviso__icone" aria-hidden="true">
                        <svg><use href="#icone-clientes"/></svg>
                    </span>
                    <p class="aviso__titulo">Ainda não há clientes registados</p>
                    <p class="aviso__texto">O cliente é criado ao registar o primeiro pedido
                        na oficina.</p>
                    <a class="botao botao--primario" href="pedido-novo.php">
                        <svg aria-hidden="true"><use href="#icone-mais"/></svg>
                        <span>Novo pedido</span>
                    </a>
                </div>
            <?php else: ?>
                <?php /* Recorte sem resultados: a pesquisa é a causa, e é por
                         ela que o cartão fala — o da oficina vazia diria que
                         não há clientes, o que é falso. */ ?>
                <div class="aviso">
                    <span class="aviso__icone" aria-hidden="true">
                        <svg><use href="#icone-clientes"/></svg>
                    </span>
                    <p class="aviso__titulo">Nenhum cliente corresponde à pesquisa «<?= h($pesquisa) ?>»</p>
                    <p class="aviso__texto">A pesquisa procura no nome, no NIF, no email e no
                        telefone do cliente.</p>
                    <?php /* Limpa só a pesquisa: a ordem escolhida não é
                             o que trouxe ninguém, e quem a mudou não a quer
                             perder por ter procurado mal. */ ?>
                    <a class="botao botao--fantasma" href="<?= h(urlClientes(1, [], ['q'])) ?>">
                        <span>Limpar pesquisa</span>
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

<script>
/* =====================================================================
   Mudar de página na lista.
   Paginar é um carregamento como outro qualquer: documento novo, mesma
   página. O navegador não tem como adivinhar isso sozinho — o endereço só
   muda depois do "?" e o caminho é o mesmo —, por isso a transição leva
   uma marca apenas quando o que entra é esta mesma página com outro número
   de página no endereço. Com a marca, painel.css deixa a moldura e o resto
   do conteúdo parados e anima só a tabela dos clientes, em vez de a página
   inteira atravessar para trocar vinte linhas.

   A marca é posta no arranque da página que entra, e não na que sai: o
   `pageswap` também aceita tipos, mas só a partir do `pagereveal` é que a
   transição os tem em conta nas duas metades. Para saber de onde se veio,
   o endereço da última visita a esta lista fica no sessionStorage — é a
   única memória que atravessa documentos da mesma aba.
   ===================================================================== */
(function () {
    "use strict";

    var CHAVE = "fixit:clientes-de-onde";
    var caminho = window.location.pathname;

    function guardar(valor) {
        try {
            if (valor === null) {
                window.sessionStorage.removeItem(CHAVE);
            } else {
                window.sessionStorage.setItem(CHAVE, valor);
            }
        } catch (erro) {
            /* navegação privada sem storage: sem memória, fica a transição
               de sempre */
        }
    }

    function ler() {
        try {
            return window.sessionStorage.getItem(CHAVE);
        } catch (erro) {
            return null;
        }
    }

    /* A sair para outra página, a memória deixa de valer: sem isto, voltar
       à lista depois de uma visita a outra página passava por recorte,
       porque a última visita gravada era esta mesma página. */
    window.addEventListener("pageswap", function (evento) {
        var ativacao = evento.activation;

        if (!ativacao || !ativacao.entry) {
            return;
        }

        var destino;

        try {
            destino = new URL(ativacao.entry.url, window.location.href);
        } catch (erro) {
            return;
        }

        if (destino.pathname !== caminho) {
            guardar(null);
        }
    });

    window.addEventListener("pagereveal", function (evento) {
        var anterior = ler();

        guardar(window.location.href);

        var transicao = evento.viewTransition;

        if (!transicao || !transicao.types || anterior === null) {
            return;
        }

        var antes;

        try {
            antes = new URL(anterior, window.location.href);
        } catch (erro) {
            return;
        }

        /* Outra página, ou esta mesma sem mudar nada: a lista está a ser
           aberta, não a ser paginada. */
        if (antes.pathname !== caminho || antes.search === window.location.search) {
            return;
        }

        transicao.types.add("recorte");
    });
})();
</script>
</body>
</html>
