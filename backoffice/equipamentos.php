<?php
declare(strict_types=1);

/**
 * equipamentos.php — Lista dos equipamentos que passaram pela oficina, com
 * o cliente de cada um e o número de pedidos associados.
 *
 * Estrutura:
 *   1. Ligação e consultas (total, equipamentos da página e pedidos por
 *      entregar para o contador da barra lateral).
 *   2. HTML + PHP de apresentação.
 *
 * A contagem vem de um LEFT JOIN: um equipamento sem pedidos aparece com
 * 0, não desaparece da lista. Por omissão a lista está ordenada pelo nome
 * do cliente e, dentro de cada cliente, pelo modelo — assim os
 * equipamentos da mesma pessoa ficam juntos.
 *
 * A pesquisa (modelo, tipo, número de série ou cliente) e a ordenação
 * (cliente, modelo e número de pedidos) vivem no URL, como na lista de
 * pedidos: o formulário e a paginação levam-nas consigo, e um
 * recarregamento não perde o recorte.
 *
 * Requisitos: PHP 8.0+, pdo_mysql e a base de dados fixit.
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/layout-painel.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';

// A lista de equipamentos é área da oficina: sem sessão válida,
// Sessao::exigir() manda para o login.php antes de qualquer consulta.
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

/** Página pedida na query string; 1 quando falta ou é inválida. */
$paginaPedida = $_GET['pagina'] ?? null;

$pagina = is_string($paginaPedida)
    && ctype_digit($paginaPedida)
    && (int) $paginaPedida > 0
        ? (int) $paginaPedida
        : 1;

// Pesquisa: modelo, tipo, número de série ou nome do cliente. Quem procura
// uma máquina pode lembrar-se de qualquer um deles.
$pesquisa = $_GET['q'] ?? null;
$pesquisa = is_string($pesquisa) ? trim($pesquisa) : '';

// Ordenação da lista: cliente (por omissão), modelo ou número de pedidos.
// A chave por omissão não vai no URL — quem abre a lista quer os
// equipamentos agrupados por cliente, não um endereço com a ordem escrita.
$chavesOrdem = ['cliente', 'modelo', 'pedidos'];

$ordemPedida = $_GET['ordem'] ?? null;
$ordemFiltro = is_string($ordemPedida) && in_array($ordemPedida, $chavesOrdem, true)
    ? $ordemPedida
    : 'cliente';

// Sentido: o primeiro extremo da chave (A–Z, mais pedidos) ou o inverso.
// Vale para qualquer chave, por isso não depende de `ordem`.
$sentidoPedido = $_GET['sentido'] ?? null;
$sentidoFiltro = is_string($sentidoPedido) && $sentidoPedido === 'antigos'
    ? 'antigos'
    : '';

$equipamentos      = [];
$totalEquipamentos = 0;   // equipamentos do recorte (pesquisa incluída)
$totalGeral        = 0;   // equipamentos da oficina, sem recorte
$totalPaginas      = 1;
$pedidosAtivos     = 0;
$erroDados         = '';

