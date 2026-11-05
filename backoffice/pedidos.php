<?php
declare(strict_types=1);

/**
 * pedidos.php — Lista completa das ordens de serviço, com paginação e a
 * ação de mudar estado partilhada com o painel (estado-pedido.php).
 *
 * Estrutura:
 *   1. Sessão, ligação e ação de mudança de estado (?alterar=ID).
 *   2. Contagem, pedidos da página pedida e limites da paginação.
 *   3. HTML + PHP de apresentação.
 *
 * Requisitos: PHP 8.0+, pdo_mysql e a base de dados fixit.
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/estado-pedido.php';
require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/layout-painel.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';

// A área da oficina exige sessão: sem login válido, Sessao::exigir()
// manda para o login.php antes de qualquer consulta. A mesma sessão
// guarda o token CSRF da ação e a confirmação que sobrevive ao
// redirecionamento (POST → Redirect → GET).
Sessao::iniciar();

/** @var array{id: int, nome: string, email: string, cargo: string} $utilizador */
$utilizador = Sessao::exigir();

// Contador do menu: quantos pedidos do site estão por contactar.
$solicitacoesNovas = solicitacoesNovas();

const POR_PAGINA = 20;

// Mais de uma semana — na oficina ou sem o cliente abrir o PIN. O mesmo
// limite do painel, e também estrito: sete dias certos ainda contam como
// espera normal.
const LIMITE_ESPERA_LONGA = 7;

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

/**
 * Data e hora curtas, para células densas: "24 out, 23:50". O ano só
 * aparece quando não é o atual — a lista é de leitura rápida e um ano
 * repetido em todas as linhas não acrescenta.
 */
function dataHoraCurta(?string $dataHora): string
{
    if ($dataHora === null || $dataHora === '') {
        return '—';
    }

    $momento = strtotime($dataHora);

    if ($momento === false) {
        return '—';
    }

    $data    = dataCurta($dataHora);
    $anoAtual = date('Y');

    if (str_ends_with($data, ' ' . $anoAtual)) {
        $data = substr($data, 0, -strlen($anoAtual) - 1);
    }

    return $data . ', ' . date('H:i', $momento);
}

/**
 * Dias de calendário desde um momento — a diferença entre datas, não entre
 * instantes, como o DATEDIFF do painel. Uma data no futuro (relógio
 * adiantado) conta como hoje. Null quando não há data legível.
 */
function diasDesde(?string $dataHora): ?int
{
    if ($dataHora === null || $dataHora === '') {
        return null;
    }

    $momento = strtotime($dataHora);

    if ($momento === false) {
        return null;
    }

    $dias = (int) round(
        (strtotime('today') - strtotime(date('Y-m-d', $momento))) / 86400
    );

    return max(0, $dias);
}

/**
 * Há quanto tempo foi um momento, na unidade que se lê melhor: "hoje",
 * "há 12 dias" até um mês, "há 3 meses" até um ano e "há 2 anos" a partir
 * daí. Até um mês a idade fica em dias — é a unidade em que a oficina age
 * (a semana do âmbar) e a que os avisos comparam —, e um "há 0 meses"
 * nunca aparece. Os meses contam-se no calendário, não a dividir por 30:
 * de 5 de março a 5 de abril vai um mês, com 31 dias ou com 28.
 */
function haTempo(?string $dataHora): string
{
    $dias = diasDesde($dataHora);

    if ($dias === null) {
        return '—';
    }

    if ($dias === 0) {
        return 'hoje';
    }

    if ($dias <= 30) {
        return 'há ' . $dias . ($dias === 1 ? ' dia' : ' dias');
    }

    $momento = strtotime(date('Y-m-d', strtotime((string) $dataHora)));
    $decorrido = (new DateTimeImmutable('today'))->diff(new DateTimeImmutable(date('Y-m-d', $momento)));
    $meses = $decorrido->y * 12 + $decorrido->m;

    if ($meses < 12) {
        return 'há ' . $meses . ($meses === 1 ? ' mês' : ' meses');
    }

    $anos = intdiv($meses, 12);

    return 'há ' . $anos . ($anos === 1 ? ' ano' : ' anos');
}

/**
 * Início da janela do PIN atual de um pedido: a última emissão de PIN, ou a
 * entrada num pedido anterior ao histórico. É o momento desde o qual o PIN
 * espera e, num pedido que ninguém consultou, a única data que a coluna tem
 * para mostrar. Escrito uma vez para a coluna, os filtros e a ordenação
 * medirem todos a mesma espera.
 */
function sqlInicioJanelaPin(): string
{
    return 'COALESCE(
                (SELECT MAX(h.registado_em)
                   FROM historico_pins h
                  WHERE h.pedido_id = p.id),
                p.data_entrada)';
}

/**
 * Fecho da janela das consultas: num pedido entregue, a conta para na
 * entrega — pedidos.data_conclusao, escrita quando o estado passa a
 * Entregue. É a mesma régua da ficha do pedido: o que interessa é o que o
 * cliente viu enquanto o equipamento estava na oficina, e uma consulta
 * feita depois de sair não é acompanhamento do trabalho.
 *
 * Um entregue sem data de entrega (linha antiga ou inserida à mão) fica
 * sem fecho, como na ficha: sem data não se afirma nada sobre ela.
 */
function sqlFechoJanelaPin(): string
{
    return ' AND (p.data_conclusao IS NULL OR c.registado_em <= p.data_conclusao)';
}

/**
 * Subconsulta que conta as consultas públicas feitas com o PIN atual de um
 * pedido, até à entrega quando ela existe.
 */
function sqlConsultasPinAtual(): string
{
    return '(SELECT COUNT(*)
               FROM consultas_publicas c
              WHERE c.pedido_id = p.id
                AND c.registado_em >= ' . sqlInicioJanelaPin()
                . sqlFechoJanelaPin() . ')';
}

/**
 * Data da última consulta pública com o PIN atual de cada pedido, na mesma
 * janela da contagem. Sem consultas, o pedido não tem data (NULL) e uma
 * ordenação descendente deixa-o no fim.
 */
function sqlUltimaConsultaPin(): string
{
    return '(SELECT MAX(c.registado_em)
               FROM consultas_publicas c
              WHERE c.pedido_id = p.id
                AND c.registado_em >= ' . sqlInicioJanelaPin()
                . sqlFechoJanelaPin() . ')';
}

/**
 * Data em que a espera do PIN atual começou: a última consulta do cliente
 * ou, num pedido que ninguém consultou, a emissão do PIN. Nunca é nula —
 * um pedido tem sempre uma entrada —, e é por isso que a ordenação pela
 * espera não precisa de mandar os sem consulta para o fim: ordenam-se pela
 * idade do próprio PIN, que é o que o cliente tem por abrir.
 */
function sqlInicioEsperaPin(): string
{
    return 'COALESCE(' . sqlUltimaConsultaPin() . ', ' . sqlInicioJanelaPin() . ')';
}

/**
 * Dias de calendário que o PIN de um pedido espera: desde a última consulta
 * ou, se o cliente nunca o abriu, desde a emissão. É a régua do recorte dos
 * esquecidos e a mesma que a chave da espera ordena.
 */
function sqlDiasSemAbrirPin(): string
{
    return 'DATEDIFF(CURDATE(), DATE(' . sqlInicioEsperaPin() . '))';
}

/**
 * URL da lista, sempre com a página explícita e os filtros ativos.
 * Assim a paginação, o formulário de estado e o redirect de sucesso
 * não perdem o contexto.
 */
function urlPedidos(int $pagina, array $extras = [], array $remover = []): string
{
    global $filtrosAtivos, $ordemFiltro, $sentidoFiltro;

    $filtros = $filtrosAtivos;

    foreach ($remover as $chave) {
        unset($filtros[$chave]);
    }

    // A ordenação não é um filtro — não muda o título nem a contagem — mas
    // tem de acompanhar a paginação e os links de estado. Sai da URL quando
    // se pede a ordem por omissão.
    if ($ordemFiltro !== 'estado' && !in_array('ordem', $remover, true)) {
        $filtros['ordem'] = $ordemFiltro;
    }

    // O sentido vale para qualquer chave de ordenação, por isso viaja
    // sozinho.
    if ($sentidoFiltro !== '' && !in_array('sentido', $remover, true)) {
        $filtros['sentido'] = $sentidoFiltro;
    }

    return 'pedidos.php?' . http_build_query(array_merge(
        $filtros,
        ['pagina' => $pagina],
        $extras
    ));
}

// ---------------------------------------------------------------------
// Ciclo de vida do pedido, na ordem do ENUM da base de dados.
// ---------------------------------------------------------------------
$etiquetasEstado = [
    'Recebido'       => 'Recebido',
    'Em Diagnostico' => 'Em diagnóstico',
    'Em Reparacao'   => 'Em reparação',
    'Pronto'         => 'Pronto',
    'Entregue'       => 'Entregue',
];

$classesEstado = [
    'Recebido'       => 'estado--recebido',
    'Em Diagnostico' => 'estado--diagnostico',
    'Em Reparacao'   => 'estado--reparacao',
    'Pronto'         => 'estado--pronto',
    'Entregue'       => 'estado--entregue',
];

// Opções do filtro por estado. Além dos cinco valores do ENUM, "ativos"
// agrupa os pedidos ainda por entregar — o mesmo conjunto que o cartão
// "Pedidos ativos" conta no painel. É só um valor de filtro: nunca é
// gravado em `pedidos`.
$estadosFiltro = ['ativos' => 'Por entregar'] + $etiquetasEstado;

// Como cada valor do filtro se lê dentro de uma frase sobre ordens de
// serviço ("As ordens de serviço em reparação"). O rótulo do select serve
// para a opção, não para a frase: "Recebido" sozinho não concorda com
// "ordens".
$qualificadoresEstado = [
    'ativos'         => 'por entregar',
    'Recebido'       => 'recém-recebidas',
    'Em Diagnostico' => 'em diagnóstico',
    'Em Reparacao'   => 'em reparação',
    'Pronto'         => 'prontas',
    'Entregue'       => 'entregues',
];

// Confirmação deixada pelo POST antes do redirecionamento.
$sucessoAcao = AcaoEstadoPedido::consumirFlash();

// Página pedida. O formulário submete para a mesma página (query string).
$paginaPedida = $_GET['pagina'] ?? null;

$pagina = is_string($paginaPedida)
    && ctype_digit($paginaPedida)
    && (int) $paginaPedida > 0
        ? (int) $paginaPedida
        : 1;

// Filtros da lista (GET). O termo de pesquisa é escapado no LIKE; o
// estado e o técnico só entram na consulta depois de validados.
$estadoFiltro = $_GET['estado'] ?? null;
$estadoFiltro = is_string($estadoFiltro) && isset($estadosFiltro[$estadoFiltro])
    ? $estadoFiltro
    : '';

// O filtro do técnico aceita dois tipos de valor: o id de um técnico ou
// "sem", o conjunto dos pedidos ainda por atribuir. Um id que já não
// exista é descartado mais abaixo, depois de confirmado na base.
$tecnicoPedido = $_GET['tecnico'] ?? null;

$tecnicoSemAtribuir = is_string($tecnicoPedido) && $tecnicoPedido === 'sem';

$tecnicoFiltro = is_string($tecnicoPedido) && ctype_digit($tecnicoPedido)
    ? (int) $tecnicoPedido
    : 0;

$pesquisa = $_GET['q'] ?? null;
$pesquisa = is_string($pesquisa) ? trim($pesquisa) : '';

// Filtro de consultas ao PIN atual: os pedidos que o cliente já abriu
// ("com"), os que ainda ninguém consultou ("sem") e os que o cliente não
// abre há mais de uma semana ("esquecido") — este último o mesmo estado
// que a coluna das consultas assinala a âmbar, e a que a própria idade
// marcada leva. Depende das tabelas das migrações e é descartado mais
// abaixo se elas não existirem.
$consultasFiltro = $_GET['consultas'] ?? null;
$consultasFiltro = is_string($consultasFiltro) && in_array($consultasFiltro, ['com', 'sem', 'esquecido'], true)
    ? $consultasFiltro
    : '';

// Ordenação da lista: estado do ciclo de vida (por omissão), entrada,
// última consulta ou espera do PIN. As duas últimas dependem das tabelas
// das migrações e são descartadas mais abaixo se elas não existirem. A
// chave por omissão não vai no URL (ver urlPedidos): quem lista pedidos
// quer ver primeiro o que está por fazer, não o que entrou mais
// recentemente.
$chavesOrdem = ['entrada', 'estado', 'consulta', 'espera'];

$ordemPedida = $_GET['ordem'] ?? null;
$ordemFiltro = is_string($ordemPedida) && in_array($ordemPedida, $chavesOrdem, true)
    ? $ordemPedida
    : 'estado';

// Sentido da ordenação: mais recentes primeiro (por omissão) ou mais
// antigos primeiro. Vale para as duas chaves — entrada e última consulta —
// e por isso não depende de `ordem`.
$sentidoFiltro = $_GET['sentido'] ?? null;
$sentidoFiltro = is_string($sentidoFiltro) && $sentidoFiltro === 'antigos'
    ? 'antigos'
    : '';

// Chave de ordenação que veio no URL e foi substituída porque não se aplica
// ao filtro ativo (ver a regra abaixo). Fica a chave pedida, para a lista
// poder dizer o que aconteceu em vez de parecer que o link falhou.
$ordemSubstituida = null;

// O filtro dos esquecidos tem uma ordem só: a espera do PIN, do mais antigo
// para o mais recente. É a chave que mede o que o recorte define — os dias
// que o cliente não abre o talão —, a que a idade marcada na coluna leva e a
// que os atalhos do painel e da barra pedem. O controlo da ordenação já não
// oferece a chave da consulta nesse recorte; aqui fecha-se a outra porta: um
// `ordem=consulta` que chegue no URL é ignorado, em vez de abrir a mesma
// lista por uma segunda ordem. O sentido acompanha a chave caída — um
// `consulta` que passasse a `espera` com o sentido que trazia (ou sem nenhum)
// abriria a espera mais curta primeiro, o contrário do que o recorte promete.
// As chaves da entrada e do estado continuam a valer: ordenam o mesmo
// conjunto por outro eixo, e não contradizem os dias que ele mostra.
if ($consultasFiltro === 'esquecido' && $ordemFiltro === 'consulta') {
    $ordemSubstituida = 'consulta';
    $ordemFiltro      = 'espera';
    $sentidoFiltro    = 'antigos';
}

// Filtro de espera: os pedidos ainda em aberto há mais de uma semana
// ("longa"), que é o atalho do painel. Não tem select na barra: chega pela
// ligação e mostra-se como chip, que se pode remover.
//
// Houve um segundo valor, "pin", para os que o cliente nunca consultou e
// cujo PIN já esperava o mesmo — um recorte mais estreito do que o dos
// esquecidos. Deixou de ter entrada no painel, que passou a apontar ao dos
// esquecidos para o número bater com o da barra de filtros, e saiu com
// ela: um parâmetro que já ninguém gera só servia para prometer um filtro
// que não existe. Agora `espera` conhece "longa" e ignora o resto.
$esperaPedida = $_GET['espera'] ?? null;
$esperaLonga = is_string($esperaPedida) && $esperaPedida === 'longa';

// Filtro por equipamento. Não há select (uma oficina tem centenas de
// máquinas): o id chega da lista de Equipamentos e é confirmado na base
// de dados antes de entrar na consulta.
$equipamentoFiltro = $_GET['equipamento'] ?? null;
$equipamentoFiltro = is_string($equipamentoFiltro) && ctype_digit($equipamentoFiltro)
    ? (int) $equipamentoFiltro
    : 0;

// Linha do equipamento (modelo, série e cliente), preenchida no try.
$equipamentoAtivo = null;

// Preenchido no try, depois de validar o técnico: é o conjunto que os
// links da página (paginação, ação, cancelar) transportam.
$filtrosAtivos = [];

$tecnicosFiltro      = [];
$tecnicosFiltroPorId = [];

$acaoEstado   = [];
$pedidoAcao   = null;
$errosAcao    = [];
$pedidos      = [];
// Só há coluna de consultas quando as tabelas das migrações existem.
$consultasDisponiveis = false;
// O mesmo para a idade dos pedidos por atribuir: sem a tabela das trocas de
// técnico, a célula fica só com o select, como antes.
$historicoTecnicosDisponivel = false;
// Resumo da barra de filtros: consultados e por consultar, calculados no try.
$resumoConsultados  = 0;
$resumoPorConsultar = 0;
// E, na mesma linha, os esquecidos: os que o cliente não abre o PIN há mais
// de uma semana. Não é um terceiro lado da mesma soma — é o recorte
// atrasado que atravessa os dois, porque tanto pode ser um pedido já
// consultado como um por consultar —, e por isso se conta à parte.
$resumoEsquecidos   = 0;
// Contagem por estado, na ordem do ciclo de vida do pedido (tudo a zero
// até a consulta correr).
$resumoEstados = array_fill_keys(array_keys($etiquetasEstado), 0);
// Contagem por técnico, preenchida no try (id => total).
$resumoTecnicos = [];
// Pedidos ainda sem técnico, no mesmo resumo (o grupo NULL da contagem).
// Quantos pedidos existem na oficina, todos os estados e sem filtros: é o
// que distingue uma oficina vazia de um recorte sem resultados.
$totalGeral = 0;
$resumoSemTecnico = 0;
$totalPedidos = 0;
$pedidosAtivos = 0;
$totalPaginas = 1;
$erroDados    = '';
// Legenda da ordem da tabela, escrita no try conforme a chave escolhida.
$descricaoOrdem = '';
// Falha ao atribuir técnico a partir da lista, mostrada acima da tabela.
$erroAtribuicao = '';