try {
    $pdo = obterConexao();

    // 1. Total sem recorte: é o que distingue a oficina vazia do recorte
    //    sem resultados, e serve a frase do topo e o cartão da lista.
    $totalGeral = (int) $pdo->query('SELECT COUNT(*) FROM equipamentos')->fetchColumn();

    // A pesquisa procura no modelo, no tipo, na série e no nome do
    // cliente. Uma condição só, entre parênteses, para o `AND` de outros
    // filtros não a partir ao meio.
    $condicoes  = [];
    $parametros = [];

    if ($pesquisa !== '') {
        $termo        = '%' . $pesquisa . '%';
        $condicoes[]  = '(e.marca_modelo LIKE ? OR e.tipo LIKE ?'
            . ' OR e.numero_serie LIKE ? OR c.nome LIKE ?)';
        $parametros   = array_fill(0, 4, $termo);
    }

    $onde = $condicoes === [] ? '' : ' WHERE ' . implode(' AND ', $condicoes);

    // 2. Total do recorte (cabeçalho do painel e paginação).
    $consulta = $pdo->prepare(
        'SELECT COUNT(*)
           FROM equipamentos e
     INNER JOIN clientes c ON c.id = e.cliente_id'
        . $onde
    );

    $consulta->execute($parametros);
    $totalEquipamentos = (int) $consulta->fetchColumn();

    $totalPaginas = max(1, (int) ceil($totalEquipamentos / POR_PAGINA));

    // Um link antigo para uma página que deixou de existir cai na última.
    $pagina = min($pagina, $totalPaginas);

    $offset = ($pagina - 1) * POR_PAGINA;

    // 3. Equipamentos da página com o cliente e o número de pedidos.
    //    As colunas do GROUP BY acompanham o SELECT para funcionar também
    //    com ONLY_FULL_GROUP_BY ativo (predefinição do MySQL 8). O sentido
    //    inverte a chave escolhida; o cliente e o modelo desempatam sempre
    //    de A a Z, para dois equipamentos iguais não trocarem de lugar
    //    entre recarregamentos.
    $ordensSql = [
        'cliente' => 'c.nome ' . ($sentidoFiltro === 'antigos' ? 'DESC' : 'ASC')
            . ', e.marca_modelo, e.id',
        'modelo'  => 'e.marca_modelo ' . ($sentidoFiltro === 'antigos' ? 'DESC' : 'ASC')
            . ', c.nome, e.id',
        'pedidos' => 'total_pedidos ' . ($sentidoFiltro === 'antigos' ? 'ASC' : 'DESC')
            . ', c.nome, e.marca_modelo, e.id',
    ];

    $consulta = $pdo->prepare(
        'SELECT e.id, e.tipo, e.marca_modelo, e.numero_serie,
                c.nome AS cliente_nome,
                COUNT(p.id) AS total_pedidos
           FROM equipamentos e
     INNER JOIN clientes c     ON c.id = e.cliente_id
      LEFT JOIN pedidos p      ON p.equipamento_id = e.id'
        . $onde . '
       GROUP BY e.id, e.tipo, e.marca_modelo, e.numero_serie, c.nome
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

    $equipamentos = $consulta->fetchAll();

    // 4. Contador da barra lateral: pedidos ainda por entregar.
    $pedidosAtivos = (int) $pdo->query(
        'SELECT COUNT(*) FROM pedidos WHERE estado <> \'Entregue\''
    )->fetchColumn();
} catch (Throwable $erro) {
    error_log('[FixIt][Equipamentos] ' . $erro->getMessage());
    $erroDados = 'Confirma que o servidor MySQL está ligado e que a base de dados fixit foi importada.';
}

// Uma coisa é a oficina não ter equipamentos, outra é a pesquisa não
// trazer nenhum: à primeira não há pesquisa que a explique. O erro de base
// de dados fica de fora — aí quem fala é o aviso.
$oficinaVazia = $erroDados === '' && $totalGeral === 0;

/**
 * URL da lista, sempre com a página explícita, a pesquisa e a ordem que
 * estiverem ativas: a paginação e o formulário não perdem o recorte.
 */
function urlEquipamentos(int $pagina, array $extras = [], array $remover = []): string
{
    global $pesquisa, $ordemFiltro, $sentidoFiltro;

    $parametros = [];

    if ($pesquisa !== '' && !in_array('q', $remover, true)) {
        $parametros['q'] = $pesquisa;
    }

    if ($ordemFiltro !== 'cliente' && !in_array('ordem', $remover, true)) {
        $parametros['ordem'] = $ordemFiltro;
    }

    if ($sentidoFiltro !== '' && !in_array('sentido', $remover, true)) {
        $parametros['sentido'] = $sentidoFiltro;
    }

    return 'equipamentos.php?' . http_build_query(array_merge(
        $parametros,
        ['pagina' => $pagina],
        $extras
    )) . '#lista';
}

// Como cada chave se lê dentro de uma frase, e como se chamam os dois
// extremos no controlo do sentido: nomes vão de A a Z, um número vai dos
// que têm mais aos que têm menos. Os extremos dizem-se como as frases,
// para o botão e a frase não terem dois vocabulários.
$frasesOrdem = [
    'cliente' => $sentidoFiltro === 'antigos'
        ? 'pelo cliente, de Z a A'
        : 'pelo cliente, de A a Z',
    'modelo' => $sentidoFiltro === 'antigos'
        ? 'pelo modelo, de Z a A'
        : 'pelo modelo, de A a Z',
    'pedidos' => $sentidoFiltro === 'antigos'
        ? 'pelo número de pedidos, dos que têm menos para os que têm mais'
        : 'pelo número de pedidos, dos que têm mais para os que têm menos',
];

$fraseOrdem = $frasesOrdem[$ordemFiltro];

if ($ordemFiltro === 'pedidos') {
    $sentidoInicial = 'Mais pedidos';
    $sentidoInverso = 'Menos pedidos';
} else {
    $sentidoInicial = 'A–Z';
    $sentidoInverso = 'Z–A';
}

// A frase do topo diz o que se pediu — sem recorte, descreve a lista; com a
// pesquisa vazia, diz que é a pesquisa que não trouxe ninguém.
if ($oficinaVazia) {
    $descricaoTopo = 'A oficina ainda não tem equipamentos registados.';
} elseif ($totalEquipamentos === 0) {
    $descricaoTopo = 'Nenhum equipamento corresponde à pesquisa «' . $pesquisa . '».';
} elseif ($pesquisa !== '') {
    $descricaoTopo = 'Equipamentos que correspondem a «' . $pesquisa . '», ' . $fraseOrdem . '.';
} else {
    /* A frase já nomeia o número de pedidos quando é essa a ordem: repeti-lo
       no fim dizia duas vezes a mesma coisa. Nesse caso o fecho descreve o
       que a lista mostra além da chave. */
    $fecho = $ordemFiltro === 'pedidos'
        ? 'com o cliente e o número de série'
        : 'com o número de pedidos de cada equipamento';

    $descricaoTopo = 'Tudo o que já entrou na oficina, ' . $fraseOrdem . ', ' . $fecho . '.';
}

// Com a oficina vazia o título é o da secção, sem recorte: nomear uma
// pesquisa por cima de zero equipamentos prometia um recorte que não existe.
if ($oficinaVazia) {
    $tituloLista = 'Equipamentos';
} elseif ($pesquisa !== '') {
    $tituloLista = 'Equipamentos que correspondem a «' . $pesquisa . '»';
} else {
    $tituloLista = 'Todos os equipamentos';
}

// Na migalha e no separador a página é "Equipamentos"; a pesquisa
// acrescenta-se quando existe, para quem tem várias abas abertas saber
// qual está a ver.
$tituloRecorte = $pesquisa === '' || $oficinaVazia
    ? 'Equipamentos'
    : 'Equipamentos — ' . $pesquisa;
?>
<?php painelAbrir(
    'equipamentos',
    $tituloRecorte,
    'Todos os equipamentos da oficina FixIt, com o cliente de cada um e o número de pedidos.',
    [
        ['Painel', 'painel.php'],
        ['Equipamentos', null],
    ],
    $erroDados,
    false,
    $pedidosAtivos ?? -1
); ?>

<main class="principal" id="conteudo" tabindex="-1">

    <div class="cabecalho">
        <div class="cabecalho__texto">
            <h1>Equipamentos</h1>
            <p class="cabecalho__descricao"><?= h($descricaoTopo) ?></p>
        </div>

        <a class="botao botao--primario" href="pedido-novo.php">
            <svg aria-hidden="true"><use href="#icone-mais"/></svg>
            <span>Novo pedido</span>
        </a>
    </div>

    <?php if ($erroDados !== ''): ?>
        <section class="painel" aria-labelledby="titulo-erro">
            <div class="aviso aviso--erro">
                <span class="aviso__icone" aria-hidden="true">
                    <svg><use href="#icone-info"/></svg>
                </span>
                <p class="aviso__titulo" id="titulo-erro">Não foi possível carregar os equipamentos</p>
                <p class="aviso__texto"><?= h($erroDados) ?></p>
                <a class="botao botao--fantasma" href="equipamentos.php">Tentar novamente</a>
            </div>
        </section>
    <?php else: ?>

        <section class="painel" id="lista" aria-labelledby="titulo-lista">
            <div class="painel__cabecalho">
                <div>
                    <h2 id="titulo-lista"><?= h($tituloLista) ?></h2>
                    <p class="painel__descricao">
                        <?= $totalEquipamentos ?> <?= $pesquisa !== ''
                            ? ($totalEquipamentos === 1 ? 'equipamento encontrado' : 'equipamentos encontrados')
                            : ($totalEquipamentos === 1 ? 'equipamento registado' : 'equipamentos registados') ?>.
                    </p>
                </div>

                <?php /* A mesma gramática da lista de pedidos: a chave
                         escolhida e o sentido, cada um no seu grupo, e a
                         opção ativa como texto em vez de ligação para si. */ ?>
                <div class="ordenacao" role="group" aria-label="Ordenar a lista">
                    <div class="ordenacao__grupo">
                        <span class="ordenacao__rotulo">Ordenar</span>
                        <?php if ($ordemFiltro === 'cliente'): ?>
                            <span class="ordenacao__atual" aria-current="true">Cliente</span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlEquipamentos(1, [], ['ordem'])) ?>">Cliente</a>
                        <?php endif; ?>

                        <?php if ($ordemFiltro === 'modelo'): ?>
                            <span class="ordenacao__atual" aria-current="true">Modelo</span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlEquipamentos(1, ['ordem' => 'modelo'])) ?>">Modelo</a>
                        <?php endif; ?>

                        <?php if ($ordemFiltro === 'pedidos'): ?>
                            <span class="ordenacao__atual" aria-current="true">Pedidos</span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlEquipamentos(1, ['ordem' => 'pedidos'])) ?>">Pedidos</a>
                        <?php endif; ?>
                    </div>

                    <?php
                    // A chave escolhida acompanha o sentido: mudar de sentido
                    // não muda o que se está a ordenar.
                    $extrasOrdem = $ordemFiltro !== 'cliente' ? ['ordem' => $ordemFiltro] : [];
                    ?>
                    <div class="ordenacao__grupo">
                        <span class="ordenacao__rotulo">Sentido</span>
                        <?php if ($sentidoFiltro === ''): ?>
                            <span class="ordenacao__atual" aria-current="true"><?= h($sentidoInicial) ?></span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlEquipamentos(1, $extrasOrdem, ['sentido'])) ?>"><?= h($sentidoInicial) ?></a>
                        <?php endif; ?>
                        <?php if ($sentidoFiltro === 'antigos'): ?>
                            <span class="ordenacao__atual" aria-current="true"><?= h($sentidoInverso) ?></span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlEquipamentos(1, array_merge($extrasOrdem, ['sentido' => 'antigos']))) ?>"><?= h($sentidoInverso) ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php /* A barra leva só a pesquisa: a chave e o sentido vão
                     escondidos, para filtrar não os perder. */ ?>
            <form class="filtros filtros--pesquisa" method="get" action="equipamentos.php#lista"
                  role="search" aria-label="Pesquisar equipamentos">
                <?php if ($ordemFiltro !== 'cliente'): ?>
                    <input type="hidden" name="ordem" value="<?= h($ordemFiltro) ?>">
                <?php endif; ?>

                <?php if ($sentidoFiltro !== ''): ?>
                    <input type="hidden" name="sentido" value="<?= h($sentidoFiltro) ?>">
                <?php endif; ?>

                <div class="campo filtros__campo filtros__campo--pesquisa">
                    <label class="campo__rotulo" for="filtro-q">Equipamento, série ou cliente</label>
                    <input class="entrada" type="search" id="filtro-q" name="q"
                           value="<?= h($pesquisa) ?>" placeholder="Modelo, série ou cliente"
                           autocomplete="off">
                </div>

                <div class="filtros__acoes">
                    <button class="botao botao--primario" type="submit">Pesquisar</button>
                    <?php if ($pesquisa !== ''): ?>
                        <a class="botao botao--fantasma" href="equipamentos.php#lista">Limpar</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if ($equipamentos): ?>
                <div class="tabela__contentor" id="resultados">
                    <table class="tabela">
                        <caption class="visualmente-oculto">
                            Equipamentos da oficina, página <?= $pagina ?> de <?= $totalPaginas ?>,
                            ordenados <?= h($fraseOrdem) ?>, com tipo, cliente, número de
                            série, número de pedidos e ligação para ver os pedidos de
                            cada máquina.
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Equipamento</th>
                                <th scope="col">Cliente</th>
                                <th scope="col">Nº de série</th>
                                <th scope="col">Pedidos</th>
                                <th scope="col" class="tabela__acao">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipamentos as $equipamento): ?>
                                <?php
                                $pedidosDoEquipamento = (int) $equipamento['total_pedidos'];
                                $serie                = (string) ($equipamento['numero_serie'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <span class="equipamento">
                                            <span class="equipamento__modelo"><?= h($equipamento['marca_modelo']) ?></span>
                                            <span class="equipamento__tipo"><?= h($equipamento['tipo']) ?></span>
                                        </span>
                                    </td>
                                    <td class="tabela__cliente"><?= h($equipamento['cliente_nome']) ?></td>
                                    <td class="tabela__serie">
                                        <?php if ($serie !== ''): ?>
                                            <?= h($serie) ?>
                                        <?php else: ?>
                                            —<span class="visualmente-oculto"> sem número de série</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tabela__numero">
                                        <?= $pedidosDoEquipamento ?>
                                        <span class="visualmente-oculto">
                                            <?= $pedidosDoEquipamento === 1 ? 'pedido' : 'pedidos' ?>
                                        </span>
                                    </td>
                                    <td class="tabela__acao">
                                        <?php if ($pedidosDoEquipamento > 0): ?>
                                            <a class="ligacao-acao"
                                               href="pedidos.php?equipamento=<?= (int) $equipamento['id'] ?>#lista"
                                               aria-label="Ver os pedidos de <?= h($equipamento['cliente_nome']) ?> — <?= h($equipamento['marca_modelo']) ?>">
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
                        · <?= $totalEquipamentos ?> <?= $totalEquipamentos === 1 ? 'equipamento' : 'equipamentos' ?>
                    </span>

                    <div class="paginacao__botoes">
                        <?php if ($pagina > 1): ?>
                            <a class="botao botao--fantasma botao--pequeno"
                               href="<?= h(urlEquipamentos($pagina - 1)) ?>" rel="prev">
                                Anterior
                            </a>
                        <?php else: ?>
                            <span class="botao botao--fantasma botao--pequeno" aria-disabled="true">Anterior</span>
                        <?php endif; ?>

                        <?php if ($pagina < $totalPaginas): ?>
                            <a class="botao botao--fantasma botao--pequeno"
                               href="<?= h(urlEquipamentos($pagina + 1)) ?>" rel="next">
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
                        <svg><use href="#icone-equipamentos"/></svg>
                    </span>
                    <p class="aviso__titulo">Ainda não há equipamentos registados</p>
                    <p class="aviso__texto">O equipamento entra na oficina com o primeiro
                        pedido de assistência.</p>
                    <a class="botao botao--primario" href="pedido-novo.php">
                        <svg aria-hidden="true"><use href="#icone-mais"/></svg>
                        <span>Novo pedido</span>
                    </a>
                </div>
            <?php else: ?>
                <?php /* Recorte sem resultados: a pesquisa é a causa, e é por
                         ela que o cartão fala — o da oficina vazia diria que
                         não há equipamentos, o que é falso. */ ?>
                <div class="aviso">
                    <span class="aviso__icone" aria-hidden="true">
                        <svg><use href="#icone-equipamentos"/></svg>
                    </span>
                    <p class="aviso__titulo">Nenhum equipamento corresponde à pesquisa «<?= h($pesquisa) ?>»</p>
                    <p class="aviso__texto">A pesquisa procura no modelo, no tipo, no
                        número de série e no nome do cliente.</p>
                    <?php /* Limpa só a pesquisa: a ordem escolhida não é
                             o que trouxe ninguém, e quem a mudou não a quer
                             perder por ter procurado mal. */ ?>
                    <a class="botao botao--fantasma" href="<?= h(urlEquipamentos(1, [], ['q'])) ?>">
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
   do conteúdo parados e anima só a tabela dos equipamentos, em vez de a página
   inteira atravessar para trocar vinte linhas.

   A marca é posta no arranque da página que entra, e não na que sai: o
   `pageswap` também aceita tipos, mas só a partir do `pagereveal` é que a
   transição os tem em conta nas duas metades. Para saber de onde se veio,
   o endereço da última visita a esta lista fica no sessionStorage — é a
   única memória que atravessa documentos da mesma aba.
   ===================================================================== */
(function () {
    "use strict";

    var CHAVE = "fixit:equipamentos-de-onde";
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