try {
    $pdo = obterConexao();

    // As tabelas do histórico de PINs e das consultas vêm de migrações e
    // podem não existir. Uma sonda só, antes de montar filtros: sem elas a
    // lista esconde a coluna e o filtro em vez de falhar.
    try {
        $pdo->query('SELECT 1 FROM historico_pins LIMIT 1');
        $pdo->query('SELECT 1 FROM consultas_publicas LIMIT 1');
        $consultasDisponiveis = true;
    } catch (PDOException $excecao) {
        error_log('[FixIt][Pedidos][Consultas] ' . $excecao->getMessage());
    }

    // Sonda à parte: a tabela das trocas é de outra migração, e a falta dela
    // não pode levar consigo a coluna das consultas.
    try {
        $pdo->query('SELECT 1 FROM historico_tecnicos LIMIT 1');
        $historicoTecnicosDisponivel = true;
    } catch (PDOException $excecao) {
        error_log('[FixIt][Pedidos][Histórico técnicos] ' . $excecao->getMessage());
    }

    if (!$consultasDisponiveis) {
        // Só as ordenações por consulta e pela espera do PIN precisam das
        // migrações; a da entrada, a do estado e o sentido continuam a
        // valer sem elas.
        $consultasFiltro = '';

        if ($ordemFiltro === 'consulta' || $ordemFiltro === 'espera') {
            $ordemFiltro = 'estado';
        }

        // Sem as tabelas também não há espera do PIN para onde cair: a nota
        // da ordem substituída não teria uma ordem para anunciar, e a página
        // já explica a falta das colunas de outra maneira.
        $ordemSubstituida = null;
    }

    // Como está ordenada a lista, para a legenda da tabela: quatro chaves
    // e dois sentidos davam oito frases espalhadas por ternários.
    if ($ordemFiltro === 'estado') {
        $descricaoOrdem = $sentidoFiltro === 'antigos'
            ? 'ordenados pelo estado, do fim do ciclo de vida para o início, '
            : 'ordenados pelo estado, do início do ciclo de vida para o fim, ';
    } elseif ($ordemFiltro === 'consulta') {
        $descricaoOrdem = $sentidoFiltro === 'antigos'
            ? 'ordenados pela data da última consulta, da mais antiga para a mais recente, '
            : 'ordenados pela data da última consulta, da mais recente para a mais antiga, ';
    } elseif ($ordemFiltro === 'espera') {
        // «da mais antiga para a mais recente», como nos atalhos: a espera
        // do PIN mede-se pela idade do talão por abrir, e é assim que o
        // resumo da lista e o painel a anunciam.
        $descricaoOrdem = $sentidoFiltro === 'antigos'
            ? 'ordenados pela espera do PIN, da mais antiga para a mais recente, '
            : 'ordenados pela espera do PIN, da mais recente para a mais antiga, ';
    } elseif ($sentidoFiltro === 'antigos') {
        $descricaoOrdem = 'ordenados pela data de entrada, da mais antiga para a mais recente, ';
    } else {
        $descricaoOrdem = 'ordenados pela data de entrada, da mais recente para a mais antiga, ';
    }

    // 1. Opções do filtro por técnico. Um técnico que já não exista
    //    deixa de filtrar, em vez de esconder todos os pedidos.
    $consulta = $pdo->prepare('SELECT id, nome FROM utilizadores ORDER BY nome');
    $consulta->execute();
    $tecnicosFiltro = $consulta->fetchAll();

    foreach ($tecnicosFiltro as $tecnico) {
        $tecnicosFiltroPorId[(int) $tecnico['id']] = (string) $tecnico['nome'];
    }

    if ($tecnicoFiltro > 0 && !isset($tecnicosFiltroPorId[$tecnicoFiltro])) {
        $tecnicoFiltro = 0;
    }

    // Equipamento: um id antigo ou apagado deixa de filtrar, em vez de
    // esconder todos os pedidos.
    if ($equipamentoFiltro > 0) {
        $consulta = $pdo->prepare(
            'SELECT e.id, e.marca_modelo, e.numero_serie,
                    c.nome AS cliente_nome
               FROM equipamentos e
         INNER JOIN clientes c ON c.id = e.cliente_id
              WHERE e.id = ?
              LIMIT 1'
        );
        $consulta->execute([$equipamentoFiltro]);

        $equipamentoAtivo = $consulta->fetch() ?: null;

        if ($equipamentoAtivo === null) {
            $equipamentoFiltro = 0;
        }
    }

    if ($pesquisa !== '') {
        $filtrosAtivos['q'] = $pesquisa;
    }

    if ($estadoFiltro !== '') {
        $filtrosAtivos['estado'] = $estadoFiltro;
    }

    if ($tecnicoSemAtribuir) {
        $filtrosAtivos['tecnico'] = 'sem';
    } elseif ($tecnicoFiltro > 0) {
        $filtrosAtivos['tecnico'] = (string) $tecnicoFiltro;
    }

    if ($equipamentoFiltro > 0) {
        $filtrosAtivos['equipamento'] = (string) $equipamentoFiltro;
    }

    if ($consultasFiltro !== '') {
        $filtrosAtivos['consultas'] = $consultasFiltro;
    }

    if ($esperaLonga) {
        $filtrosAtivos['espera'] = 'longa';
    }

    // Destino do atalho da idade marcada na coluna das consultas: os
    // esquecidos, ordenados pela espera do PIN mais longa primeiro. É a
    // própria idade que a coluna assinala a âmbar, posta por ordem — quem
    // espera há mais tempo em cima —, e a mesma chave (e o mesmo sentido)
    // com que o atalho dos esquecidos do painel abre a lista, para a idade
    // marcada aí levar ao mesmo sítio. A ordenação viaja no URL, por isso o
    // controlo da ordenação mostra-a e continua a poder trocar-se.
    $linkEsquecidos = urlPedidos(1, [
        'consultas' => 'esquecido',
        'ordem'     => 'espera',
        'sentido'   => 'antigos',
    ]) . '#lista';

    // Destino do atalho da idade cinzenta: os entregues com o PIN por
    // abrir, pela espera mais antiga. É o mesmo recorte e a mesma ordem do
    // atalho do painel que os conta, e do lado dos entregues na barra de
    // filtros, para a pílula levar ao sítio que já promete.
    $linkEntreguesPin = urlPedidos(1, [
        'estado'    => 'Entregue',
        'consultas' => 'sem',
        'ordem'     => 'espera',
        'sentido'   => 'antigos',
    ]) . '#lista';

    // 2. Atribuição de técnico a partir da lista, um POST pequeno por
    //    linha. Mesmo padrão da mudança de estado: token CSRF da sessão e
    //    POST → Redirect → GET, para um refresh não repetir a atribuição.
    //    Um valor vazio no select quer dizer "por atribuir" (NULL).
    if (($_POST['acao'] ?? null) === 'atribuir_tecnico') {
        $pedidoAtribuir = $_POST['pedido_id'] ?? null;
        $pedidoAtribuir = is_string($pedidoAtribuir) && ctype_digit($pedidoAtribuir)
            ? (int) $pedidoAtribuir
            : 0;

        $tecnicoEnviado = $_POST['tecnico_id'] ?? null;
        $tecnicoEnviado = is_string($tecnicoEnviado) ? $tecnicoEnviado : '';

        $tecnicoNovo = ctype_digit($tecnicoEnviado) && (int) $tecnicoEnviado > 0
            ? (int) $tecnicoEnviado
            : 0;

        $tokenAtribuir = $_POST['csrf'] ?? null;

        if (!Sessao::csrfValido(is_string($tokenAtribuir) ? $tokenAtribuir : null)) {
            $erroAtribuicao = 'A sessão expirou. Recarrega a página e tenta novamente.';
        } elseif ($pedidoAtribuir <= 0) {
            $erroAtribuicao = 'Não foi possível identificar o pedido a atribuir.';
        } elseif ($tecnicoNovo > 0 && !isset($tecnicosFiltroPorId[$tecnicoNovo])) {
            $erroAtribuicao = 'Escolhe um técnico da lista.';
        } else {
            $consulta = $pdo->prepare(
                'SELECT id, tecnico_id FROM pedidos WHERE id = ? LIMIT 1'
            );
            $consulta->execute([$pedidoAtribuir]);
            $pedidoAtribuirLinha = $consulta->fetch();

            if (!is_array($pedidoAtribuirLinha)) {
                $erroAtribuicao = 'O pedido #' . $pedidoAtribuir . ' já não existe.';
            } else {
                $tecnicoAtual = (int) ($pedidoAtribuirLinha['tecnico_id'] ?? 0);

                if ($tecnicoAtual === $tecnicoNovo) {
                    $erroAtribuicao = $tecnicoNovo > 0
                        ? 'O pedido #' . $pedidoAtribuir . ' já está atribuído a '
                            . $tecnicosFiltroPorId[$tecnicoNovo] . '.'
                        : 'O pedido #' . $pedidoAtribuir . ' já está por atribuir.';
                } else {
                    // Só a coluna do técnico: o estado e o histórico dos
                    // estados ficam onde estão (o trigger só escreve
                    // quando o estado muda). A troca e a linha de
                    // historico_tecnicos são uma só transação, para o
                    // registo não sobreviver a um UPDATE falhado.
                    $gravou = true;

                    try {
                        $pdo->beginTransaction();

                        $atualizar = $pdo->prepare(
                            'UPDATE pedidos SET tecnico_id = ? WHERE id = ?'
                        );
                        $atualizar->execute([
                            $tecnicoNovo > 0 ? $tecnicoNovo : null,
                            $pedidoAtribuir,
                        ]);

                        // Opcional como o resto do histórico: numa base
                        // anterior à migração a tabela não existe e a
                        // atribuição não é bloqueada — fica sem registo.
                        try {
                            $registar = $pdo->prepare(
                                'INSERT INTO historico_tecnicos
                                     (pedido_id, tecnico_anterior, tecnico_novo, autor_id)
                                 VALUES (?, ?, ?, ?)'
                            );
                            $registar->execute([
                                $pedidoAtribuir,
                                $tecnicoAtual > 0 ? $tecnicoAtual : null,
                                $tecnicoNovo > 0 ? $tecnicoNovo : null,
                                (int) $utilizador['id'],
                            ]);
                        } catch (PDOException $excecao) {
                            error_log('[FixIt][Pedidos][Histórico técnicos] ' . $excecao->getMessage());
                        }

                        $pdo->commit();
                    } catch (Throwable $erroGravacao) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        error_log('[FixIt][Pedidos][Atribuir] ' . $erroGravacao->getMessage());
                        $erroAtribuicao = 'Não foi possível guardar a atribuição. Tenta novamente.';
                        $gravou = false;
                    }

                    if ($gravou) {
                        AcaoEstadoPedido::guardarFlash(
                            'Pedido #' . $pedidoAtribuir . ' '
                            . ($tecnicoNovo > 0
                                ? 'atribuído a ' . $tecnicosFiltroPorId[$tecnicoNovo]
                                : 'ficou por atribuir')
                            . '.'
                        );

                        header('Location: ' . urlPedidos($pagina) . '#lista', true, 303);
                        exit;
                    }
                }
            }
        }
    }

    // 3. Ação de mudança de estado. Em caso de sucesso grava e
    //    redireciona para a mesma página e filtros; em caso de erro
    //    devolve o estado do formulário para a apresentação.
    $acaoEstado = AcaoEstadoPedido::preparar(
        $pdo,
        $etiquetasEstado,
        urlPedidos($pagina) . '#lista'
    );
    $pedidoAcao = $acaoEstado['pedido'];
    $errosAcao  = $acaoEstado['erros'];

    // 4. Condições comuns à contagem e à página. Estado e técnico ficam
    //    em pares à parte: cada resumo da barra conta sem o seu próprio
    //    filtro, para os números se lerem em conjunto mesmo depois de se
    //    escolher um deles.
    $condicoesEstado  = [];
    $parametrosEstado = [];

    if ($estadoFiltro === 'ativos') {
        $condicoesEstado[] = "p.estado <> 'Entregue'";
    } elseif ($estadoFiltro !== '') {
        $condicoesEstado[] = 'p.estado = ?';
        $parametrosEstado[] = $estadoFiltro;
    }

    $condicoesTecnico  = [];
    $parametrosTecnico = [];

    if ($tecnicoSemAtribuir) {
        // Sem técnico é NULL na coluna: a chave estrangeira não deixa
        // gravar outro valor vazio, e um técnico eliminado também deixa
        // NULL (ON DELETE SET NULL).
        $condicoesTecnico[] = 'p.tecnico_id IS NULL';
    } elseif ($tecnicoFiltro > 0) {
        $condicoesTecnico[] = 'p.tecnico_id = ?';
        $parametrosTecnico[] = $tecnicoFiltro;
    }

    // Restantes filtros: equipamento, espera longa e pesquisa.
    $condicoes  = [];
    $parametros = [];

    // Espera longa: só pedidos em aberto contam, porque um entregue já
    // saiu da oficina. O limite vem da constante, não do URL.
    if ($esperaLonga) {
        $condicoes[] = "p.estado <> 'Entregue'";
        $condicoes[] = 'DATEDIFF(CURDATE(), DATE(p.data_entrada)) > ' . LIMITE_ESPERA_LONGA;
    }

    if ($equipamentoFiltro > 0) {
        $condicoes[] = 'p.equipamento_id = ?';
        $parametros[] = $equipamentoFiltro;
    }

    // Consultas ao PIN emitido agora, na mesma janela da coluna — a mesma
    // da ficha: num entregue a conta fecha na entrega. "sem" deixa os
    // ainda não consultados; "com", os que já o foram. Um pedido cujo PIN
    // foi trocado volta a contar do zero.
    //
    // "esquecido" deixa os que o cliente não abre há mais de uma semana:
    // a mesma régua do marca a âmbar na coluna — dias de calendário, como
    // o DATEDIFF e o diasDesde() —, para a lista filtrada ser exatamente o
    // conjunto das linhas marcadas, nem mais nem menos. Só pedidos em
    // aberto, porque a marca não aparece nos entregues.
    //
    // A régua mede a partir da última consulta e, quando o cliente nunca
    // abriu o PIN, desde a emissão dele: quem nunca abriu espera desde que
    // o PIN saiu, e um PIN renovado ontem volta a contar do zero.
    //
    // Fica à parte até ao fim: o resumo da barra conta os dois lados e só
    // pode fazê-lo com as restantes condições, sem esta.
    $condicaoConsultas = '';

    if ($consultasFiltro === 'esquecido') {
        $condicaoConsultas = "(p.estado <> 'Entregue'
                              AND " . sqlDiasSemAbrirPin() . ' > '
            . LIMITE_ESPERA_LONGA . ') ';
    } elseif ($consultasFiltro !== '') {
        $condicaoConsultas = sqlConsultasPinAtual()
            . ($consultasFiltro === 'com' ? ' > 0' : ' = 0');
    }

    if ($pesquisa !== '') {
        // O mesmo campo procura no nome e no NIF. Os curingas do LIKE
        // que o utilizador escreva (%, _) são literais; no NIF procura-se
        // também a versão só com algarismos, para "123 456 789"
        // encontrar 123456789.
        $termo   = '%' . addcslashes($pesquisa, '%_\\') . '%';
        $digitos = preg_replace('/\D+/', '', $pesquisa);

        $condicoes[] = '(c.nome LIKE ? OR c.nif LIKE ?)';
        $parametros[] = $termo;
        $parametros[] = $digitos !== null && $digitos !== '' ? '%' . $digitos . '%' : $termo;
    }

    // Condições com estado, sem o filtro das consultas: é o que o resumo
    // das consultas precisa (espelha a seleção, incluindo o estado).
    $condicoesConsultas  = array_merge($condicoesEstado, $condicoesTecnico, $condicoes);
    $parametrosConsultas = array_merge($parametrosEstado, $parametrosTecnico, $parametros);

    // Resumo dos dois lados do filtro de consultas, por baixo dos
    // controlos: quantos pedidos desta seleção já foram consultados e
    // quantos ainda não. Conta com os restantes filtros mas sem o das
    // consultas, para os dois números se lerem em conjunto mesmo depois
    // de se escolher um dos lados (e somarem sempre o total).
    //
    // A mesma conta é feita só para os pedidos em aberto: um entregue já
    // saiu da oficina e não pede nada a ninguém, mas continua a somar ao
    // número geral. Os entregues saem por subtração, para não haver uma
    // terceira contagem que possa divergir das duas.
    //
    // O terceiro número da linha é outra pergunta: dos pedidos desta
    // seleção, quantos já passaram o limite sem o cliente abrir o PIN.
    // A régua é a do filtro dos esquecidos, escrita uma vez — dias de
    // calendário desde a última consulta e, num que ninguém consultou,
    // desde a emissão do PIN —, para o número e a lista que ele abre
    // contarem o mesmo. Só pedidos em aberto, pela mesma razão de sempre:
    // a marca não aparece nos entregues.
    if ($consultasDisponiveis) {
        try {
            $consulta = $pdo->prepare(
                "SELECT SUM(uso > 0) AS consultados, SUM(uso = 0) AS por_consultar,
                        SUM(estado <> 'Entregue' AND uso > 0) AS consultados_oficina,
                        SUM(estado <> 'Entregue' AND uso = 0) AS por_consultar_oficina,
                        SUM(estado <> 'Entregue' AND sem_abrir > " . LIMITE_ESPERA_LONGA . ') AS esquecidos
                   FROM (SELECT p.estado, ' . sqlConsultasPinAtual() . ' AS uso,
                                DATEDIFF(CURDATE(), DATE(COALESCE('
            . sqlUltimaConsultaPin() . ', ' . sqlInicioJanelaPin() . '))) AS sem_abrir
                           FROM pedidos p
                     INNER JOIN equipamentos e ON e.id = p.equipamento_id
                     INNER JOIN clientes c     ON c.id = e.cliente_id'
                . ($condicoesConsultas !== [] ? ' WHERE ' . implode(' AND ', $condicoesConsultas) : '')
                . ') AS por_pedido'
            );
            $consulta->execute($parametrosConsultas);
            $linha = $consulta->fetch();

            $resumoConsultados  = (int) ($linha['consultados'] ?? 0);
            $resumoPorConsultar = (int) ($linha['por_consultar'] ?? 0);

            $resumoOficinaConsultados  = (int) ($linha['consultados_oficina'] ?? 0);
            $resumoOficinaPorConsultar = (int) ($linha['por_consultar_oficina'] ?? 0);
            $resumoEntreguesConsultados  = $resumoConsultados - $resumoOficinaConsultados;
            $resumoEntreguesPorConsultar = $resumoPorConsultar - $resumoOficinaPorConsultar;
            $resumoEsquecidos            = (int) ($linha['esquecidos'] ?? 0);
        } catch (PDOException $excecao) {
            $resumoConsultados  = 0;
            $resumoPorConsultar = 0;
            $resumoEsquecidos   = 0;

            $resumoOficinaConsultados    = 0;
            $resumoOficinaPorConsultar   = 0;
            $resumoEntreguesConsultados  = 0;
            $resumoEntreguesPorConsultar = 0;
            error_log('[FixIt][Pedidos][Resumo consultas] ' . $excecao->getMessage());
        }
    }

    // Resumo por estado: quantos pedidos há em cada um, com os restantes
    // filtros (consultas incluídas) mas sem o do estado. Assim cada
    // atalho mostra quantos pedidos traz, mesmo quando já se escolheu um.
    $condicoesResumoEstado = $condicoes;

    if ($condicaoConsultas !== '') {
        $condicoesResumoEstado[] = $condicaoConsultas;
    }

    try {
        $consulta = $pdo->prepare(
            'SELECT p.estado, COUNT(*) AS total
               FROM pedidos p
         INNER JOIN equipamentos e ON e.id = p.equipamento_id
         INNER JOIN clientes c     ON c.id = e.cliente_id'
            . ($condicoesResumoEstado !== [] ? ' WHERE ' . implode(' AND ', $condicoesResumoEstado) : '')
            . ' GROUP BY p.estado'
        );
        $consulta->execute($parametros);

        foreach ($consulta->fetchAll() as $linhaEstado) {
            $chaveEstado = (string) $linhaEstado['estado'];

            if (array_key_exists($chaveEstado, $resumoEstados)) {
                $resumoEstados[$chaveEstado] = (int) $linhaEstado['total'];
            }
        }
    } catch (PDOException $excecao) {
        error_log('[FixIt][Pedidos][Resumo estados] ' . $excecao->getMessage());
    }

    // Resumo por técnico: os mesmos pedidos, contados sem o filtro do
    // técnico. Só entram técnicos com pedidos — um atalho para uma lista
    // vazia não serve de nada.
    $condicoesResumoTecnico = array_merge($condicoesEstado, $condicoes);
    $parametrosResumoTecnico = array_merge($parametrosEstado, $parametros);

    if ($condicaoConsultas !== '') {
        $condicoesResumoTecnico[] = $condicaoConsultas;
    }

    try {
        $consulta = $pdo->prepare(
            'SELECT p.tecnico_id, COUNT(*) AS total
               FROM pedidos p
         INNER JOIN equipamentos e ON e.id = p.equipamento_id
         INNER JOIN clientes c     ON c.id = e.cliente_id'
            . ($condicoesResumoTecnico !== [] ? ' WHERE ' . implode(' AND ', $condicoesResumoTecnico) : '')
            . ' GROUP BY p.tecnico_id'
        );
        $consulta->execute($parametrosResumoTecnico);

        foreach ($consulta->fetchAll() as $linhaTecnico) {
            $idTecnico = (int) ($linhaTecnico['tecnico_id'] ?? 0);

            if ($idTecnico > 0 && isset($tecnicosFiltroPorId[$idTecnico])) {
                $resumoTecnicos[$idTecnico] = (int) $linhaTecnico['total'];
            } elseif ($idTecnico === 0) {
                // O grupo dos pedidos sem técnico: a coluna vem a NULL.
                $resumoSemTecnico = (int) $linhaTecnico['total'];
            }
        }
    } catch (PDOException $excecao) {
        error_log('[FixIt][Pedidos][Resumo técnicos] ' . $excecao->getMessage());
    }

    // A partir daqui as condições são as da lista: estado e técnico vêm à
    // frente, as consultas ao fim, na ordem em que os parâmetros foram
    // recolhidos.
    $condicoes  = array_merge($condicoesEstado, $condicoesTecnico, $condicoes);
    $parametros = array_merge($parametrosEstado, $parametrosTecnico, $parametros);

    if ($condicaoConsultas !== '') {
        $condicoes[] = $condicaoConsultas;
    }

    $filtroSql = $condicoes !== []
        ? ' WHERE ' . implode(' AND ', $condicoes)
        : '';

    // 5. Contagem com filtros e contador global da sidebar.
    $consulta = $pdo->prepare(
        'SELECT COUNT(*)
           FROM pedidos p
     INNER JOIN equipamentos e ON e.id = p.equipamento_id
     INNER JOIN clientes c     ON c.id = e.cliente_id'
        . $filtroSql
    );
    $consulta->execute($parametros);
    $totalPedidos = (int) $consulta->fetchColumn();

    $pedidosAtivos = (int) $pdo->query(
        'SELECT COUNT(*) FROM pedidos WHERE estado <> \'Entregue\''
    )->fetchColumn();

    $totalGeral = (int) $pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn();

    $totalPaginas = max(1, (int) ceil($totalPedidos / POR_PAGINA));

    // Um link antigo para uma página que deixou de existir cai na última.
    $pagina = min($pagina, $totalPaginas);

    $offset = ($pagina - 1) * POR_PAGINA;

    // 6. Pedidos da página, com o mesmo filtro da contagem. Por omissão a
    //    ordem é o estado do ciclo de vida; por entrada, a mais recente
    //    primeiro; por consulta, quem ainda não consultou não tem data e
    //    fica no fim (NULL desce para último num DESC).
    //    No sentido inverso, quem nunca foi consultado não tem data e passa
    //    a pescar a data mais distante possível, para continuar no fim.
    //
    //    Por estado, a ordem é a do ciclo de vida — o FIELD() toma os
    //    valores por ordem do array das etiquetas, em vez de depender do
    //    índice interno do ENUM — e dentro do mesmo estado fica a entrada
    //    mais recente à frente, nos dois sentidos.
    //
    //    Pela espera do PIN, a ordem é o momento em que a espera começou —
    //    a última consulta ou, para quem nunca o abriu, a emissão do PIN —
    //    e não a data da consulta: um pedido que o cliente nunca consultou
    //    espera desde que o PIN saiu e ordena-se por aí, em vez de ir para
    //    o fim da lista. É a idade que a coluna assinala a âmbar, posta por
    //    ordem.
    $ordemEstadosSql = 'FIELD(p.estado, ' . implode(', ', array_map(
        static fn (string $chave): string => "'" . $chave . "'",
        array_keys($etiquetasEstado)
    )) . ')';

    if ($ordemFiltro === 'estado' && $sentidoFiltro === 'antigos') {
        $ordenacaoSql = $ordemEstadosSql . ' DESC, p.data_entrada DESC, p.id DESC';
    } elseif ($ordemFiltro === 'estado') {
        $ordenacaoSql = $ordemEstadosSql . ' ASC, p.data_entrada DESC, p.id DESC';
    } elseif ($ordemFiltro === 'consulta' && $sentidoFiltro === 'antigos') {
        // A consulta mais antiga primeiro. Quem o cliente nunca consultou
        // fica no fim, como no sentido inverso, e não à frente: não tem
        // data de consulta nenhuma para o ordenar. Para esses há a chave da
        // espera do PIN, que os ordena pela idade do talão por abrir — é
        // por ela que abre o atalho dos esquecidos.
        $ordenacaoSql = "COALESCE(" . sqlUltimaConsultaPin()
            . ", '9999-12-31 23:59:59') ASC, p.data_entrada DESC, p.id DESC";
    } elseif ($ordemFiltro === 'consulta') {
        $ordenacaoSql = sqlUltimaConsultaPin() . ' DESC, p.data_entrada DESC, p.id DESC';
    } elseif ($ordemFiltro === 'espera' && $sentidoFiltro === 'antigos') {
        // Espera mais antiga primeiro: quem começou a esperar há mais tempo.
        $ordenacaoSql = sqlInicioEsperaPin() . ' ASC, p.data_entrada ASC, p.id ASC';
    } elseif ($ordemFiltro === 'espera') {
        // Espera mais recente primeiro: quem começou a esperar agora mesmo.
        $ordenacaoSql = sqlInicioEsperaPin() . ' DESC, p.data_entrada DESC, p.id DESC';
    } elseif ($sentidoFiltro === 'antigos') {
        // Entrada mais antiga primeiro: o id desempata na mesma direção.
        $ordenacaoSql = 'p.data_entrada ASC, p.id ASC';
    } else {
        $ordenacaoSql = 'p.data_entrada DESC, p.id DESC';
    }

    $consulta = $pdo->prepare(
        'SELECT p.id, p.estado, p.data_entrada, p.tecnico_id,
                e.marca_modelo,
                c.nome AS cliente_nome,
                u.nome AS tecnico_nome
           FROM pedidos p
     INNER JOIN equipamentos e ON e.id = p.equipamento_id
     INNER JOIN clientes c     ON c.id = e.cliente_id
      LEFT JOIN utilizadores u ON u.id = p.tecnico_id'
        . $filtroSql . '
       ORDER BY ' . $ordenacaoSql . '
          LIMIT ? OFFSET ?'
    );

    $posicao = 1;

    foreach ($parametros as $parametro) {
        $consulta->bindValue($posicao++, $parametro);
    }

    $consulta->bindValue($posicao++, POR_PAGINA, PDO::PARAM_INT);
    $consulta->bindValue($posicao++, $offset, PDO::PARAM_INT);
    $consulta->execute();

    $pedidos = $consulta->fetchAll();

    // 7. Consultas públicas com o PIN emitido agora, pedido a pedido.
    //    Mesma regra da ficha: conta desde a última emissão de PIN (ou
    //    desde a entrada, num pedido sem histórico de PINs) e, num pedido
    //    entregue, para na entrega — as duas pontas vêm dos mesmos
    //    fragmentos que os filtros e o resumo usam. Uma consulta só, para
    //    os pedidos desta página. Opcional: sem as tabelas das migrações a
    //    lista fica sem a coluna em vez de falhar.
    if ($pedidos !== [] && $consultasDisponiveis) {
        try {
            $marcadores = implode(', ', array_fill(0, count($pedidos), '?'));

            $consulta = $pdo->prepare(
                'SELECT c.pedido_id, COUNT(*) AS total, MAX(c.registado_em) AS ultima
                   FROM consultas_publicas c
             INNER JOIN pedidos p ON p.id = c.pedido_id
                  WHERE c.pedido_id IN (' . $marcadores . ')
                    AND c.registado_em >= ' . sqlInicioJanelaPin()
                . sqlFechoJanelaPin() . '
               GROUP BY c.pedido_id'
            );

            $consulta->execute(array_map(
                static fn (array $pedido): int => (int) $pedido['id'],
                $pedidos
            ));

            $totaisConsultas = [];

            foreach ($consulta->fetchAll() as $linhaTotal) {
                $totaisConsultas[(int) $linhaTotal['pedido_id']] = [
                    'total'  => (int) $linhaTotal['total'],
                    'ultima' => $linhaTotal['ultima'],
                ];
            }

            // 8. Desde quando espera o PIN de cada pedido. Numa consulta
            //    antiga é a data dessa consulta que interessa, mas num
            //    pedido que ninguém consultou não há consulta para datar a
            //    espera: a coluna mostra então a idade do próprio PIN.
            $consulta = $pdo->prepare(
                'SELECT p.id, ' . sqlInicioJanelaPin() . ' AS pin_emitido
                   FROM pedidos p
                  WHERE p.id IN (' . $marcadores . ')'
            );

            $consulta->execute(array_map(
                static fn (array $pedido): int => (int) $pedido['id'],
                $pedidos
            ));

            $emissoesPin = [];

            foreach ($consulta->fetchAll() as $linhaEmissao) {
                $emissoesPin[(int) $linhaEmissao['id']] = $linhaEmissao['pin_emitido'];
            }

            foreach ($pedidos as $indice => $pedido) {
                $totais = $totaisConsultas[(int) $pedido['id']] ?? null;

                $pedidos[$indice]['consultas_pin']   = $totais['total'] ?? 0;
                $pedidos[$indice]['ultima_consulta'] = $totais['ultima'] ?? null;
                $pedidos[$indice]['pin_emitido']     = $emissoesPin[(int) $pedido['id']] ?? null;
            }

        } catch (PDOException $excecao) {
            $consultasDisponiveis = false;
            error_log('[FixIt][Pedidos][Consultas] ' . $excecao->getMessage());
        }
    }

    // 9. Desde quando espera por um técnico cada pedido sem nenhum: a última
    //    troca que o deixou por atribuir e, num pedido que nunca teve
    //    técnico, a entrada — as duas pontas que o painel usa, para a idade
    //    escrita aqui ser a mesma que ele mostra. Só os pedidos desta página
    //    e só os que ainda estão na oficina: um entregue já não espera por
    //    ninguém, e uma idade a crescer depois do trabalho acabado mentia.
    if ($pedidos !== [] && $historicoTecnicosDisponivel) {
        $semTecnico = array_values(array_filter(
            $pedidos,
            static fn (array $linha): bool => (int) ($linha['tecnico_id'] ?? 0) === 0
                && $linha['estado'] !== 'Entregue'
        ));

        if ($semTecnico !== []) {
            try {
                $marcadores = implode(', ', array_fill(0, count($semTecnico), '?'));

                $consulta = $pdo->prepare(
                    'SELECT h.pedido_id, MAX(h.registado_em) AS desde
                       FROM historico_tecnicos h
                      WHERE h.tecnico_novo IS NULL
                        AND h.pedido_id IN (' . $marcadores . ')
                   GROUP BY h.pedido_id'
                );

                $consulta->execute(array_map(
                    static fn (array $linha): int => (int) $linha['id'],
                    $semTecnico
                ));

                $trocasSemTecnico = [];

                foreach ($consulta->fetchAll() as $linhaTroca) {
                    $trocasSemTecnico[(int) $linhaTroca['pedido_id']] = $linhaTroca['desde'] ?? null;
                }

                foreach ($pedidos as $indice => $linhaPedido) {
                    if ((int) ($linhaPedido['tecnico_id'] ?? 0) === 0
                            && $linhaPedido['estado'] !== 'Entregue') {
                        // Sem trocas que o tenham deixado sem técnico, espera
                        // desde que entrou: nunca foi atribuído a ninguém.
                        $pedidos[$indice]['sem_tecnico_desde'] =
                            $trocasSemTecnico[(int) $linhaPedido['id']]
                                ?? $linhaPedido['data_entrada'];
                    }
                }
            } catch (PDOException $excecao) {
                $historicoTecnicosDisponivel = false;
                error_log('[FixIt][Pedidos][Histórico técnicos] ' . $excecao->getMessage());
            }
        }
    }
} catch (Throwable $erro) {
    error_log('[FixIt][Pedidos] ' . $erro->getMessage());
    $erroDados = 'Confirma que o servidor MySQL está ligado e que a base de dados fixit foi importada.';
}

// O nome do recorte sai daqui, antes do <head>, porque não serve só a
// frase do topo: o título do separador do browser e a migalha também o
// levam, e são escritos muito antes de a página chegar ao corpo. As
// frases são as mesmas da descrição, com uma exceção de género — o sujeito
// aqui é "pedidos", e "recém-recebidas", "prontas" e "já consultadas"
// passam ao masculino —, e juntam-se pela ordem com que os filtros se
// aplicam. Vão também na mesma frase da contagem, para o cabeçalho não
// ganhar uma segunda linguagem para o mesmo recorte.
$recorteFrases = [];

if ($estadoFiltro !== '' && ($qualificadoresEstado[$estadoFiltro] ?? '') !== '') {
    $recorteFrases[] = $qualificadoresEstado[$estadoFiltro];
}

if ($tecnicoSemAtribuir) {
    $recorteFrases[] = 'ainda por atribuir';
} elseif ($tecnicoFiltro > 0 && isset($tecnicosFiltroPorId[$tecnicoFiltro])) {
    $recorteFrases[] = 'do técnico ' . $tecnicosFiltroPorId[$tecnicoFiltro];
}

if ($equipamentoAtivo !== null) {
    $recorteFrases[] = 'do equipamento ' . $equipamentoAtivo['marca_modelo'];
}

if ($consultasFiltro === 'com') {
    $recorteFrases[] = 'já consultadas pelo cliente';
} elseif ($consultasFiltro === 'sem') {
    $recorteFrases[] = 'ainda por consultar';
} elseif ($consultasFiltro === 'esquecido') {
    $recorteFrases[] = 'com o PIN por abrir há mais de uma semana';
}

if ($esperaLonga) {
    $recorteFrases[] = 'na oficina há mais de uma semana';
}

if ($pesquisa !== '') {
    $recorteFrases[] = 'que correspondem a «' . $pesquisa . '»';
}

$tituloFrases = [];

foreach ($recorteFrases as $fraseRecorte) {
    $tituloFrases[] = match ($fraseRecorte) {
        'recém-recebidas'             => 'recém-recebidos',
        'prontas'                     => 'prontos',
        'já consultadas pelo cliente' => 'já consultados pelo cliente',
        default                       => $fraseRecorte,
    };
}

// Uma coisa é a oficina não ter pedido nenhum, outra é o recorte não trazer
// nenhum: a uma não há filtros que a expliquem. O valor serve a frase do
// topo, o aviso da barra de filtros, o cartão da lista vazia e o título da
// secção — sem ele, a barra pedia o primeiro pedido enquanto o cartão
// culpava os filtros pelo mesmo zero, e o título nomeava um recorte sobre
// uma lista que não existe.
//
// Um erro de base de dados fica de fora — aí quem fala é o aviso do erro, e
// uma contagem a zero não quer dizer oficina vazia.
$oficinaVazia = $erroDados === '' && $totalGeral === 0;

// Com a oficina vazia o título é o da secção, sem recorte: nomear filtros
// por cima de zero pedidos prometia um recorte que não existe, e a frase do
// topo e o cartão já dizem o mesmo.
if ($oficinaVazia) {
    $tituloLista = 'Pedidos';
} elseif ($tituloFrases === []) {
    $tituloLista = 'Todos os pedidos';
} else {
    $tituloLista = 'Pedidos ' . implode(', ', $tituloFrases);
}

// Na migalha e no separador a página é "Pedidos"; o recorte acrescenta-se
// quando existe, para quem tem várias abas abertas saber qual está a ver.
// Sem filtros — ou sem oficina — fica só "Pedidos": "Todos os pedidos" é o
// nome da secção e diz bem o que a lista é, mas na aba do browser seria uma
// repetição mais longa do mesmo.
$tituloRecorte = ($oficinaVazia || $tituloFrases === []) ? 'Pedidos' : $tituloLista;
?>
<?php painelAbrir(
    'pedidos',
    $tituloRecorte,
    'Todas as ordens de serviço da oficina FixIt, com estado, técnico e data de entrada.',
    [
        ['Painel', 'painel.php'],
        [$tituloRecorte, null],
    ],
    $erroDados,
    false,
    $pedidosAtivos ?? -1
); ?>

<main class="principal" id="conteudo" tabindex="-1">

    <?php
    // A frase do topo diz o que a lista mostra. Sem filtros é a oficina
    // toda, com a explicação inteira da ordem; com filtros é o recorte —
    // a lista deixou de ser "todas as ordens" —, cujas frases já vêm
    // calculadas de cima (é delas que o separador e a migalha vivem), e a
    // ordem fica na forma curta, senão a frase crescia sem fim. As duas
    // metades nunca aparecem juntas, por isso a diferença de comprimento
    // não se nota.

    // A ordem em meia dúzia de palavras, para acompanhar o recorte. "Ordens"
    // é feminino, por isso "ordenadas".
    $ordemFraseCurta = match (true) {
        $ordemFiltro === 'estado' && $sentidoFiltro === 'antigos'
            => 'Ordenadas do fim do ciclo de vida para o início.',
        $ordemFiltro === 'estado'
            => 'Ordenadas do início do ciclo de vida para o fim.',
        $ordemFiltro === 'consulta' && $sentidoFiltro === 'antigos'
            => 'Ordenadas pela última consulta, da mais antiga para a mais recente; as ordens sem consultas ficam no fim.',
        $ordemFiltro === 'consulta'
            => 'Ordenadas pela última consulta, da mais recente para a mais antiga; as ordens sem consultas ficam no fim.',
        $ordemFiltro === 'espera' && $sentidoFiltro === 'antigos'
            => 'Ordenadas pela espera do PIN, da mais antiga para a mais recente.',
        $ordemFiltro === 'espera'
            => 'Ordenadas pela espera do PIN, da mais recente para a mais antiga.',
        $sentidoFiltro === 'antigos'
            => 'Ordenadas pela data de entrada, da mais antiga para a mais recente.',
        default
            => 'Ordenadas pela data de entrada, da mais recente para a mais antiga.',
    };

    // Sem filtros, a frase de sempre — a que explica os detalhes da ordem,
    // como os pedidos sem consultas que ficam no fim.
    $ordemFraseLonga = match (true) {
        $ordemFiltro === 'estado' && $sentidoFiltro === 'antigos'
            => 'Todas as ordens de serviço da oficina, do fim do ciclo de vida para o início — primeiro as que já foram entregues, por último as recém-recebidas. Dentro do mesmo estado vem à frente a entrada mais recente.',
        $ordemFiltro === 'estado'
            => 'Todas as ordens de serviço da oficina, pela ordem do ciclo de vida: primeiro as recebidas, depois as que estão em diagnóstico, em reparação e prontas, e por fim as entregues. Dentro do mesmo estado vem à frente a entrada mais recente.',
        $ordemFiltro === 'consulta' && $sentidoFiltro === 'antigos'
            => 'Todas as ordens de serviço da oficina, da última consulta mais antiga para a mais recente — quem foi consultado há mais tempo aparece primeiro. Os pedidos ainda sem consultas ficam no fim.',
        $ordemFiltro === 'consulta'
            => 'Todas as ordens de serviço da oficina, da última consulta mais recente para a mais antiga. Os pedidos ainda sem consultas ficam no fim.',
        $ordemFiltro === 'espera' && $sentidoFiltro === 'antigos'
            => 'Todas as ordens de serviço da oficina, pela espera do PIN, da mais antiga para a mais recente — quem o cliente deixou de consultar há mais tempo aparece primeiro. Num pedido que ninguém chegou a consultar, a espera conta desde que o talão foi emitido.',
        $ordemFiltro === 'espera'
            => 'Todas as ordens de serviço da oficina, pela espera do PIN, da mais recente para a mais antiga — quem o cliente consultou em último aparece primeiro.',
        $sentidoFiltro === 'antigos'
            => 'Todas as ordens de serviço da oficina, da entrada mais antiga para a mais recente — as ordens que estão na oficina há mais tempo aparecem primeiro.',
        default
            => 'Todas as ordens de serviço da oficina, da entrada mais recente para a mais antiga.',
    };

    $descricaoTopo = $recorteFrases === []
        ? $ordemFraseLonga
        : 'As ordens de serviço ' . implode(', ', $recorteFrases) . '. ' . $ordemFraseCurta;

    // Sem resultados a frase não descreve o que não existe: com a oficina
    // sem pedidos diz isso mesmo (não há recorte a nomear); com a oficina
    // cheia, diz que o recorte está vazio e nomeia-o, para se perceber o que
    // se pediu. Sai também a ordem da lista — não há lista para ordenar —, e
    // fica o mesmo verbo do aviso do painel, «corresponde aos filtros».
    if ($oficinaVazia) {
        $descricaoTopo = 'A oficina ainda não tem ordens de serviço registadas.';
    } elseif ($erroDados === '' && $totalPedidos === 0) {
        $descricaoTopo = 'Nenhum pedido corresponde aos filtros — ordens de serviço '
            . implode(', ', $recorteFrases) . '.';
    }

    ?>

    <div class="cabecalho">
        <div class="cabecalho__texto">
            <h1>Pedidos</h1>
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
                <p class="aviso__titulo" id="titulo-erro">Não foi possível carregar os pedidos</p>
                <p class="aviso__texto"><?= h($erroDados) ?></p>
                <a class="botao botao--fantasma" href="pedidos.php">Tentar novamente</a>
            </div>
        </section>
    <?php else: ?>

        <?php
        // Ação de mudança de estado. O módulo não imprime nada quando
        // não há pedido em edição.
        AcaoEstadoPedido::renderFormulario(
            $acaoEstado,
            $etiquetasEstado,
            $classesEstado,
            urlPedidos($pagina) . '#alterar',
            urlPedidos($pagina) . '#lista'
        );
        ?>

        <section class="painel" id="lista" aria-labelledby="titulo-lista">
            <div class="painel__cabecalho">
                <div>
                    <h2 id="titulo-lista" tabindex="-1"><?= h($tituloLista) ?></h2>
                    <p class="painel__descricao">
                        <?php if ($filtrosAtivos !== []): ?>
                            <?= $totalPedidos ?> <?= $totalPedidos === 1 ? 'pedido encontrado' : 'pedidos encontrados' ?>.
                        <?php else: ?>
                            <?= $totalPedidos ?> <?= $totalPedidos === 1 ? 'pedido registado' : 'pedidos registados' ?>.
                        <?php endif; ?>
                    </p>

                    <?php /* O endereço pode pedir uma ordem que o filtro ativo não
                             aceita — o caso dos esquecidos, que têm a ordem que
                             os define. Sem esta linha, quem chega por uma
                             ligação antiga vê a lista por outra ordem e conclui
                             que a ligação falhou. Discreta: o controlo ao lado
                             já mostra a ordem em vigor, e esta nota só diz por
                             que não é a pedida. */ ?>
                    <?php if ($ordemSubstituida !== null): ?>
                        <p class="nota-ordem" id="nota-ordem"
                           data-chave="<?= h($ordemSubstituida . '-' . $consultasFiltro) ?>">
                            <svg aria-hidden="true"><use href="#icone-info"/></svg>
                            <span>O endereço pedia a lista pela última consulta, que este
                                filtro não usa. Está pela espera do PIN, da mais antiga para
                                a mais recente.
                                <button class="nota-ordem__dispensar" type="button"
                                        aria-label="Dispensar esta nota" hidden>Dispensar</button>
                            </span>
                        </p>
                        <?php /* Quem já leu a nota não precisa de a ver a cada
                                 recarregamento da mesma ligação. O fecho vive
                                 aqui, junto da nota, e não no fim da página:
                                 corre antes de a nota chegar ao ecrã, por isso
                                 não há um instante em que ela apareça para
                                 logo desaparecer. A chave é a da própria nota
                                 — outra substituição fala por si. */ ?>
                        <script>
                        (function () {
                            "use strict";

                            var nota = document.getElementById("nota-ordem");
                            var chave = "nota-ordem:" + nota.getAttribute("data-chave");
                            var lida = false;

                            try {
                                lida = window.sessionStorage.getItem(chave) === "1";
                            } catch (erro) {
                                lida = false;   /* navegação privada sem storage */
                            }

                            if (lida) {
                                nota.parentNode.removeChild(nota);
                                return;
                            }

                            var botao = nota.querySelector(".nota-ordem__dispensar");
                            botao.hidden = false;
                            botao.addEventListener("click", function () {
                                try {
                                    window.sessionStorage.setItem(chave, "1");
                                } catch (erro) {
                                    /* sem storage, a nota volta na próxima vez */
                                }

                                var titulo = document.getElementById("titulo-lista");
                                nota.parentNode.removeChild(nota);

                                /* O foco estava no botão que saiu do DOM:
                                   passa para o título da secção, em vez de
                                   cair no início do documento. */
                                if (titulo) {
                                    titulo.focus({ preventScroll: true });
                                }
                            });
                        })();
                        </script>
                    <?php endif; ?>
                </div>

                <div class="ordenacao" role="group" aria-label="Ordenar a lista">
                    <?php /* Cada lado do controlo é um grupo fechado — o rótulo
                             com as suas opções. Soltos, os oito elementos
                             quebravam em linhas ao acaso e o rótulo podia ficar
                             numa linha e as opções na seguinte. */ ?>
                    <div class="ordenacao__grupo">
                        <span class="ordenacao__rotulo">Ordenar</span>
                        <?php /* A opção escolhida é texto marcado: uma ligação
                                 para a própria página não leva a lado nenhum. */ ?>
                        <?php if ($ordemFiltro === 'entrada'): ?>
                            <span class="ordenacao__atual" aria-current="true">Entrada</span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlPedidos(1, ['ordem' => 'entrada'])) ?>#lista">Entrada</a>
                        <?php endif; ?>

                        <?php /* A chave por omissão devolve o URL limpo. */ ?>
                        <?php if ($ordemFiltro === 'estado'): ?>
                            <span class="ordenacao__atual" aria-current="true">Estado</span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlPedidos(1, [], ['ordem'])) ?>#lista">Estado</a>
                        <?php endif; ?>

                        <?php /* Com o filtro dos esquecidos a chave não se oferece:
                                 o recorte já tem a ordem que o define — a espera
                                 do PIN —, e escolher por consulta seria uma
                                 segunda ordem para a mesma lista. E não fica só
                                 fora do controlo: um `ordem=consulta` que chegue
                                 no URL cai na espera do PIN, no topo do ficheiro,
                                 por isso esta marca nunca aparece com os
                                 esquecidos. */ ?>
                        <?php if ($consultasDisponiveis): ?>
                            <?php if ($ordemFiltro === 'consulta'): ?>
                                <span class="ordenacao__atual" aria-current="true">Última consulta</span>
                            <?php elseif ($consultasFiltro !== 'esquecido'): ?>
                                <a class="botao botao--pequeno botao--fantasma"
                                   href="<?= h(urlPedidos(1, ['ordem' => 'consulta'])) ?>#lista">Última consulta</a>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php /* A espera do PIN tem um lado que interessa — quem
                                 espera há mais tempo — e é por ele que a chave
                                 abre: o sentido do momento não o traz, por isso a
                                 ligação pede-o. O controlo do sentido fica a dizer
                                 onde se está e troca-o. */ ?>
                        <?php if ($consultasDisponiveis): ?>
                            <?php if ($ordemFiltro === 'espera'): ?>
                                <span class="ordenacao__atual" aria-current="true">Espera do PIN</span>
                            <?php else: ?>
                                <a class="botao botao--pequeno botao--fantasma"
                                   href="<?= h(urlPedidos(1, ['ordem' => 'espera', 'sentido' => 'antigos'])) ?>#lista">Espera do PIN</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php
                    // A chave escolhida acompanha o sentido: mudar de sentido
                    // não muda o que se está a ordenar.
                    $extrasOrdem = $ordemFiltro !== 'estado' ? ['ordem' => $ordemFiltro] : [];

                    // O sentido tem nomes próprios em cada eixo: uma data vai
                    // do mais recente ao mais antigo, o ciclo de vida do
                    // início ao fim, e a espera do PIN segue as palavras das
                    // frases da ordem — «mais antiga» e «mais recente» —,
                    // ditas da espera e não da data: o que é antigo aqui é o
                    // tempo que já corre, não o dia em que começou. Por isso
                    // a espera não leva os «Recentes/Antigos» das datas.
                    //
                    // O ciclo de vida nomeia os dois extremos como as frases o
                    // fazem — «do início do ciclo de vida para o fim» —, em vez
                    // do «Do início/Do fim» solto: sem o ciclo, «do início»
                    // tanto podia ser o primeiro pedido da lista como o primeiro
                    // estado, e era o único par que não dizia de que eixo falava.
                    if ($ordemFiltro === 'estado') {
                        $sentidoInicial = 'Do início do ciclo';
                        $sentidoInverso = 'Do fim do ciclo';
                    } elseif ($ordemFiltro === 'espera') {
                        $sentidoInicial = 'Mais recente';
                        $sentidoInverso = 'Mais antiga';
                    } else {
                        $sentidoInicial = 'Recentes';
                        $sentidoInverso = 'Antigos';
                    }
                    ?>
                    <div class="ordenacao__grupo">
                        <span class="ordenacao__rotulo">Sentido</span>
                        <?php if ($sentidoFiltro === ''): ?>
                            <span class="ordenacao__atual" aria-current="true"><?= $sentidoInicial ?></span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlPedidos(1, $extrasOrdem, ['sentido'])) ?>#lista"><?= $sentidoInicial ?></a>
                        <?php endif; ?>
                        <?php if ($sentidoFiltro === 'antigos'): ?>
                            <span class="ordenacao__atual" aria-current="true"><?= $sentidoInverso ?></span>
                        <?php else: ?>
                            <a class="botao botao--pequeno botao--fantasma"
                               href="<?= h(urlPedidos(1, array_merge($extrasOrdem, ['sentido' => 'antigos']))) ?>#lista"><?= $sentidoInverso ?></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <form class="filtros" method="get" action="pedidos.php#lista" role="search"
                  aria-label="Filtrar pedidos">
                <?php if ($equipamentoFiltro > 0): ?>
                    <input type="hidden" name="equipamento" value="<?= $equipamentoFiltro ?>">
                <?php endif; ?>

                <?php if ($esperaLonga): ?>
                    <input type="hidden" name="espera" value="longa">
                <?php endif; ?>

                <?php if ($ordemFiltro !== 'estado'): ?>
                    <input type="hidden" name="ordem" value="<?= h($ordemFiltro) ?>">
                <?php endif; ?>

                <?php if ($sentidoFiltro !== ''): ?>
                    <input type="hidden" name="sentido" value="<?= h($sentidoFiltro) ?>">
                <?php endif; ?>

                <div class="campo filtros__campo filtros__campo--pesquisa">
                    <label class="campo__rotulo" for="filtro-q">Cliente ou NIF</label>
                    <input class="entrada" type="search" id="filtro-q" name="q"
                           value="<?= h($pesquisa) ?>" placeholder="Nome ou NIF"
                           autocomplete="off">
                </div>

                <div class="campo filtros__campo">
                    <label class="campo__rotulo" for="filtro-estado">Estado</label>
                    <select class="entrada" id="filtro-estado" name="estado">
                        <option value="">Todos os estados</option>
                        <?php foreach ($estadosFiltro as $valorEstado => $rotuloEstado): ?>
                            <option value="<?= h($valorEstado) ?>"
                                <?= $estadoFiltro === $valorEstado ? 'selected' : '' ?>><?= h($rotuloEstado) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo filtros__campo">
                    <label class="campo__rotulo" for="filtro-tecnico">Técnico</label>
                    <select class="entrada" id="filtro-tecnico" name="tecnico">
                        <option value="">Todos os técnicos</option>
                        <option value="sem" <?= $tecnicoSemAtribuir ? 'selected' : '' ?>>Por atribuir</option>
                        <?php foreach ($tecnicosFiltro as $tecnicoFiltroLinha): ?>
                            <option value="<?= (int) $tecnicoFiltroLinha['id'] ?>"
                                <?= $tecnicoFiltro === (int) $tecnicoFiltroLinha['id'] ? 'selected' : '' ?>><?= h($tecnicoFiltroLinha['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($consultasDisponiveis): ?>
                    <div class="campo filtros__campo">
                        <label class="campo__rotulo" for="filtro-consultas">Consultas</label>
                        <select class="entrada" id="filtro-consultas" name="consultas">
                            <option value="">Todas</option>
                            <option value="com" <?= $consultasFiltro === 'com' ? 'selected' : '' ?>>Algumas</option>
                            <option value="sem" <?= $consultasFiltro === 'sem' ? 'selected' : '' ?>>Nenhuma</option>
                            <option value="esquecido" <?= $consultasFiltro === 'esquecido' ? 'selected' : '' ?>>Há mais de uma semana</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="filtros__acoes">
                    <button class="botao botao--primario" type="submit">Filtrar</button>
                    <?php if ($filtrosAtivos !== []): ?>
                        <a class="botao botao--fantasma" href="pedidos.php#lista">Limpar</a>
                    <?php endif; ?>
                </div>

                <?php if ($equipamentoAtivo !== null || $esperaLonga): ?>
                    <div class="filtros__ativos">
                        <?php if ($equipamentoAtivo !== null): ?>
                            <span class="filtro">
                                <svg class="filtro__icone" viewBox="0 0 24 24" aria-hidden="true"><use href="#icone-equipamentos"/></svg>
                                <span class="filtro__texto">
                                    <span class="filtro__valor"><?= h($equipamentoAtivo['marca_modelo']) ?></span>
                                    <span class="filtro__contexto">
                                        <?= h($equipamentoAtivo['cliente_nome']) ?><?php if (($equipamentoAtivo['numero_serie'] ?? '') !== ''): ?> · <?= h($equipamentoAtivo['numero_serie']) ?><?php endif; ?>
                                    </span>
                                </span>
                                <a class="filtro__remover" href="<?= h(urlPedidos(1, [], ['equipamento'])) ?>#lista">
                                    Remover<span class="visualmente-oculto"> o filtro do equipamento</span>
                                </a>
                            </span>
                        <?php endif; ?>

                        <?php if ($esperaLonga): ?>
                            <span class="filtro">
                                <svg class="filtro__icone" viewBox="0 0 24 24" aria-hidden="true"><use href="#icone-relogio"/></svg>
                                <span class="filtro__texto">
                                    <span class="filtro__valor">Há mais de uma semana</span>
                                    <span class="filtro__contexto">Pedidos em aberto, na oficina</span>
                                </span>
                                <a class="filtro__remover" href="<?= h(urlPedidos(1, [], ['espera'])) ?>#lista">
                                    Remover<span class="visualmente-oculto"> o filtro da espera</span>
                                </a>
                            </span>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>

                <?php
                // O resumo por estado abre em dois enquanto a seleção
                // misturar o que está na oficina com o que já saiu, como o
                // das consultas: os números todos numa linha dizem totais,
                // não dizem de que lado cada um está, e um entregue não
                // pede nada a ninguém. Cada linha nomeia o seu lado depois
                // da dimensão — as duas vivem na mesma barra, e "Na
                // oficina" sozinho já é o rótulo do resumo das consultas.
                // Com um estado escolhido o recorte já está feito e a
                // separação não acrescenta nada: a linha volta a ser uma.
                //
                // O rótulo leva a dimensão e o lado, sem a frase toda: no
                // telemóvel a barra tem 328 px úteis e "Por estado, na
                // oficina" empurrava os números para uma segunda linha —
                // cinco linhas destas ocupavam metade da barra. "Estado:"
                // é o nome do próprio controlo, por isso não se perde nada.
                //
                // A dimensão é um pedaço próprio ("Estado: ") e some no
                // telemóvel: lá os dois lados deixam de ser duas linhas e
                // passam a um texto corrido, e repetir "Estado:" duas vezes
                // num texto só empurrava os números mais para baixo. No
                // ecrã largo fica como estava, uma linha por lado.
                $gruposDoResumoEstado = [
                    [
                        'dimensao' => 'Estado: ',
                        'rotulo'   => 'na oficina',
                        'estados'  => ['Recebido', 'Em Diagnostico', 'Em Reparacao', 'Pronto'],
                    ],
                    [
                        'dimensao' => 'Estado: ',
                        'rotulo'   => 'entregues',
                        'estados'  => ['Entregue'],
                    ],
                ];

                if ($estadoFiltro !== '') {
                    $gruposDoResumoEstado = [[
                        'dimensao' => '',
                        'rotulo'   => 'Por estado',
                        'estados'  => array_keys($etiquetasEstado),
                    ]];
                }

                // Os atalhos do resumo que não impõem uma ordem herdam a do
                // URL (`urlPedidos` leva-a consigo): clicar num estado ou num
                // técnico mantém a ordem que a lista já tinha. Para quem ouve
                // o resumo, o número não chega — é preciso saber em que ordem
                // os pedidos vão aparecer. A frase é a mesma dos atalhos que
                // impõem a sua: um pedaço curto a seguir a "mostrar só estes".
                // Aqueles que já fixam a ordem — já consultados, por consultar
                // dos entregues e esquecidos — continuam com a frase própria,
                // e não com esta, que descreveria outra lista.
                $ordemResumo = match (true) {
                    $ordemFiltro === 'consulta' && $sentidoFiltro === 'antigos'
                        => 'da última consulta mais antiga para a mais recente',
                    $ordemFiltro === 'consulta'
                        => 'da última consulta mais recente para a mais antiga',
                    $ordemFiltro === 'espera' && $sentidoFiltro === 'antigos'
                        => 'da espera do PIN mais antiga para a mais recente',
                    $ordemFiltro === 'espera'
                        => 'da espera do PIN mais recente para a mais antiga',
                    $ordemFiltro === 'entrada' && $sentidoFiltro === 'antigos'
                        => 'da entrada mais antiga para a mais recente',
                    $ordemFiltro === 'entrada'
                        => 'da entrada mais recente para a mais antiga',
                    $sentidoFiltro === 'antigos'
                        => 'do fim do ciclo de vida para o início',
                    default
                        => 'do início do ciclo de vida para o fim',
                };
                ?>
                <div class="filtros__resumo-estado">
                    <?php
                    // Com a oficina vazia não há resumo a dar: os números
                    // por estado seriam todos zero, e uma barra cheia de
                    // zeros diz menos do que a frase que os substitui.
                    // O valor vem de cima, o mesmo que a frase do topo e o
                    // cartão da lista usam.
                    ?>
                    <?php if ($oficinaVazia): ?>
                        <?php /* A frase diz porque é que o resumo não existe, e
                                 não repete o aviso da lista vazia que está por
                                 baixo: no telemóvel os dois ficam a um dedo de
                                 distância, e a mesma notícia duas vezes seguidas
                                 lê-se como um erro da página.

                                 O atalho no fim leva ao mesmo destino do botão
                                 do cartão vazio, mas como ligação de texto:
                                 quem já está a ler a barra cria o primeiro
                                 pedido sem descer à lista. Não é um segundo
                                 botão — o primário é o do cartão, e dois
                                 botões iguais no mesmo ecrã pesam. */ ?>
                        <p class="filtros__resumo filtros__resumo--vazio">Assim que houver um pedido, os números por estado aparecem aqui. <a href="pedido-novo.php">Cria o primeiro pedido</a></p>
                    <?php else: ?>
                    <?php foreach ($gruposDoResumoEstado as $grupoEstado): ?>
                        <?php
                        // Só os estados com pedidos nesta seleção, pela ordem do
                        // ciclo de vida; um grupo sem nenhum não deixa na barra
                        // um rótulo sem números.
                        $estadosComPedidos = array_filter(array_intersect_key(
                            $resumoEstados,
                            array_flip($grupoEstado['estados'])
                        ));
                        ?>
                        <?php if ($estadosComPedidos === []) { continue; } ?>
                        <p class="filtros__resumo">
                            <span class="filtros__resumo-rotulo"><?php if ($grupoEstado['dimensao'] !== ''): ?><span class="filtros__resumo-dimensao"><?= h($grupoEstado['dimensao']) ?></span><?php endif; ?><?= h($grupoEstado['rotulo']) ?></span>
                            <?php $primeiroEstado = true; ?>
                            <?php foreach ($estadosComPedidos as $chaveEstado => $totalEstado): ?>
                                <?php if (!$primeiroEstado): ?><span aria-hidden="true">·</span><?php endif; ?>
                                <?php $primeiroEstado = false; ?>
                                <?php if ($estadoFiltro === (string) $chaveEstado): ?>
                                    <span class="filtros__resumo-atual"><?= h($etiquetasEstado[$chaveEstado] ?? $chaveEstado) ?> <strong><?= $totalEstado ?></strong></span>
                                <?php else: ?>
                                    <a href="<?= h(urlPedidos(1, ['estado' => $chaveEstado])) ?>#lista"><?= h($etiquetasEstado[$chaveEstado] ?? $chaveEstado) ?> <strong><?= $totalEstado ?></strong><span class="visualmente-oculto"> — mostrar só estes, <?= h($ordemResumo) ?></span></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </p>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php
                // Técnicos com pedidos nesta seleção, pela ordem do select.
                $tecnicosComPedidos = [];

                foreach ($tecnicosFiltro as $tecnicoLinha) {
                    $idLinha = (int) $tecnicoLinha['id'];

                    if (($resumoTecnicos[$idLinha] ?? 0) > 0) {
                        $tecnicosComPedidos[$idLinha] = (string) $tecnicoLinha['nome'];
                    }
                }
                ?>
                <?php if ($tecnicosComPedidos !== [] || $resumoSemTecnico > 0): ?>
                    <?php /* O invólucro não existe para o layout no ecrã largo
                             (`display: contents`): serve ao telemóvel, onde este
                             resumo passa a texto corrido como o do estado. Os
                             nomes são o que ocupa a linha, e cada um a começar
                             uma caixa nova de flexão deixava o fim das linhas
                             vazio. */ ?>
                    <div class="filtros__resumo-tecnico">
                        <p class="filtros__resumo">
                            <span class="filtros__resumo-rotulo">Por técnico</span>
                            <?php $primeiroTecnico = true; ?>
                            <?php foreach ($tecnicosComPedidos as $idTecnico => $nomeTecnico): ?>
                                <?php if (!$primeiroTecnico): ?><span aria-hidden="true">·</span><?php endif; ?>
                                <?php $primeiroTecnico = false; ?>
                                <?php if ($tecnicoFiltro === $idTecnico): ?>
                                    <span class="filtros__resumo-atual"><?= h($nomeTecnico) ?> <strong><?= $resumoTecnicos[$idTecnico] ?></strong></span>
                                <?php else: ?>
                                    <a href="<?= h(urlPedidos(1, ['tecnico' => $idTecnico])) ?>#lista"><?= h($nomeTecnico) ?> <strong><?= $resumoTecnicos[$idTecnico] ?></strong><span class="visualmente-oculto"> — mostrar só estes, <?= h($ordemResumo) ?></span></a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($resumoSemTecnico > 0): ?>
                                <?php if (!$primeiroTecnico): ?><span aria-hidden="true">·</span><?php endif; ?>
                                <?php $primeiroTecnico = false; ?>
                                <?php /* Mesma etiqueta da coluna da lista e da ficha. */ ?>
                                <?php if ($tecnicoSemAtribuir): ?>
                                    <span class="filtros__resumo-atual">Por atribuir <strong><?= $resumoSemTecnico ?></strong></span>
                                <?php else: ?>
                                    <a href="<?= h(urlPedidos(1, ['tecnico' => 'sem'])) ?>#lista">Por atribuir <strong><?= $resumoSemTecnico ?></strong><span class="visualmente-oculto"> — mostrar só estes, <?= h($ordemResumo) ?></span></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php
                // Enquanto a seleção misturar o que está na oficina com o que
                // já saiu, o resumo das consultas abre em dois: um entregue
                // não pede nada a ninguém e não se pode ler ao lado de um em
                // trabalho sem se saber qual é qual. Com um estado escolhido,
                // a separação não acrescenta nada — o select já diz de que
                // lado estamos, e o atalho levaria a mais do que o número
                // conta — e o resumo volta a ser uma linha só, como antes.
                // Os esquecidos só existem do lado da oficina: a régua pede
                // um pedido em aberto, e um entregue já saiu. No grupo dos
                // entregues o número é zero por construção, e por isso a
                // linha de lá nem chega a mostrá-lo.
                $gruposDoResumo = [
                    [
                        'rotulo'       => 'Na oficina',
                        'estado'       => 'ativos',
                        'consultados'  => $resumoOficinaConsultados,
                        'porConsultar' => $resumoOficinaPorConsultar,
                        'esquecidos'   => $resumoEsquecidos,
                    ],
                    [
                        'rotulo'       => 'Entregues',
                        'estado'       => 'Entregue',
                        'consultados'  => $resumoEntreguesConsultados,
                        'porConsultar' => $resumoEntreguesPorConsultar,
                        'esquecidos'   => 0,
                    ],
                ];

                if ($estadoFiltro !== '') {
                    $gruposDoResumo = [[
                        'rotulo'       => '',
                        'estado'       => null,
                        'consultados'  => $resumoConsultados,
                        'porConsultar' => $resumoPorConsultar,
                        'esquecidos'   => $resumoEsquecidos,
                    ]];
                }
                ?>
                <?php if ($consultasDisponiveis): ?>
                    <?php foreach ($gruposDoResumo as $grupoResumo): ?>
                        <?php if ($grupoResumo['consultados'] + $grupoResumo['porConsultar'] === 0) { continue; } ?>
                        <?php
                        // O atalho leva o filtro do lado que o número conta. Sem
                        // grupo, é o das consultas sozinho, como sempre foi.
                        $atributosGrupo = $grupoResumo['estado'] === null
                            ? []
                            : ['estado' => $grupoResumo['estado']];

                        // Os já consultados abrem pela última consulta mais
                        // antiga em qualquer metade do resumo: o número conta
                        // há quanto tempo ninguém abre o PIN daqueles pedidos,
                        // e a lista que ele abre parte da mesma ponta do tempo.
                        // Vale para a oficina e para os entregues, e também com
                        // um estado escolhido — aí o resumo tem uma linha só,
                        // mas a regra não muda com o número de linhas. Ali
                        // todos têm data de consulta, por isso a chave
                        // `consulta` no sentido dos antigos não manda ninguém
                        // para o fim.
                        $ordemConsultadosGrupo = ['ordem' => 'consulta', 'sentido' => 'antigos'];

                        // Os por consultar só mudam de ordem do lado dos
                        // entregues, onde vão pela espera do PIN mais antiga,
                        // como o atalho do painel que conta os mesmos pedidos:
                        // são talões que o cliente nunca abriu, e a ordem por
                        // estado não diz qual deles espera há mais tempo. Na
                        // oficina ficam pela ordem por omissão, que é a mesma
                        // das outras leituras da lista.
                        $ladoEntregue = ($grupoResumo['estado'] ?? $estadoFiltro) === 'Entregue';
                        $ordemGrupo = $ladoEntregue
                            ? ['ordem' => 'espera', 'sentido' => 'antigos']
                            : [];
                        ?>
                        <?php /* O atalho dos por consultar também anuncia a ordem:
                                 do lado dos entregues vem fixa da espera do PIN
                                 (ver $ordemGrupo); na oficina é a que a lista
                                 já tem. */ ?>
                        <?php $ordemPorConsultar = $ordemGrupo !== []
                            ? 'da espera do PIN mais antiga para a mais recente'
                            : $ordemResumo; ?>
                        <p class="filtros__resumo">
                            <?php if ($grupoResumo['rotulo'] !== ''): ?>
                                <span class="filtros__resumo-rotulo"><?= h($grupoResumo['rotulo']) ?></span>
                            <?php endif; ?>
                            <?php if ($consultasFiltro === 'com'): ?>
                                <span class="filtros__resumo-atual">Já consultados <strong><?= $grupoResumo['consultados'] ?></strong></span>
                            <?php else: ?>
                                <a href="<?= h(urlPedidos(1, $atributosGrupo + $ordemConsultadosGrupo + ['consultas' => 'com'])) ?>#lista">Já consultados <strong><?= $grupoResumo['consultados'] ?></strong><span class="visualmente-oculto"> — mostrar só estes, da última consulta mais antiga para a mais recente</span></a>
                            <?php endif; ?>
                            <span aria-hidden="true">·</span>
                            <?php if ($consultasFiltro === 'sem'): ?>
                                <span class="filtros__resumo-atual">Por consultar <strong><?= $grupoResumo['porConsultar'] ?></strong></span>
                            <?php else: ?>
                                <a href="<?= h(urlPedidos(1, $atributosGrupo + $ordemGrupo + ['consultas' => 'sem'])) ?>#lista">Por consultar <strong><?= $grupoResumo['porConsultar'] ?></strong><span class="visualmente-oculto"> — mostrar só estes, <?= h($ordemPorConsultar) ?></span></a>
                            <?php endif; ?>
                            <?php /* Atrasados da mesma seleção, quando os há. Não é
                                     um terceiro lado da soma — atravessa os dois —
                                     e por isso não aparece a zero: um atalho para uma
                                     lista vazia não serve a ninguém.

                                     O nome diz "sem consulta" e não "PIN por
                                     abrir" de propósito: este número inclui quem
                                     já consultou alguma vez e não abre o PIN há
                                     mais de uma semana, que é o conjunto que a
                                     coluna assinala a âmbar e que o filtro abre.
                                     O atalho do painel com o outro nome conta só
                                     os PINs que ninguém abriu, um recorte mais
                                     estreito — dois números com a mesma etiqueta
                                     liam-se como um só. */ ?>
                            <?php if ($grupoResumo['esquecidos'] > 0): ?>
                                <span aria-hidden="true">·</span>
                                <?php if ($consultasFiltro === 'esquecido'): ?>
                                    <span class="filtros__resumo-atual">Sem consulta há mais de uma semana <strong><?= $grupoResumo['esquecidos'] ?></strong></span>
                                <?php else: ?>
                                    <?php /* O atalho abre a lista já pela espera mais
                                             antiga primeiro, como os do painel e como
                                             a idade marcada na coluna: antes de chegar
                                             à ordem por omissão, a lista chegava com os
                                             pedidos por estado e só depois se podia
                                             trocar. */ ?>
                                    <a href="<?= h(urlPedidos(1, $atributosGrupo + ['consultas' => 'esquecido', 'ordem' => 'espera', 'sentido' => 'antigos'])) ?>#lista">Sem consulta há mais de uma semana <strong><?= $grupoResumo['esquecidos'] ?></strong><span class="visualmente-oculto"> — mostrar só estes, da espera do PIN mais antiga para a mais recente</span></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                <?php endif; ?>
            </form>

            <?php if ($erroAtribuicao !== ''): ?>
                <div class="alerta" role="alert">
                    <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                    <div>
                        <p class="alerta__titulo">Técnico não alterado</p>
                        <p class="alerta__texto"><?= h($erroAtribuicao) ?></p>
                    </div>
                </div>
            <?php elseif ($sucessoAcao !== ''): ?>
                <div class="alerta alerta--sucesso" role="status">
                    <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                    <div>
                        <p class="alerta__titulo">Pedido atualizado</p>
                        <p class="alerta__texto"><?= h($sucessoAcao) ?></p>
                    </div>
                </div>
            <?php elseif ($pedidoAcao === null && isset($errosAcao['geral'])): ?>
                <div class="alerta" role="alert">
                    <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                    <div>
                        <p class="alerta__titulo">Não foi possível mudar o estado</p>
                        <p class="alerta__texto"><?= h($errosAcao['geral']) ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($pedidos): ?>
                <div class="tabela__contentor" id="resultados">
                    <table class="tabela">
                        <caption class="visualmente-oculto">
                            Pedidos da oficina, página <?= $pagina ?> de <?= $totalPaginas ?>,
                            <?= $descricaoOrdem ?>com cliente, equipamento, estado,<?= $consultasDisponiveis ? ' consultas ao PIN atual,' : '' ?>
                            técnico — com há quanto tempo está por atribuir, nos que ainda
                            não têm —, data de entrada e ligações para abrir o pedido e
                            mudar o estado.
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Pedido</th>
                                <th scope="col">Cliente</th>
                                <th scope="col">Equipamento</th>
                                <th scope="col">Estado</th>
                                <?php if ($consultasDisponiveis): ?>
                                    <th scope="col">Consultas <span class="visualmente-oculto">ao PIN atual</span></th>
                                <?php endif; ?>
                                <th scope="col">Técnico</th>
                                <th scope="col">Entrada</th>
                                <th scope="col" class="tabela__acao">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pedidos as $pedido): ?>
                                <tr>
                                    <td class="tabela__id" data-rotulo="Pedido">
                                        <a class="tabela__ligacao"
                                           href="pedido.php?id=<?= (int) $pedido['id'] ?>"
                                           aria-label="Abrir o pedido #<?= (int) $pedido['id'] ?>">#<?= (int) $pedido['id'] ?></a>
                                    </td>
                                    <td class="tabela__cliente" data-rotulo="Cliente"><?= h($pedido['cliente_nome']) ?></td>
                                    <td data-rotulo="Equipamento"><?= h($pedido['marca_modelo']) ?></td>
                                    <td data-rotulo="Estado"><span class="estado <?= $classesEstado[$pedido['estado']] ?? 'estado--recebido' ?>"><?= h($etiquetasEstado[$pedido['estado']] ?? $pedido['estado']) ?></span></td>
                                    <?php if ($consultasDisponiveis): ?>
                                        <?php
                                        $consultasPedido = (int) ($pedido['consultas_pin'] ?? 0);
                                        $ultimaPedido    = $pedido['ultima_consulta'] ?? null;
                                        $temUltima       = $ultimaPedido !== null && $ultimaPedido !== '';

                                        // O momento de que a idade fala, nos dois lados
                                        // da coluna: a última consulta num pedido que já
                                        // foi consultado — num entregue, a última antes
                                        // da entrega, como na ficha — e, num que nunca
                                        // o foi, a emissão do PIN, porque quem nunca o
                                        // abriu espera desde que ele saiu. É a mesma
                                        // referência do filtro e da ordenação, pelo que
                                        // a lista filtrada são exatamente as linhas
                                        // marcadas.
                                        $referenciaPin = $temUltima ? $ultimaPedido : ($pedido['pin_emitido'] ?? null);
                                        $temReferencia = $referenciaPin !== null && $referenciaPin !== '';
                                        $idadePedido   = $temReferencia ? haTempo($referenciaPin) : '—';

                                        // Mais de uma semana sem o cliente abrir o PIN:
                                        // a idade ganha a mesma pílula âmbar com que o
                                        // painel assinala a espera longa, para "há mais
                                        // de uma semana" se ler como o mesmo aviso nos
                                        // dois sítios.
                                        //
                                        // Um pedido entregue fica de fora desta marca:
                                        // o âmbar é um pedido de atenção a quem ainda
                                        // tem o trabalho em mãos, e um pedido que já
                                        // saiu da oficina não pede nada. O talão continua
                                        // válido depois da entrega, por isso a idade
                                        // mostra-se na mesma — e um entregue que ninguém
                                        // consultou ganha a marca cinzenta, logo abaixo.
                                        $consultaAntiga = $temReferencia
                                            && $pedido['estado'] !== 'Entregue'
                                            && (diasDesde($referenciaPin) ?? 0) > LIMITE_ESPERA_LONGA;

                                        // Um pedido entregue que o cliente nunca consultou:
                                        // o trabalho acabou, mas ninguém chegou a ver o que
                                        // se passava. Leva marca — para o caso não se perder
                                        // numa lista de dezenas de linhas —, mas em cinza,
                                        // como o atalho do painel que conta estes: o âmbar
                                        // fica para as esperas que ainda pedem trabalho. Não
                                        // há limite de uma semana: aqui assinala-se a falta
                                        // de consulta, não a sua duração.
                                        $entreguePorConsultar = $temReferencia
                                            && $pedido['estado'] === 'Entregue'
                                            && $consultasPedido === 0;

                                        // Qual das duas pílulas a idade veste. São
                                        // exclusivas — uma pede o pedido em aberto, a outra
                                        // um entregue —, e sem marca a idade fica texto.
                                        $classeIdade = $consultaAntiga
                                            ? ' tabela__consultas-idade--longa'
                                            : ($entreguePorConsultar ? ' tabela__consultas-idade--entregue' : '');

                                        // A idade marcada é também o atalho para ver só
                                        // estes pedidos. A ligação fica fora do span que
                                        // esconde a apresentação dos leitores de ecrã —
                                        // uma ligação focável lá dentro desaparecia-lhes
                                        // do alcance — e, com o filtro já aplicado, a
                                        // idade volta a ser texto: não há razão para uma
                                        // ligação à página onde já se está.
                                        $idadeAtalho = $consultaAntiga && $consultasFiltro !== 'esquecido';

                                        // A marca cinzenta também é um conjunto que
                                        // vale atalho — o painel conta-o e a barra
                                        // de filtros já leva lá —, por isso a mesma
                                        // idade nos entregues abre a lista desses
                                        // pedidos, pela espera do PIN mais antiga.
                                        // Com esse recorte já aberto, a idade volta a
                                        // ser texto, como a âmbar com os esquecidos.
                                        $idadeAtalhoEntregue = $entreguePorConsultar
                                            && !($estadoFiltro === 'Entregue'
                                                 && $consultasFiltro === 'sem');
                                        ?>
                                        <td class="tabela__consultas<?= $consultasPedido === 0 ? ' tabela__consultas--vazia' : '' ?>" data-rotulo="Consultas">
                                            <?php if ($consultasPedido === 0): ?>
                                                <span aria-hidden="true">—</span>
                                                <?php if ($temReferencia): ?>
                                                    <?php if ($idadeAtalho): ?>
                                                        <span class="tabela__consultas-data" aria-hidden="true">· PIN por abrir</span>
                                                        <a class="tabela__consultas-idade tabela__consultas-idade--longa"
                                                           href="<?= h($linkEsquecidos) ?>"><?= h($idadePedido) ?><span class="visualmente-oculto"> — mostrar só os pedidos com o PIN por abrir há mais de uma semana, da espera do PIN mais antiga para a mais recente</span></a>
                                                    <?php elseif ($idadeAtalhoEntregue): ?>
                                                        <span class="tabela__consultas-data" aria-hidden="true">· PIN por abrir</span>
                                                        <a class="tabela__consultas-idade tabela__consultas-idade--entregue"
                                                           href="<?= h($linkEntreguesPin) ?>"><?= h($idadePedido) ?><span class="visualmente-oculto"> — mostrar só os entregues com o PIN por abrir, da espera do PIN mais antiga para a mais recente</span></a>
                                                    <?php else: ?>
                                                        <span class="tabela__consultas-data" aria-hidden="true">· PIN por abrir <span class="tabela__consultas-idade<?= $classeIdade ?>"><?= h($idadePedido) ?></span></span>
                                                    <?php endif; ?>
                                                    <?php /* A data exata ao lado da idade, no mesmo par da
                                                             coluna dos consultados: "há 10 dias" diz de
                                                             relance, mas não quando o talão saiu. O
                                                             <time> dá a data legível às máquinas e aos
                                                             leitores de ecrã, como nas notas dos
                                                             atalhos do painel. */ ?>
                                                    <span class="tabela__consultas-data" aria-hidden="true">(<time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $referenciaPin))) ?>"><?= h(dataHoraCurta($referenciaPin)) ?></time>)</span>
                                                    <span class="visualmente-oculto"><?php if ($entreguePorConsultar): ?>sem consultas; pedido entregue e o PIN ficou por abrir <?= h($idadePedido) ?>, desde <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $referenciaPin))) ?>"><?= h(dataHoraCurta($referenciaPin)) ?></time><?php else: ?>sem consultas; o PIN está por abrir <?= h($idadePedido) ?>, desde <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $referenciaPin))) ?>"><?= h(dataHoraCurta($referenciaPin)) ?></time><?php endif; ?></span>
                                                <?php else: ?>
                                                    <span class="visualmente-oculto">sem consultas</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span aria-hidden="true"><?= $consultasPedido ?></span>
                                                <?php if ($temUltima): ?>
                                                    <?php if ($idadeAtalho): ?>
                                                        <span class="tabela__consultas-data" aria-hidden="true">·</span>
                                                        <a class="tabela__consultas-idade tabela__consultas-idade--longa"
                                                           href="<?= h($linkEsquecidos) ?>"><?= h($idadePedido) ?><span class="visualmente-oculto"> — mostrar só os pedidos com o PIN por abrir há mais de uma semana, da espera do PIN mais antiga para a mais recente</span></a>
                                                        <span class="tabela__consultas-data" aria-hidden="true">(<time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $ultimaPedido))) ?>"><?= h(dataHoraCurta($ultimaPedido)) ?></time>)</span>
                                                    <?php else: ?>
                                                        <span class="tabela__consultas-data" aria-hidden="true">
                                                            · <span class="tabela__consultas-idade<?= $classeIdade ?>"><?= h($idadePedido) ?></span> (<time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $ultimaPedido))) ?>"><?= h(dataHoraCurta($ultimaPedido)) ?></time>)
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <span class="visualmente-oculto">
                                                    <?= $consultasPedido ?> <?= $consultasPedido === 1 ? 'consulta' : 'consultas' ?><?php if ($temUltima): ?>, a última <?= h($idadePedido) ?>, em <?= h(dataCurta($ultimaPedido)) ?>, <?= h(date('H:i', strtotime((string) $ultimaPedido))) ?><?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="tabela__tecnico" data-rotulo="Técnico">
                                        <?php
                                        // Atribuir sem sair da lista: o select traz o
                                        // técnico atual (ou "Por atribuir") e o botão
                                        // grava só essa coluna.
                                        $tecnicoCelula = (int) ($pedido['tecnico_id'] ?? 0);
                                        ?>
                                        <form class="atribuir" method="post" action="<?= h(urlPedidos($pagina)) ?>#lista">
                                            <input type="hidden" name="csrf" value="<?= h(Sessao::csrf()) ?>">
                                            <input type="hidden" name="acao" value="atribuir_tecnico">
                                            <input type="hidden" name="pedido_id" value="<?= (int) $pedido['id'] ?>">
                                            <label class="visualmente-oculto"
                                                   for="tecnico-<?= (int) $pedido['id'] ?>">Técnico do pedido #<?= (int) $pedido['id'] ?></label>
                                            <select class="entrada entrada--tabela"
                                                    id="tecnico-<?= (int) $pedido['id'] ?>" name="tecnico_id">
                                                <option value=""<?= $tecnicoCelula === 0 ? ' selected' : '' ?>>Por atribuir</option>
                                                <?php foreach ($tecnicosFiltro as $tecnicoLinha): ?>
                                                    <option value="<?= (int) $tecnicoLinha['id'] ?>"<?= $tecnicoCelula === (int) $tecnicoLinha['id'] ? ' selected' : '' ?>><?= h($tecnicoLinha['nome']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="botao botao--pequeno botao--fantasma" type="submit">Guardar<span class="visualmente-oculto"> o técnico do pedido #<?= (int) $pedido['id'] ?></span></button>
                                        </form>
                                        <?php if (($pedido['sem_tecnico_desde'] ?? null) !== null): ?>
                                            <?php /* Há quanto tempo espera por um técnico, por
                                                     baixo do select — que já diz o estado, «Por
                                                     atribuir». É a segunda linha da célula do
                                                     painel, com a mesma régua e o mesmo
                                                     vocabulário. */ ?>
                                            <span class="tabela__sem-tecnico">
                                                <span class="visualmente-oculto">Por atribuir </span><?= h(haTempo($pedido['sem_tecnico_desde'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <?php
                                    // Há quanto tempo o pedido está na oficina, ao lado da
                                    // data: a mesma idade que o painel escreve na coluna
                                    // «Na oficina», pela régua desta página — "há 12 dias",
                                    // "há 3 meses" —, para o mesmo tempo se ler da mesma
                                    // maneira nos dois lados. Um pedido entregue já saiu: a
                                    // idade continuaria a crescer depois do trabalho
                                    // acabado, por isso só os abertos a levam.
                                    //
                                    // A partir de uma semana a idade veste a mesma pílula
                                    // âmbar da coluna «Na oficina» do painel, com a
                                    // mesma régua estrita: sete dias certos ainda são
                                    // texto.
                                    $naOficinaDias = $pedido['estado'] !== 'Entregue'
                                        ? diasDesde($pedido['data_entrada'])
                                        : null;
                                    $naOficina = $pedido['estado'] !== 'Entregue'
                                        ? haTempo($pedido['data_entrada'])
                                        : null;
                                    $naOficinaLonga = $naOficinaDias !== null
                                        && $naOficinaDias > LIMITE_ESPERA_LONGA;
                                    ?>
                                    <td class="tabela__data" data-rotulo="Entrada"><?= h(dataCurta($pedido['data_entrada'])) ?><?php if ($naOficina !== null): ?><?php if ($naOficinaLonga): ?><span class="tabela__data-idade tabela__data-idade--longa" aria-hidden="true"><?= h($naOficina) ?></span><?php else: ?><span class="tabela__data-separador" aria-hidden="true"> · </span><span class="tabela__data-idade" aria-hidden="true"><?= h($naOficina) ?></span><?php endif; ?><span class="visualmente-oculto">, na oficina <?= h($naOficina) ?></span><?php endif; ?></td>
                                    <td class="tabela__acao" data-rotulo="Ação">
                                        <a class="ligacao-acao"
                                           href="<?= h(urlPedidos($pagina, ['alterar' => (int) $pedido['id']])) ?>#alterar"
                                           aria-label="Mudar o estado do pedido #<?= (int) $pedido['id'] ?>">
                                            <svg aria-hidden="true"><use href="#icone-editar"/></svg>
                                            <span>Mudar</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="painel__rodape">
                    <span>
                        Página <?= $pagina ?> de <?= $totalPaginas ?>
                        · <?= $totalPedidos ?> <?= $totalPedidos === 1 ? 'pedido' : 'pedidos' ?>
                    </span>

                    <div class="paginacao__botoes">
                        <?php if ($pagina > 1): ?>
                            <a class="botao botao--fantasma botao--pequeno"
                               href="<?= h(urlPedidos($pagina - 1)) ?>#lista" rel="prev">
                                Anterior
                            </a>
                        <?php else: ?>
                            <span class="botao botao--fantasma botao--pequeno" aria-disabled="true">Anterior</span>
                        <?php endif; ?>

                        <?php if ($pagina < $totalPaginas): ?>
                            <a class="botao botao--fantasma botao--pequeno"
                               href="<?= h(urlPedidos($pagina + 1)) ?>#lista" rel="next">
                                Seguinte
                            </a>
                        <?php else: ?>
                            <span class="botao botao--fantasma botao--pequeno" aria-disabled="true">Seguinte</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php /* Com a oficina vazia quem fala é a falta de pedidos: um
                         recorte por cima de uma oficina sem nada não tem a quem
                         culpar, e era isso que punha a barra a pedir o primeiro
                         pedido e o cartão a mandar limpar filtros. Só quando há
                         pedidos que o filtro deixou de fora é que o aviso é dos
                         filtros. */ ?>
                <?php if ($filtrosAtivos !== [] && !$oficinaVazia): ?>
                    <div class="aviso">
                        <span class="aviso__icone" aria-hidden="true">
                            <svg><use href="#icone-pesquisa"/></svg>
                        </span>
                        <p class="aviso__titulo">Nenhum pedido corresponde aos filtros</p>
                        <p class="aviso__texto">Tenta outro nome, NIF, estado, técnico, consultas ou espera.</p>
                        <a class="botao botao--fantasma" href="pedidos.php#lista">Limpar filtros</a>
                    </div>
                <?php else: ?>
                    <div class="aviso">
                        <span class="aviso__icone" aria-hidden="true">
                            <svg><use href="#icone-pedidos"/></svg>
                        </span>
                        <p class="aviso__titulo">Ainda não há pedidos registados</p>
                        <?php /* O convite a criar o primeiro pedido já está na
                                 barra de filtros, com a razão (os números por
                                 estado só aparecem quando houver um) e uma
                                 ligação. Aqui fica só o botão: o mesmo
                                 convite duas vezes numa página é ruído, e
                                 num ecrã de telemóvel cai quase em cima do
                                 outro. */ ?>
                        <a class="botao botao--primario" href="pedido-novo.php">
                            <svg aria-hidden="true"><use href="#icone-mais"/></svg>
                            <span>Novo pedido</span>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    <?php endif; ?>

</main>
</div>
</div>

<script>
/* =====================================================================
   Recorte da lista.
   Aplicar um filtro é um carregamento como outro qualquer: documento novo,
   mesma página. O navegador não tem como adivinhar isso sozinho — o
   endereço só muda depois do "?" e o caminho é o mesmo —, por isso a
   transição leva uma marca apenas quando o entrou é esta mesma página com
   outros parâmetros. Com a marca, painel.css deixa a moldura e o resto do
   conteúdo parados e anima só a tabela dos resultados, em vez de a página
   inteira atravessar para trocar algumas linhas.

   A marca é posta no arranque da página que entra, e não na que sai: o
   `pageswap` também aceita tipos, mas só a partir do `pagereveal` é que a
   transição os tem em conta nas duas metades. Para saber de onde se veio,
   o endereço da última visita a esta lista fica no sessionStorage — é a
   única memória que atravessa documentos da mesma aba.
   ===================================================================== */
(function () {
    "use strict";

    var CHAVE = "fixit:lista-de-onde";
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
       à lista a partir da ficha de um pedido passava por recorte, porque a
       última visita gravada era esta mesma página. */
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
           aberta, não a ser recortada. */
        if (antes.pathname !== caminho || antes.search === window.location.search) {
            return;
        }

        transicao.types.add("recorte");
    });
})();
</script>
</body>
</html>
