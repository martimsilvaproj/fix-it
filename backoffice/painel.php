<?php
declare(strict_types=1);

/**
 * painel.php — Visão geral da oficina FixIt.
 *
 * Esta página é o dashboard administrativo: reúne os números da
 * atividade, a distribuição dos pedidos por estado (Chart.js), os
 * pedidos em aberto pela ordem do ciclo de vida e as últimas trocas de
 * técnico. Todos os valores vêm da base de dados através de PDO/prepared
 * statements; não há números de demonstração.
 *
 * Estrutura:
 *   1. Ligação, ação de mudança de estado (estado-pedido.php) e ciclo
 *      de vida dos pedidos.
 *   2. Consultas (contagens por estado, totais, pedidos abertos e trocas
 *      de técnico).
 *   3. HTML + PHP de apresentação.
 *
 * Requisitos: PHP 8.0+, pdo_mysql e ligação à base de dados fixit.
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/estado-pedido.php';
require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/layout-painel.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';
require_once __DIR__ . '/../nucleo/metricas.php';

// A área da oficina exige sessão: sem login válido, Sessao::exigir()
// manda para o login.php antes de qualquer consulta. A mesma sessão
// guarda o token CSRF do formulário de mudança de estado e a confirmação
// que sobrevive ao redirecionamento (padrão POST → Redirect → GET).
Sessao::iniciar();

/** @var array{id: int, nome: string, email: string, cargo: string} $utilizador */
$utilizador = Sessao::exigir();

// Contador do menu: quantos pedidos do site estão por contactar.
$solicitacoesNovas = solicitacoesNovas();

// Recusa de uma secção reservada a administradores: a página de origem
// manda para aqui com este aviso. É só uma mensagem — não dá acesso a nada.
$avisoPermissao = ($_GET['aviso'] ?? '') === 'sem-permissao';

/** Escapa texto para HTML. Todo o output dinâmico passa por aqui. */
function h(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Data curta em português, no formato usado no painel ("22 out 2026"). */
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

/** Data e hora curtas ("22 out 2026, 14:05"), como na ficha do pedido. */
function dataHora(?string $dataHora): string
{
    if ($dataHora === null || $dataHora === '') {
        return '—';
    }

    $momento = strtotime($dataHora);

    return $momento === false ? '—' : dataCurta($dataHora) . ', ' . date('H:i', $momento);
}

/**
 * Dias de calendário entre hoje e um momento. Null quando não há data
 * legível; uma data no futuro (relógio adiantado) conta como hoje. É a
 * mesma conta da lista e da ficha, para as idades das três páginas saírem
 * da mesma régua.
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
 * Há quanto tempo foi um momento, sem o "há": "hoje", "12 dias" até um
 * mês, "3 meses" até um ano e "2 anos" a partir daí. Até um mês fica em
 * dias — é a unidade em que a oficina age (a semana do âmbar) —, e os
 * meses contam-se no calendário, não a dividir por 30: de 5 de março a
 * 5 de abril vai um mês, com 31 dias ou com 28. O "há" fica com quem
 * escreve a frase: os atalhos compõem-no em haTempo, os avisos já o têm.
 */
function tempoDecorrido(?string $dataHora): string
{
    $dias = diasDesde($dataHora);

    if ($dias === null) {
        return '—';
    }

    if ($dias === 0) {
        return 'hoje';
    }

    if ($dias <= 30) {
        return $dias . ($dias === 1 ? ' dia' : ' dias');
    }

    $momento = strtotime(date('Y-m-d', strtotime((string) $dataHora)));
    $decorrido = (new DateTimeImmutable('today'))->diff(new DateTimeImmutable(date('Y-m-d', $momento)));
    $meses = $decorrido->y * 12 + $decorrido->m;

    if ($meses < 12) {
        return $meses . ($meses === 1 ? ' mês' : ' meses');
    }

    $anos = intdiv($meses, 12);

    return $anos . ($anos === 1 ? ' ano' : ' anos');
}

/**
 * "hoje", "há 12 dias", "há 3 meses", "há 2 anos" — a mesma idade que a
 * lista e a ficha mostram, para a mesma espera se ler da mesma maneira
 * nas três páginas.
 */
function haTempo(?string $dataHora): string
{
    $texto = tempoDecorrido($dataHora);

    return $texto === '—' || $texto === 'hoje' ? $texto : 'há ' . $texto;
}

/**
 * SQL: o instante em que a espera do PIN começou — a última consulta do
 * cliente e, num pedido que ninguém consultou, a emissão do PIN (ou a
 * entrada, num pedido anterior ao histórico). É a data que os avisos
 * mostram ao lado dos dias, e a mesma de que essa contagem sai.
 *
 * O fragmento refere p.id e p.data_entrada: a consulta que o usa tem de
 * trazer a tabela dos pedidos com o alias `p`.
 */
function sqlInicioEsperaPin(): string
{
    return 'COALESCE(
                (SELECT MAX(c.registado_em)
                   FROM consultas_publicas c
                  WHERE c.pedido_id = p.id
                    AND c.registado_em >= COALESCE(
                          (SELECT MAX(h.registado_em)
                             FROM historico_pins h
                            WHERE h.pedido_id = p.id),
                          p.data_entrada)),
                COALESCE(
                    (SELECT MAX(h.registado_em)
                       FROM historico_pins h
                      WHERE h.pedido_id = p.id),
                    p.data_entrada))';
}

/**
 * SQL: há quantos dias o cliente não abre o PIN de um pedido — desde a
 * última consulta e, num pedido que ninguém consultou, desde a emissão
 * do PIN (ou desde a entrada, num pedido anterior ao histórico). É a
 * mesma régua da contagem dos esquecidos, na consulta 11, e a que a
 * coluna das consultas assinala a âmbar na lista.
 */
function sqlDiasSemAbrirPin(): string
{
    return 'DATEDIFF(CURDATE(), DATE(' . sqlInicioEsperaPin() . '))';
}

/**
 * SQL: quantas consultas o cliente fez com o PIN atual de um pedido — desde
 * a última emissão de PIN ou, num pedido anterior ao histórico, desde a
 * entrada. É a mesma janela das consultas que a lista de pedidos conta na
 * coluna e a mesma que o filtro `consultas` aplica; zero quer dizer que o
 * cliente ainda não abriu este PIN.
 *
 * Num pedido entregue a janela fecha na entrega, como na ficha: o que
 * conta é o que o cliente viu enquanto o equipamento estava na oficina.
 * Sem esse fecho, um entregue consultado só depois de sair deixava de
 * entrar no atalho dos entregues com o PIN por abrir, mas continuava
 * marcado na lista — dois números para o mesmo conjunto.
 *
 * O fragmento refere p.id, p.data_entrada e p.data_conclusao: a consulta
 * que o usa tem de trazer a tabela dos pedidos com o alias `p`.
 */
function sqlConsultasPinAtual(): string
{
    return '(SELECT COUNT(*)
               FROM consultas_publicas c
              WHERE c.pedido_id = p.id
                AND c.registado_em >= COALESCE(
                      (SELECT MAX(h.registado_em)
                         FROM historico_pins h
                        WHERE h.pedido_id = p.id),
                      p.data_entrada)
                AND (p.data_conclusao IS NULL OR c.registado_em <= p.data_conclusao))';
}

// A partir de quantos dias a espera deixa de ser normal — mais de uma
// semana — e passa a estar assinalada na lista de pedidos abertos.
const LIMITE_ESPERA_LONGA = 7;

// ---------------------------------------------------------------------
// Ciclo de vida do pedido, pela ordem em que acontece.
//   - etiquetas: texto apresentado ao utilizador (o ENUM tem os valores
//     sem acento, "Em Diagnostico");
//   - classes: o badge correspondente em painel.css;
//   - cores: a versão sólida do mesmo matiz, para as fatias do gráfico.
//     Assim o gráfico e os badges contam a mesma história cromaticamente.
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

$coresEstado = [
    'Recebido'       => '#5C7695',
    'Em Diagnostico' => '#C99433',
    'Em Reparacao'   => '#2F6DB5',
    'Pronto'         => '#35946A',
    'Entregue'       => '#A9B4C0',
];

// Ordem do ciclo de vida usada na lista de pedidos abertos. Sai das
// chaves de $etiquetasEstado, pela ordem em que estão definidas: o
// gráfico e a lista seguem assim a mesma sequência, sem uma segunda
// tabela de estados para manter em sincronia.
$ordemEstadosSql = implode(', ', array_map(
    static fn (string $estado): string => "'" . $estado . "'",
    array_keys($etiquetasEstado)
));

// Uma entrada por estado, mesmo que a base de dados ainda não tenha
// nenhum pedido nesse estado: o gráfico e a legenda ficam sempre com
// as cinco posições do ciclo.
$contagens = array_fill_keys(array_keys($etiquetasEstado), 0);

$totalPedidos    = 0;
$pedidosAtivos   = 0;
$emDiagnostico   = 0;
$novosSemana     = 0;
$clientesTotal   = 0;
$clientesMes     = 0;
$pedidosAbertos  = [];
// Atalhos do cabeçalho de "Pedidos abertos": há quantos pedidos em aberto
// por atribuir, quantos já passaram o limite de espera, quantos o cliente
// já consultou e quantos ainda não. Cada número é o tamanho da lista que o
// atalho abre, e por isso conta pedidos em aberto — o âmbito da secção —,
// com uma exceção: o último conta os entregues, que é o conjunto que ele
// abre.
$abertosEsperaLonga  = 0;
$abertosPorAtribuir  = 0;
$abertosConsultados  = 0;
$abertosPorConsultar = 0;
// Os que já passaram o limite sem o cliente abrir o PIN: mais de sete dias
// desde a última consulta e, num que ninguém abriu, desde que ele saiu. É
// exatamente o recorte que a barra de filtros da lista conta como
// "sem consulta há mais de uma semana" — a mesma âmbar da coluna —, e não
// uma versão mais estreita dele: um número há de querer dizer o mesmo nos
// dois ecrãs.
$abertosEsquecidos   = 0;
// E o mesmo recorte do lado de lá da oficina: pedidos já entregues cujo PIN
// o cliente nunca abriu (ver a consulta 11b). É o único dos atalhos que
// conta fora dos pedidos em aberto, e por isso não entra na soma que mede a
// fila — mas o número continua a ser o tamanho da lista que ele abre. Um
// pedido entregue não pede trabalho a ninguém; mostra quem acompanhou o
// pedido até ao fim e quem só o viu depois de pronto.
$entreguesPinPorAbrir = 0;
// Quando foi a última consulta do pedido consultado há mais tempo (null
// quando não há nenhum). É a data que encabeça a lista que o atalho dos
// consultados abre. Guarda-se o instante, não o número de dias: a idade é
// escrita por haTempo(), para um pedido de meses se ler aqui como na lista
// e na ficha — "há 3 meses", e não "há 92 dias".
$dataConsultaMaisAntiga = null;
// O outro lado da mesma pergunta: dos pedidos por consultar, desde quando
// espera uma consulta o que está à espera há mais tempo (null quando não
// há nenhum). Conta desde quando corre o PIN atual de cada um — a última
// emissão de PIN, ou a entrada num pedido anterior ao histórico.
$dataSemConsultaMaisAntigo = null;
// E a mesma pergunta para o atalho dos esquecidos: desde quando espera o
// pedido desse recorte que espera há mais tempo (null quando não há
// nenhum). É a idade da primeira linha da lista que ele abre.
$dataEsquecidoMaisAntigo = null;
// Pedidos entregues por idade da entrega (ver a consulta 3b): até uma
// semana, do oitavo dia ao trigésimo, e mais de um mês. É o que o anel dos
// entregues mostra — o estado é o mesmo para todos, a idade não.
$contagensEntrega = ['recente' => 0, 'mes' => 0, 'antigo' => 0];
// Avisos do dia lidos para quem está a usar a sessão (ver a consulta 12).
$avisosPin       = [];
$trocasTecnico   = [];
$semTecnicoDesde = [];
$erroDados       = '';

// Atribuição de técnico feita na própria lista de abertos.
$tecnicosAtribuir       = [];
$tecnicosAtribuirPorId  = [];
$erroAtribuicao         = '';

// Distingue "não há trocas registadas" de "não consigo saber" (base sem
// a migração do histórico de técnicos). Só no primeiro caso é honesto
// dizer que um pedido está por atribuir desde a entrada.
$historicoTecnicosOk = true;

// Formulário de mudança de estado (só existe quando pedido).
$acaoEstado = [];
$pedidoAcao = null;
$errosAcao  = [];

// Confirmação deixada pelo POST antes do redirecionamento.
$sucessoAcao = AcaoEstadoPedido::consumirFlash();

try {
    $pdo = obterConexao();

    // 0. Técnicos da oficina: alimentam o select da célula do técnico —
    //    atribuir, trocar ou devolver a "por atribuir" — e validam o
    //    valor enviado. Uma entrada por utilizador, pela ordem alfabética
    //    que o select mostra.
    $consulta = $pdo->prepare('SELECT id, nome FROM utilizadores ORDER BY nome');
    $consulta->execute();

    foreach ($consulta->fetchAll() as $linhaTecnico) {
        $tecnicosAtribuir[] = $linhaTecnico;
        $tecnicosAtribuirPorId[(int) $linhaTecnico['id']] = (string) $linhaTecnico['nome'];
    }

    // 1. Técnico do pedido mudado na lista de abertos: atribuir a quem não
    //    tem, trocar quem tem, ou devolver a "por atribuir" (valor vazio).
    //    Mesmo padrão da lista de pedidos: token CSRF e POST → Redirect →
    //    GET, para um refresh não repetir a mudança.
    if (($_POST['acao'] ?? null) === 'atribuir_tecnico') {
        $pedidoAtribuir = $_POST['pedido_id'] ?? null;
        $pedidoAtribuir = is_string($pedidoAtribuir) && ctype_digit($pedidoAtribuir)
            ? (int) $pedidoAtribuir
            : 0;

        $tecnicoEnviado = $_POST['tecnico_id'] ?? null;
        $tecnicoNovo = is_string($tecnicoEnviado)
            && ctype_digit($tecnicoEnviado)
            && (int) $tecnicoEnviado > 0
            ? (int) $tecnicoEnviado
            : 0;

        $tokenAtribuir = $_POST['csrf'] ?? null;

        if (!Sessao::csrfValido(is_string($tokenAtribuir) ? $tokenAtribuir : null)) {
            $erroAtribuicao = 'A sessão expirou. Recarrega a página e tenta novamente.';
        } elseif ($pedidoAtribuir <= 0) {
            $erroAtribuicao = 'Não foi possível identificar o pedido a atribuir.';
        } elseif ($tecnicoNovo > 0 && !isset($tecnicosAtribuirPorId[$tecnicoNovo])) {
            $erroAtribuicao = 'Escolhe um técnico da lista.';
        } else {
            try {
                $consulta = $pdo->prepare(
                    'SELECT id, tecnico_id FROM pedidos WHERE id = ? LIMIT 1'
                );
                $consulta->execute([$pedidoAtribuir]);
                $pedidoAtribuirLinha = $consulta->fetch();

                if (!is_array($pedidoAtribuirLinha)) {
                    $erroAtribuicao = 'O pedido #' . $pedidoAtribuir . ' já não existe.';
                } else {
                    $tecnicoAtual = (int) ($pedidoAtribuirLinha['tecnico_id'] ?? 0);

                    // Sem mudança não há nada para gravar: mais vale dizê-lo
                    // do que escrever uma linha de histórico a repetir o que
                    // já estava.
                    if ($tecnicoAtual === $tecnicoNovo) {
                        $erroAtribuicao = $tecnicoNovo > 0
                            ? 'O pedido #' . $pedidoAtribuir . ' já está atribuído a '
                                . $tecnicosAtribuirPorId[$tecnicoNovo] . '.'
                            : 'O pedido #' . $pedidoAtribuir . ' já está por atribuir.';
                    } else {
                        $pdo->beginTransaction();

                        $atualizar = $pdo->prepare(
                            'UPDATE pedidos SET tecnico_id = ? WHERE id = ?'
                        );
                        $atualizar->execute([
                            $tecnicoNovo > 0 ? $tecnicoNovo : null,
                            $pedidoAtribuir,
                        ]);

                        // A mudança e o registo no histórico são uma só
                        // transação. Opcional como o resto: sem a tabela a
                        // mudança não é bloqueada, fica sem registo e a falha
                        // vai para o log.
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
                            error_log('[FixIt][Painel][Histórico técnicos] ' . $excecao->getMessage());
                        }

                        $pdo->commit();

                        AcaoEstadoPedido::guardarFlash(
                            'Pedido #' . $pedidoAtribuir . ' '
                            . ($tecnicoNovo > 0
                                ? 'atribuído a ' . $tecnicosAtribuirPorId[$tecnicoNovo]
                                : 'ficou por atribuir')
                            . '.'
                        );

                        header('Location: painel.php#abertos', true, 303);
                        exit;
                    }
                }
            } catch (Throwable $falhaAtribuicao) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log('[FixIt][Painel][Atribuir] ' . $falhaAtribuicao->getMessage());
                $erroAtribuicao = 'Não foi possível guardar a atribuição. Tenta novamente.';
            }
        }
    }

    // 2. Mudança de estado (GET ?alterar=ID ou POST do formulário).
    //    O módulo valida, grava, escreve a confirmação e redireciona.
    $acaoEstado = AcaoEstadoPedido::preparar($pdo, $etiquetasEstado, 'painel.php#abertos');
    $pedidoAcao = $acaoEstado['pedido'];
    $errosAcao  = $acaoEstado['erros'];

    // 3. Pedidos por estado. É esta consulta que alimenta o gráfico:
    //    uma linha por estado existente, agregada pelo próprio MySQL.
    $consulta = $pdo->prepare(
        'SELECT estado, COUNT(*) AS total
           FROM pedidos
          GROUP BY estado'
    );
    $consulta->execute();

    foreach ($consulta->fetchAll() as $linha) {
        $estado = (string) $linha['estado'];
        if (isset($contagens[$estado])) {
            $contagens[$estado] = (int) $linha['total'];
        }
    }

    // 3b. Idade das entregas: há quantos dias cada pedido entregue saiu da
    //     oficina, contados por degraus. A data é a da entrega, escrita na
    //     coluna quando o estado passa a Entregue; num pedido que já estava
    //     entregue antes de a coluna ser escrita vale a passagem a Entregue
    //     do histórico, e na falta das duas a entrada — o mais cedo que ele
    //     pode ter saído, para nenhum entregue ficar sem lugar no anel.
    $consulta = $pdo->prepare(
        "SELECT CASE
                    WHEN dias <= 7  THEN 'recente'
                    WHEN dias <= 30 THEN 'mes'
                    ELSE 'antigo'
                END AS escalao,
                COUNT(*) AS total
           FROM (SELECT DATEDIFF(CURDATE(), DATE(COALESCE(
                        p.data_conclusao,
                        (SELECT MAX(h.registado_em) FROM historico_pedidos h
                          WHERE h.pedido_id = p.id
                            AND h.estado = 'Entregue'),
                        p.data_entrada))) AS dias
                   FROM pedidos p
                  WHERE p.estado = 'Entregue') AS entregas
          GROUP BY escalao"
    );
    $consulta->execute();

    foreach ($consulta->fetchAll() as $linha) {
        $escalao = (string) $linha['escalao'];
        if (isset($contagensEntrega[$escalao])) {
            $contagensEntrega[$escalao] = (int) $linha['total'];
        }
    }

    $totalPedidos  = array_sum($contagens);
    $pedidosAtivos = $totalPedidos - $contagens['Entregue'];
    $emDiagnostico = $contagens['Em Diagnostico'];

    // 4. Pedidos novos nos últimos 7 dias.
    $consulta = $pdo->prepare(
        'SELECT COUNT(*)
           FROM pedidos
          WHERE data_entrada >= (NOW() - INTERVAL 7 DAY)'
    );
    $consulta->execute();
    $novosSemana = (int) $consulta->fetchColumn();

    // 5. Clientes registados (total e no mês corrente).
    $consulta = $pdo->prepare('SELECT COUNT(*) FROM clientes');
    $consulta->execute();
    $clientesTotal = (int) $consulta->fetchColumn();

    $consulta = $pdo->prepare(
        'SELECT COUNT(*)
           FROM clientes
          WHERE YEAR(criado_em) = YEAR(CURDATE())
            AND MONTH(criado_em) = MONTH(CURDATE())'
    );
    $consulta->execute();
    $clientesMes = (int) $consulta->fetchColumn();

    // 6. Até cinco pedidos em aberto, com o cliente já resolvido. O técnico
    //    vai pelo id — é ele que o select da célula pré-seleciona —, o nome
    //    só é preciso na lista de técnicos que o formulário mostra. A ordem
    //    é a do ciclo de vida — recebido, em diagnóstico, em reparação,
    //    pronto — porque é a fila de trabalho do painel; os entregues só
    //    aparecem na lista de pedidos. Dentro do mesmo estado fica primeiro
    //    o que entrou há mais tempo.
    $consulta = $pdo->prepare(
        'SELECT p.id, p.estado, p.data_entrada, p.tecnico_id,
                DATEDIFF(CURDATE(), DATE(p.data_entrada)) AS dias_aberto,
                e.marca_modelo,
                c.nome AS cliente_nome
           FROM pedidos p
           JOIN equipamentos e ON e.id = p.equipamento_id
           JOIN clientes c     ON c.id = e.cliente_id
          WHERE p.estado <> \'Entregue\'
       ORDER BY FIELD(p.estado, ' . $ordemEstadosSql . '),
                p.data_entrada ASC, p.id ASC
          LIMIT 5'
    );
    $consulta->execute();
    $pedidosAbertos = $consulta->fetchAll();

    // 7. Últimas trocas de técnico, em todos os pedidos, com quem estava,
    //    quem ficou e quem fez a troca. Opcional como no histórico da
    //    ficha: numa base anterior à migração a tabela não existe e o
    //    painel mostra o resto em vez de falhar por completo.
    try {
        $consulta = $pdo->prepare(
            'SELECT h.id, h.registado_em,
                    p.id AS pedido_id,
                    e.marca_modelo,
                    c.nome AS cliente_nome,
                    antes.nome AS anterior_nome,
                    novo.nome  AS novo_nome,
                    autor.nome AS autor_nome
               FROM historico_tecnicos h
               JOIN pedidos p       ON p.id = h.pedido_id
               JOIN equipamentos e  ON e.id = p.equipamento_id
               JOIN clientes c      ON c.id = e.cliente_id
          LEFT JOIN utilizadores antes ON antes.id = h.tecnico_anterior
          LEFT JOIN utilizadores novo  ON novo.id  = h.tecnico_novo
          LEFT JOIN utilizadores autor ON autor.id = h.autor_id
           ORDER BY h.registado_em DESC, h.id DESC
              LIMIT 5'
        );
        $consulta->execute();
        $trocasTecnico = $consulta->fetchAll();

        // 8. Desde quando cada pedido ficou sem técnico: a última vez
        //    que uma troca o deixou por atribuir. Pedidos que nunca
        //    tiveram técnico não têm linha nenhuma e contam desde a
        //    entrada — é a `data_entrada` que já vem na consulta 4. O
        //    instante, e não os dias, para a idade ser escrita por
        //    haTempo() como no resto do painel.
        $consulta = $pdo->prepare(
            'SELECT h.pedido_id,
                    MAX(h.registado_em) AS desde
               FROM historico_tecnicos h
              WHERE h.tecnico_novo IS NULL
           GROUP BY h.pedido_id'
        );
        $consulta->execute();

        foreach ($consulta->fetchAll() as $linha) {
            $semTecnicoDesde[(int) $linha['pedido_id']] = $linha['desde'] ?? null;
        }
    } catch (PDOException $excecao) {
        // Sem a tabela não se sabe desde quando está por atribuir; a
        // célula fica só com "Por atribuir", como antes.
        $historicoTecnicosOk = false;
        error_log('[FixIt][Painel][Técnicos] ' . $excecao->getMessage());
    }

    // 9. Pedidos em aberto há mais do que uma semana: é o número que o
    //    atalho do cabeçalho mostra. A regra é a mesma da pílula de cada
    //    linha (o `dias_aberto` da consulta 6), aplicada à tabela toda — a
    //    lista em baixo só traz cinco, o atalho conta todos.
    $consulta = $pdo->prepare(
        'SELECT COUNT(*)
           FROM pedidos
          WHERE estado <> \'Entregue\'
            AND DATEDIFF(CURDATE(), DATE(data_entrada)) > ' . LIMITE_ESPERA_LONGA
    );
    $consulta->execute();
    $abertosEsperaLonga = (int) $consulta->fetchColumn();

    // 10. Pedidos em aberto sem técnico.
    $consulta = $pdo->prepare(
        'SELECT COUNT(*)
           FROM pedidos
          WHERE estado <> \'Entregue\'
            AND tecnico_id IS NULL'
    );
    $consulta->execute();
    $abertosPorAtribuir = (int) $consulta->fetchColumn();

    // 11. Os dois lados da consulta: quem o cliente já abriu com o PIN
    //     atual e quem ainda não — mais a idade que cada lado mostra ao
    //     lado do número. Do lado dos consultados é a data da consulta
    //     mais antiga; do lado dos por consultar, como não há consulta
    //     nenhuma para datar, é desde quando corre o PIN atual: há quantos
    //     dias espera aquele que espera desde mais cedo.
    //     A janela é a mesma de pedidos.php (`sqlConsultasPinAtual`): desde
    //     a última emissão do PIN, ou desde a entrada num pedido anterior
    //     ao histórico. A data por pedido é a da última consulta dele, a
    //     mesma (`sqlUltimaConsultaPin`) por que a lista ordena: a mais
    //     antiga de todas é, portanto, a primeira linha dessa lista.
    //     As tabelas vêm de migrações e podem não existir: sem elas não se
    //     sabe quem consultou, os atalhos desaparecem e a falha vai para o
    //     log.
    try {
        // O início da janela sai à parte, uma vez por pedido, para a
        // subconsulta da última consulta o poder reutilizar em vez de
        // repetir a procura da última emissão de PIN.
        //
        // O terceiro número é o dos esquecidos: os pedidos em aberto cujo
        // PIN o cliente não abre há mais de uma semana — quer nunca o tenha
        // aberto, quer o tenha aberto há muito. A referência é a última
        // consulta e, num que ninguém abriu, a emissão do PIN: a régua é a
        // de `sqlDiasSemAbrirPin`, a mesma da âmbar na coluna e a do número
        // que a barra de filtros da lista mostra. Por isso a subconsulta
        // devolve as duas datas, e a conta dos dias é feita sobre elas,
        // como em pedidos.php.
        $consulta = $pdo->prepare(
            'SELECT
                COUNT(*) AS abertos,
                SUM(ultima IS NOT NULL) AS consultados,
                SUM(sem_abrir > ' . LIMITE_ESPERA_LONGA . ') AS esquecidos,
                MIN(CASE WHEN sem_abrir > ' . LIMITE_ESPERA_LONGA . '
                         THEN referencia END) AS data_esquecido,
                MIN(ultima) AS data_mais_antiga,
                MIN(CASE WHEN ultima IS NULL THEN referencia END) AS data_sem_consulta
               FROM (
                     SELECT consultas.inicio,
                            consultas.ultima,
                            COALESCE(consultas.ultima, consultas.inicio) AS referencia,
                            DATEDIFF(
                                CURDATE(),
                                DATE(COALESCE(consultas.ultima, consultas.inicio))
                            ) AS sem_abrir
                       FROM (
                             SELECT janela.inicio,
                                    (SELECT MAX(c.registado_em)
                                       FROM consultas_publicas c
                                      WHERE c.pedido_id = janela.id
                                        AND c.registado_em >= janela.inicio) AS ultima
                               FROM (
                                     SELECT p.id,
                                            COALESCE((SELECT MAX(h.registado_em)
                                                        FROM historico_pins h
                                                       WHERE h.pedido_id = p.id),
                                                     p.data_entrada) AS inicio
                                       FROM pedidos p
                                      WHERE p.estado <> \'Entregue\'
                                    ) AS janela
                            ) AS consultas
                    ) AS espera'
        );
        $consulta->execute();
        $contagem = $consulta->fetch();

        $abertosConsultados = (int) ($contagem['consultados'] ?? 0);
        $abertosPorConsultar = (int) ($contagem['abertos'] ?? 0) - $abertosConsultados;
        $abertosEsquecidos = (int) ($contagem['esquecidos'] ?? 0);
        // A espera do mais antigo deste recorte, para o atalho não mostrar
        // só um número: é a idade da primeira linha da lista que ele abre,
        // ordenada pela espera do PIN — a mesma que a coluna assinala a
        // âmbar, no pedido que espera há mais tempo. Vem como instante, e
        // não como um número de dias já contado na base: quem escreve a
        // idade é haTempo(), para um pedido de meses se ler "há 3 meses"
        // aqui como na lista e na ficha, e não "há 92 dias". A data exata
        // fica ao lado, no texto que só os leitores de ecrã ouvem.
        $dataEsquecidoMaisAntigo = $abertosEsquecidos > 0
            ? ($contagem['data_esquecido'] ?? null)
            : null;
        // Sem consultas não há "mais antiga": o MIN vem NULL e o atalho
        // nem chega a aparecer, mas o valor fica nulo em vez de zero.
        $dataConsultaMaisAntiga = $abertosConsultados > 0
            ? ($contagem['data_mais_antiga'] ?? null)
            : null;
        // Do outro lado o MIN ignora quem já foi consultado, por isso o
        // nulo só aparece quando não há mesmo nenhum por consultar.
        $dataSemConsultaMaisAntigo = $abertosPorConsultar > 0
            ? ($contagem['data_sem_consulta'] ?? null)
            : null;

        // 11b. O outro lado do atalho dos esquecidos: pedidos já
        //      entregues cujo PIN o cliente nunca abriu. A régua é a mesma
        //      da consulta acima, sem o limite de dias e com o estado ao
        //      contrário — num entregue não há espera a medir, há só o
        //      registo de que o cliente nunca chegou a ver o que se passava
        //      com o equipamento. É o recorte exato que o atalho abre
        //      (`consultas=sem` com `estado=Entregue`), para o número e a
        //      lista contarem o mesmo.
        $consulta = $pdo->prepare(
            'SELECT COUNT(*)
               FROM pedidos p
              WHERE p.estado = \'Entregue\'
                AND ' . sqlConsultasPinAtual() . ' = 0'
        );
        $consulta->execute();
        $entreguesPinPorAbrir = (int) $consulta->fetchColumn();
    } catch (PDOException $excecao) {
        $abertosConsultados        = 0;
        $abertosPorConsultar       = 0;
        $abertosEsquecidos         = 0;
        $entreguesPinPorAbrir      = 0;
        $dataConsultaMaisAntiga    = null;
        $dataSemConsultaMaisAntigo = null;
        $dataEsquecidoMaisAntigo   = null;
        error_log('[FixIt][Painel][Consultas] ' . $excecao->getMessage());
    }

    // 12. Avisos do PIN, em dois tempos: criar os do dia e ler os de quem
    //     está a usar a sessão.
    //
    //     O aviso é para quem registou o pedido (pedidos.registado_por) e
    //     sai quando o cliente não abre o PIN há mais de uma semana — a
    //     mesma régua da contagem acima: dias de calendário desde a última
    //     consulta e, num pedido que ninguém consultou, desde a emissão do
    //     PIN. Só pedidos em aberto, que um entregue já saiu da oficina.
    //
    //     O "uma vez por dia, em vez de repetir" não vive numa marca de
    //     dia guardada: vive na chave única (pedido, dia) da tabela dos
    //     avisos. Por isso a verificação corre sempre que o painel abre —
    //     e pode correr com duas pessoas a abrir ao mesmo tempo —, que o
    //     dia de hoje fica com um aviso por pedido e mais nenhum: o IGNORE
    //     e a chave tratam disso. Um pedido que se resolve (o cliente abre
    //     o PIN) deixa de gerar avisos, e o de hoje deixa de ser mostrado,
    //     para o painel não acusar uma espera que já acabou.
    //
    //     A tabela vem de uma migração: sem ela o painel mostra o resto, a
    //     secção não aparece e a falha fica no log — como nos históricos.
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO avisos_pin (pedido_id, destinatario_id, dia)
             SELECT p.id, p.registado_por, CURDATE()
               FROM pedidos p
              WHERE p.estado <> \'Entregue\'
                AND p.registado_por IS NOT NULL
                AND ' . sqlDiasSemAbrirPin() . ' > ' . LIMITE_ESPERA_LONGA
        )->execute();

        $consulta = $pdo->prepare(
            'SELECT a.id, a.criado_em,
                    p.id AS pedido_id,
                    e.marca_modelo,
                    c.nome AS cliente_nome,
                    ' . sqlInicioEsperaPin() . ' AS espera_desde
               FROM avisos_pin a
               JOIN pedidos p      ON p.id = a.pedido_id
               JOIN equipamentos e ON e.id = p.equipamento_id
               JOIN clientes c     ON c.id = e.cliente_id
              WHERE a.dia = CURDATE()
                AND a.destinatario_id = ?
                AND p.estado <> \'Entregue\'
                AND ' . sqlDiasSemAbrirPin() . ' > ' . LIMITE_ESPERA_LONGA . '
           ORDER BY a.criado_em DESC, a.id DESC
              LIMIT 5'
        );
        $consulta->execute([(int) $utilizador['id']]);
        $avisosPin = $consulta->fetchAll();
    } catch (PDOException $excecao) {
        $avisosPin = [];
        error_log('[FixIt][Painel][Avisos] ' . $excecao->getMessage());
    }
} catch (Throwable $erro) {
    // O detalhe técnico fica no log; a página mostra uma mensagem útil.
    error_log('[FixIt][Painel] ' . $erro->getMessage());
    $erroDados = 'Confirma que o servidor MySQL está ligado e que a base de dados fixit foi importada.';
}

// ---------------------------------------------------------------------
// Receita: os 12 meses que se veem e os 12 do ano anterior.
//
// A janela tem 24 meses para que a comparação com o ano anterior seja
// uma leitura direta do mesmo array, sem deslocar índices à mão. A série
// fica alinhada com a janela por metricasPreencher(): um mês sem pedidos
// entregues vale zero, e não desaparece do eixo.
//
// A receita é a soma dos orçamentos dos pedidos entregues no mês — o
// valor que saiu da oficina com o equipamento. Não é o que o cliente
// pagou: a base não guarda pagamentos, e o gráfico não inventa esse dado.
// A legenda da secção di-lo por palavras.
// ---------------------------------------------------------------------
$receitaErro = '';
$mesesJanela = metricasJanela(24);
$mapaReceita = [];
$mapaClientes = [];
$primeiroRegisto = null;

try {
    $pdo = obterConexao();
    $inicioJanela = $mesesJanela[0]['chave'] . '-01 00:00:00';

    // Pedidos entregues por mês de conclusão (ou de entrada, num pedido
    // anterior à coluna data_conclusao).
    $consulta = $pdo->prepare(
        "SELECT DATE_FORMAT(COALESCE(data_conclusao, data_entrada), '%Y-%m') AS mes,
                COUNT(*) AS pedidos,
                SUM(COALESCE(orcamento, 0)) AS receita
           FROM pedidos
          WHERE estado = 'Entregue'
            AND COALESCE(data_conclusao, data_entrada) >= ?
       GROUP BY mes"
    );
    $consulta->execute([$inicioJanela]);

    foreach ($consulta->fetchAll() as $linha) {
        $mapaReceita[(string) $linha['mes']] = [
            'pedidos' => (int) $linha['pedidos'],
            'receita' => (float) $linha['receita'],
        ];
    }

    // Clientes novos por mês de registo.
    $consulta = $pdo->prepare(
        "SELECT DATE_FORMAT(criado_em, '%Y-%m') AS mes, COUNT(*) AS total
           FROM clientes
          WHERE criado_em >= ?
       GROUP BY mes"
    );
    $consulta->execute([$inicioJanela]);

    foreach ($consulta->fetchAll() as $linha) {
        $mapaClientes[(string) $linha['mes']] = (int) $linha['total'];
    }

    // Desde quando há registos: a frase da secção diz o que a base tem,
    // para ninguém ler uma linha a cero como se fosse uma queda.
    $consulta = $pdo->query('SELECT MIN(data_entrada) FROM pedidos');
    $primeiroRegisto = $consulta->fetchColumn() ?: null;
} catch (Throwable $excecao) {
    error_log('[FixIt][Painel][Receita] ' . $excecao->getMessage());
    $receitaErro = 'Não foi possível ler os valores do gráfico de receita. Tenta novamente daqui a pouco.';
}

$serieReceita  = metricasPreencher($mesesJanela, $mapaReceita, 'receita');
$seriePedidos  = metricasPreencher($mesesJanela, $mapaReceita, 'pedidos');
$serieClientes = metricasPreencher($mesesJanela, $mapaClientes);

$receita = [
    'meses'   => array_column(array_slice($mesesJanela, 12), 'curto'),
    'longos'  => array_column(array_slice($mesesJanela, 12), 'longo'),
    'atual'   => [
        'receita'  => array_slice($serieReceita, 12),
        'pedidos'  => array_slice($seriePedidos, 12),
        'clientes' => array_slice($serieClientes, 12),
    ],
    'anterior' => [
        'receita'  => array_slice($serieReceita, 0, 12),
        'pedidos'  => array_slice($seriePedidos, 0, 12),
        'clientes' => array_slice($serieClientes, 0, 12),
    ],
    'variacoes' => [
        'receita'  => array_slice(metricasVariacoes($serieReceita), 12),
        'pedidos'  => array_slice(metricasVariacoes($seriePedidos), 12),
        'clientes' => array_slice(metricasVariacoes($serieClientes), 12),
    ],
    'totais' => [
        'receita'  => array_sum(array_slice($serieReceita, 12)),
        'pedidos'  => array_sum(array_slice($seriePedidos, 12)),
        'clientes' => array_sum(array_slice($serieClientes, 12)),
    ],
];

// Há algum registo na janela do ano anterior? Se não houver, a linha
// tracejada fica colada ao eixo e o texto da secção diz porquê.
$receita['temAnoAnterior'] = array_sum($receita['anterior']['receita'])
    + array_sum($receita['anterior']['pedidos'])
    + array_sum($receita['anterior']['clientes']) > 0;

// Total do último mês da janela, para a frase que os leitores de ecrã ouvem.
$ultimoIndice = count($receita['meses']) - 1;
$resumoReceita = 'Receita por mês nos últimos 12 meses. Total de '
    . metricasMoeda((float) $receita['totais']['receita']) . '. '
    . 'O mês mais recente é ' . $receita['longos'][$ultimoIndice] . ', com '
    . metricasMoeda((float) $receita['atual']['receita'][$ultimoIndice]) . '. '
    . 'Os valores de cada mês estão na tabela abaixo do gráfico.';

// A legenda do gráfico abre nos dois lados da oficina, como o resumo da
// barra da lista: o que está na oficina e o que já saiu. Um entregue não
// pede nada a ninguém e, lido na mesma lista dos que estão em trabalho,
// não se sabe qual é qual. Cada lado é um anel: o da oficina por fora, com
// uma fatia por estado, e o dos entregues por dentro. Por isso a
// percentagem de cada estado é a do seu anel — a fatia que se vê — e não a
// do total, que somava números de dois anéis diferentes. A linha do meio do
// círculo diz a divisão em duas palavras.
$gruposGrafico = [
    ['rotulo' => 'Na oficina', 'estados' => [], 'total' => 0],
    ['rotulo' => 'Entregues',  'estados' => [], 'total' => 0],
];

foreach (array_keys($etiquetasEstado) as $estado) {
    $ladoGrafico = $estado === 'Entregue' ? 1 : 0;
    $gruposGrafico[$ladoGrafico]['estados'][] = $estado;
    $gruposGrafico[$ladoGrafico]['total'] += (int) $contagens[$estado];
}

// Percentagem de cada estado dentro do seu lado (para a legenda). Um lado
// sem pedidos não divide por zero: mostra 0% e o anel não se desenha. Só o
// da oficina as usa: o dos entregues mostra a idade da entrega, com os seus
// próprios degraus (ver $fatiasEntrega).
$percentagens = [];
foreach ($gruposGrafico as $grupoGrafico) {
    foreach ($grupoGrafico['estados'] as $estado) {
        $percentagens[$estado] = $grupoGrafico['total'] > 0
            ? (int) round(($contagens[$estado] / $grupoGrafico['total']) * 100)
            : 0;
    }
}

// O anel dos entregues não se divide por estado — estão todos no mesmo — e
// divide-se pela idade da entrega: o que saiu esta semana, o que saiu no
// mês e o que já saiu há mais de um mês. Uma cor só dizia "cinco entregues"
// e nada sobre quando, que é o que distingue um entregue do outro. As três
// cores são o mesmo cinza do estado, do tom do badge para os mais escuros.
// Nenhuma fatia leva a uma lista: a barra de filtros sabe filtrar por estado,
// não por quando o pedido saiu.
// Os rótulos seguem a régua das idades do resto do painel: dias até um mês
// (é a unidade em que a oficina age) e meses a partir daí. O último degrau
// dizia «Mais de 30 dias» — em dias, uma idade que o painel, a lista e a
// ficha escrevem em meses ao lado.
$escaloesEntrega = [
    'recente' => ['rotulo' => 'Até 7 dias',    'cor' => '#A9B4C0'],
    'mes'     => ['rotulo' => '8 a 30 dias',   'cor' => '#7F8E9E'],
    'antigo'  => ['rotulo' => 'Mais de 1 mês', 'cor' => '#586677'],
];

$totalEntregues = $gruposGrafico[1]['total'];
$fatiasEntrega  = [];
foreach ($escaloesEntrega as $chaveEscalao => $escalaoGrafico) {
    $quantidadeEscalao = (int) $contagensEntrega[$chaveEscalao];

    $fatiasEntrega[] = $escalaoGrafico + [
        'total'       => $quantidadeEscalao,
        'percentagem' => $totalEntregues > 0
            ? (int) round(($quantidadeEscalao / $totalEntregues) * 100)
            : 0,
    ];
}

// Os dois anéis, na ordem em que o Chart.js os empilha: o primeiro fica por
// fora. Por fora vai a oficina, que é a parte que se clica estado a estado e
// a que tem mais circunferência; por dentro, encostado ao número do meio,
// fica o lado dos entregues. Um lado sem pedidos não é um anel vazio: sai da
// lista, e o gráfico fica com um só.
$aneisGrafico = [];
foreach ($gruposGrafico as $indiceGrupo => $grupoGrafico) {
    if ($grupoGrafico['total'] <= 0) {
        continue;
    }

    // O de dentro: as idades da entrega, sem destino por fatia.
    if ($indiceGrupo === 1) {
        $aneisGrafico[] = [
            'rotulo'  => $grupoGrafico['rotulo'],
            'de'      => $grupoGrafico['total'] === 1 ? 'entregue' : 'entregues',
            'chaves'  => array_fill(0, count($fatiasEntrega), null),
            'rotulos' => array_column($fatiasEntrega, 'rotulo'),
            'valores' => array_column($fatiasEntrega, 'total'),
            'cores'   => array_column($fatiasEntrega, 'cor'),
            'total'   => $grupoGrafico['total'],
        ];

        continue;
    }

    $aneisGrafico[] = [
        'rotulo'  => $grupoGrafico['rotulo'],
        // Como se lê o "3 de 4 …" da dica: "na oficina".
        'de'      => 'na oficina',
        'chaves'  => $grupoGrafico['estados'],
        'rotulos' => array_map(
            static fn (string $estado): string => $etiquetasEstado[$estado],
            $grupoGrafico['estados']
        ),
        'valores' => array_map(
            static fn (string $estado): int => (int) $contagens[$estado],
            $grupoGrafico['estados']
        ),
        'cores'   => array_map(
            static fn (string $estado): string => $coresEstado[$estado],
            $grupoGrafico['estados']
        ),
        'total'   => $grupoGrafico['total'],
    ];
}

// Descrição textual do gráfico para leitores de ecrã: o canvas sozinho
// não transmite os valores, e a divisão entre os dois lados vai à frente
// de cada lista, para não se perder no meio dos cinco números. O lado dos
// entregues lê-se pela idade da entrega, como o anel.
$partesResumo = [];
foreach ($gruposGrafico as $indiceGrupo => $grupoGrafico) {
    $valoresGrupo = [];

    if ($indiceGrupo === 1) {
        foreach ($fatiasEntrega as $fatiaEntrega) {
            $valoresGrupo[] = $fatiaEntrega['rotulo'] . ' ' . $fatiaEntrega['total'];
        }
    } else {
        foreach ($grupoGrafico['estados'] as $estado) {
            $valoresGrupo[] = $etiquetasEstado[$estado] . ' ' . $contagens[$estado];
        }
    }

    $partesResumo[] = $grupoGrafico['rotulo'] . ', ' . $grupoGrafico['total']
        . ($grupoGrafico['total'] === 1 ? ' pedido' : ' pedidos') . ': '
        . implode(', ', $valoresGrupo);
}
$resumoGrafico = 'Pedidos por estado. ' . implode('. ', $partesResumo) . '.';

// A divisão escrita por extenso no meio do anel: "4 em aberto · 1 entregue".
$divisaoGrafico = $gruposGrafico[0]['total'] . ' em aberto · '
    . $gruposGrafico[1]['total']
    . ($gruposGrafico[1]['total'] === 1 ? ' entregue' : ' entregues');

// A frase do topo descreve só o que existe. Sem pedidos nenhuns os
// atalhos da fila não chegam a aparecer e o gráfico não é desenhado —
// as contagens ficam todas a zero —, por isso a linha diz isso em vez de
// anunciar um estado da oficina que ninguém registou. A mesma escolha da
// lista de pedidos, que também troca a descrição quando não há nada para
// descrever. Um erro de base de dados fica de fora: aí quem fala é o
// aviso do erro, e uma contagem a zero não quer dizer oficina vazia.
$descricaoTopo = $erroDados === '' && $totalPedidos === 0
    ? 'A oficina ainda não tem pedidos registados — os atalhos e o gráfico de'
        . ' estados ficam a zero até ao primeiro.'
    : 'Estado da oficina e pedidos em curso, atualizado a cada consulta.';

// ---------------------------------------------------------------------
// A semana corrente, para a coluna lateral. As datas são as do servidor
// — a mesma régua das idades do resto do painel — e começam na segunda.
// ---------------------------------------------------------------------
$hojeSemana      = new DateTimeImmutable('today');
$segundaDaSemana = $hojeSemana->modify('monday this week');
$letrasDaSemana  = ['S', 'T', 'Q', 'Q', 'S', 'S', 'D'];
$diasDaSemana    = [];

for ($indiceDia = 0; $indiceDia < 7; $indiceDia++) {
    $diaCorrente = $segundaDaSemana->modify('+' . $indiceDia . ' days');

    $diasDaSemana[] = [
        'letra'  => $letrasDaSemana[$indiceDia],
        'numero' => (int) $diaCorrente->format('j'),
        'hoje'   => $diaCorrente->format('Y-m-d') === $hojeSemana->format('Y-m-d'),
    ];
}

$mesesLongos = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

$hojePorExtenso = (int) $hojeSemana->format('j') . ' de '
    . $mesesLongos[(int) $hojeSemana->format('n') - 1];
?>
<?php painelAbrir(
    'painel',
    'Visão geral',
    'Painel de gestão da oficina FixIt: pedidos, clientes e equipamentos.',
    [
        ['Painel', 'painel.php'],
        ['Visão geral', null],
    ],
    $erroDados,
    false,
    $pedidosAtivos
); ?>

        <main class="principal" id="conteudo" tabindex="-1">

            <!-- Cabeçalho de página -->
            <div class="cabecalho">
                <div class="cabecalho__texto">
                    <h1>Visão geral</h1>
                    <p class="cabecalho__descricao"><?= h($descricaoTopo) ?></p>
                </div>

                <a class="botao botao--primario" href="pedido-novo.php">
                    <svg aria-hidden="true"><use href="#icone-mais"/></svg>
                    <span>Novo pedido</span>
                </a>
            </div>

            <?php if ($avisoPermissao): ?>
                <div class="alerta" role="status">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 4.5 21 20H3L12 4.5Z"/>
                        <path d="M12 10v4"/>
                        <path d="M12 17h.01"/>
                    </svg>
                    <div>
                        <p class="alerta__titulo">A secção Utilizadores é só para administradores</p>
                        <p class="alerta__texto">
                            A tua conta é de técnico. Se precisares de consultar as
                            contas da oficina, pede a um administrador.
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($erroDados !== ''): ?>
                <section class="painel" aria-labelledby="titulo-erro">
                    <div class="aviso aviso--erro">
                        <span class="aviso__icone" aria-hidden="true">
                            <svg><use href="#icone-info"/></svg>
                        </span>
                        <p class="aviso__titulo" id="titulo-erro">Não foi possível carregar os dados da oficina</p>
                        <p class="aviso__texto"><?= h($erroDados) ?></p>
                        <a class="botao botao--fantasma" href="painel.php">Tentar novamente</a>
                    </div>
                </section>
            <?php else: ?>

            <!-- =====================================================
                 CARTÕES DE MÉTRICAS
                 Cada número vem de um COUNT(*) feito por PDO.
                 ===================================================== -->
            <section class="metricas" aria-label="Resumo da atividade">

                <a class="metrica" href="pedidos.php">
                    <div class="metrica__topo">
                        <p class="metrica__rotulo">Total de pedidos</p>
                        <span class="metrica__icone" aria-hidden="true">
                            <svg><use href="#icone-pedidos"/></svg>
                        </span>
                    </div>
                    <p class="metrica__valor"><?= $totalPedidos ?></p>
                    <?php if ($novosSemana > 0): ?>
                        <p class="metrica__nota">
                            <span class="tendencia">
                                <svg aria-hidden="true"><use href="#icone-subida"/></svg>
                                +<?= $novosSemana ?>
                            </span>
                            esta semana
                        </p>
                    <?php else: ?>
                        <p class="metrica__nota">Sem novos pedidos esta semana</p>
                    <?php endif; ?>
                    <svg class="metrica__seta" viewBox="0 0 24 24" aria-hidden="true"><use href="#icone-seta-direita"/></svg>
                    <span class="visualmente-oculto">Ver todos os pedidos</span>
                </a>

                <a class="metrica" href="pedidos.php?estado=ativos">
                    <div class="metrica__topo">
                        <p class="metrica__rotulo">Pedidos ativos</p>
                        <span class="metrica__icone metrica__icone--ambar" aria-hidden="true">
                            <svg><use href="#icone-relogio"/></svg>
                        </span>
                    </div>
                    <p class="metrica__valor"><?= $pedidosAtivos ?></p>
                    <?php if ($emDiagnostico > 0): ?>
                        <p class="metrica__nota">
                            <span class="tendencia tendencia--atencao"><?= $emDiagnostico ?></span>
                            em diagnóstico
                        </p>
                    <?php else: ?>
                        <p class="metrica__nota">Nenhum pedido em diagnóstico</p>
                    <?php endif; ?>
                    <svg class="metrica__seta" viewBox="0 0 24 24" aria-hidden="true"><use href="#icone-seta-direita"/></svg>
                    <span class="visualmente-oculto">Ver os pedidos por entregar</span>
                </a>

                <a class="metrica" href="clientes.php">
                    <div class="metrica__topo">
                        <p class="metrica__rotulo">Clientes registados</p>
                        <span class="metrica__icone metrica__icone--verde" aria-hidden="true">
                            <svg><use href="#icone-clientes"/></svg>
                        </span>
                    </div>
                    <p class="metrica__valor"><?= $clientesTotal ?></p>
                    <?php if ($clientesMes > 0): ?>
                        <p class="metrica__nota">
                            <span class="tendencia">
                                <svg aria-hidden="true"><use href="#icone-subida"/></svg>
                                +<?= $clientesMes ?>
                            </span>
                            este mês
                        </p>
                    <?php else: ?>
                        <p class="metrica__nota">Sem novos clientes este mês</p>
                    <?php endif; ?>
                    <svg class="metrica__seta" viewBox="0 0 24 24" aria-hidden="true"><use href="#icone-seta-direita"/></svg>
                    <span class="visualmente-oculto">Ver todos os clientes</span>
                </a>

            </section>

            <!-- =====================================================
                 RECEITA — os últimos 12 meses, com o ano anterior
                 por trás. O gráfico é desenhado por
                 assets/js/grafico-receita.js a partir do JSON que vai no
                 fim da página; a tabela em baixo é a mesma informação,
                 para quem não vê o canvas ou está sem JavaScript.
                 ===================================================== -->
            <section class="painel receita" id="receita" aria-labelledby="titulo-receita">
                <div class="painel__cabecalho">
                    <div>
                        <h2 id="titulo-receita">Receita</h2>
                        <p class="painel__descricao">
                            Pedidos entregues nos últimos 12 meses, com o mesmo período do ano
                            anterior por trás.
                            <?php if ($primeiroRegisto !== null): ?>
                                Registos desde <?= h(dataCurta((string) $primeiroRegisto)) ?>.
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Três vistas do mesmo gráfico. O aria-pressed diz
                         qual está escolhida; o teclado chega às três. -->
                    <div class="receita__vistas" role="group" aria-label="Métrica do gráfico">
                        <button class="receita__vista" type="button" data-vista="receita"
                                aria-pressed="true">Receita</button>
                        <button class="receita__vista" type="button" data-vista="pedidos"
                                aria-pressed="false">Pedidos</button>
                        <button class="receita__vista" type="button" data-vista="clientes"
                                aria-pressed="false">Clientes</button>
                    </div>
                </div>

                <?php if ($receitaErro !== ''): ?>
                    <div class="receita__corpo">
                        <div class="alerta alerta--erro" role="alert">
                            <p class="alerta__texto"><?= h($receitaErro) ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="receita__corpo">
                        <div class="receita__resumo">
                            <p class="receita__total" id="receita-total"><?= h(metricasMoeda((float) $receita['totais']['receita'])) ?></p>
                            <p class="receita__nota" id="receita-nota">em 12 meses · <?= (int) $receita['totais']['pedidos'] ?> pedidos entregues</p>

                            <ul class="receita__chave">
                                <li><span class="receita__amostra receita__amostra--atual" aria-hidden="true"></span>Este ano</li>
                                <li><span class="receita__amostra receita__amostra--anterior" aria-hidden="true"></span>Ano anterior</li>
                            </ul>
                        </div>

                        <div class="receita__tela">
                            <canvas id="grafico-receita" role="img" aria-label="<?= h($resumoReceita) ?>"></canvas>

                            <p class="receita__falha" id="receita-falha" hidden>
                                O gráfico não pôde ser desenhado. Os valores estão na tabela abaixo.
                            </p>
                        </div>

                        <?php if (!$receita['temAnoAnterior']): ?>
                            <p class="receita__aviso">
                                Não há pedidos entregues no período homólogo: a linha tracejada
                                fica sobre o eixo.
                            </p>
                        <?php endif; ?>
                    </div>

                    <details class="receita__tabela">
                        <summary>Ver os números destes 12 meses</summary>

                        <div class="receita__tabela-envolucro">
                            <table class="tabela">
                                <caption class="visualmente-oculto">
                                    Receita, pedidos entregues e clientes novos, mês a mês.
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Mês</th>
                                        <th scope="col">Receita</th>
                                        <th scope="col">Pedidos</th>
                                        <th scope="col">Clientes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($receita['meses'] as $indice => $mes): ?>
                                        <tr>
                                            <th scope="row"><?= h($receita['longos'][$indice]) ?></th>
                                            <td><?= h(metricasMoeda((float) $receita['atual']['receita'][$indice])) ?></td>
                                            <td><?= h(metricasNumero((float) $receita['atual']['pedidos'][$indice])) ?></td>
                                            <td><?= h(metricasNumero((float) $receita['atual']['clientes'][$indice])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th scope="row">Total</th>
                                        <td><?= h(metricasMoeda((float) $receita['totais']['receita'])) ?></td>
                                        <td><?= h(metricasNumero((float) $receita['totais']['pedidos'])) ?></td>
                                        <td><?= h(metricasNumero((float) $receita['totais']['clientes'])) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
            </section>

            <?php if ($totalPedidos > 0): ?>
                <!-- =================================================
                     DISTRIBUIÇÃO POR ESTADO (Chart.js)
                     Os valores vêm da consulta agrupada por estado
                     feita no topo da página e são injetados no
                     JavaScript com json_encode (ver o fim do ficheiro).
                     ================================================= -->
                <section class="painel" aria-labelledby="titulo-estados">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-estados">Pedidos por estado</h2>
                            <p class="painel__descricao">Todos os pedidos registados. Escolhe um
                                estado para abrir a lista já filtrada.</p>
                        </div>
                    </div>

                    <div class="grafico">
                        <div class="grafico__tela">
                            <canvas id="grafico-estados" role="img" aria-label="<?= h($resumoGrafico) ?>"></canvas>

                            <div class="grafico__centro" aria-hidden="true">
                                <span class="grafico__total"><?= $totalPedidos ?></span>
                                <span class="grafico__unidade"><?= $totalPedidos === 1 ? 'pedido' : 'pedidos' ?></span>
                                <span class="grafico__divisao"><?= h($divisaoGrafico) ?></span>
                            </div>

                            <p class="grafico__falha" id="grafico-aviso" hidden>
                                O gráfico não pôde ser carregado. Os totais estão na lista de estados.
                            </p>
                        </div>

                        <div class="grafico__legenda">
                            <?php foreach ($gruposGrafico as $indiceGrupo => $grupoGrafico): ?>
                                <div class="grafico__grupo">
                                    <p class="grafico__grupo-titulo">
                                        <span><?= h($grupoGrafico['rotulo']) ?></span>
                                        <span class="grafico__grupo-total"><?= $grupoGrafico['total'] ?></span>
                                    </p>

                                    <?php if ($indiceGrupo === 1): ?>
                                        <?php /* A idade da entrega, degrau a degrau, com a cor
                                                 que o anel usa. Estas linhas não são ligações:
                                                 a lista filtra por estado, não por quando o
                                                 pedido saiu. */ ?>
                                        <ul class="grafico__lista" aria-label="<?= h($grupoGrafico['rotulo']) ?>">
                                            <?php foreach ($fatiasEntrega as $fatiaEntrega): ?>
                                                <li class="grafico__item">
                                                    <span class="grafico__ligacao grafico__ligacao--parada">
                                                        <span class="grafico__ponto" style="background-color: <?= h($fatiaEntrega['cor']) ?>" aria-hidden="true"></span>
                                                        <span class="grafico__nome"><?= h($fatiaEntrega['rotulo']) ?></span>
                                                        <span class="grafico__quantidade"><?= $fatiaEntrega['total'] ?></span>
                                                        <span class="grafico__percentagem"><?= $fatiaEntrega['percentagem'] ?>%</span>
                                                    </span>
                                                </li>
                                            <?php endforeach; ?>

                                            <li class="grafico__item">
                                                <a class="grafico__ver"
                                                   href="pedidos.php?estado=Entregue#lista"
                                                   aria-label="Ver os pedidos entregues na lista, já filtrados">Ver os entregues</a>
                                            </li>
                                        </ul>
                                    <?php else: ?>
                                        <ul class="grafico__lista" aria-label="<?= h($grupoGrafico['rotulo']) ?>">
                                            <?php foreach ($grupoGrafico['estados'] as $estado): ?>
                                                <?php $quantidade = (int) $contagens[$estado]; ?>
                                                <li class="grafico__item">
                                                    <a class="grafico__ligacao"
                                                       href="pedidos.php?estado=<?= h(urlencode($estado)) ?>#lista"
                                                       aria-label="<?= h($etiquetasEstado[$estado]) ?>: <?= $quantidade ?> <?= $quantidade === 1 ? 'pedido' : 'pedidos' ?>. Ver a lista filtrada">
                                                        <span class="grafico__ponto" style="background-color: <?= h($coresEstado[$estado]) ?>" aria-hidden="true"></span>
                                                        <span class="grafico__nome"><?= h($etiquetasEstado[$estado]) ?></span>
                                                        <span class="grafico__quantidade"><?= $quantidade ?></span>
                                                        <span class="grafico__percentagem"><?= $percentagens[$estado] ?>%</span>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php
            // Ação de mudança de estado. O módulo não imprime nada
            // quando não há pedido em edição.
            AcaoEstadoPedido::renderFormulario(
                $acaoEstado,
                $etiquetasEstado,
                $classesEstado,
                'painel.php#alterar',
                'painel.php#abertos'
            );
            ?>

            <!-- =====================================================
                 PEDIDOS ABERTOS
                 A fila pela ordem do ciclo de vida (recebido, em
                 diagnóstico, em reparação, pronto); os entregues só na
                 lista de pedidos. O `else` tem dois estados vazios:
                 base sem pedidos e base só com entregues.
                 ===================================================== -->
            <section class="painel" id="abertos" aria-labelledby="titulo-abertos">
                <div class="painel__cabecalho">
                    <div>
                        <h2 id="titulo-abertos">Pedidos abertos</h2>
                        <p class="painel__descricao">Fila de trabalho, pelo estado do ciclo de vida.</p>

                        <?php /* Atalhos para a lista já filtrada. Cada um leva ao
                                 conjunto que conta e só aparece quando há algum:
                                 um atalho para uma lista vazia não serve a ninguém.
                                 O último olha para fora da secção — para os
                                 entregues —, e por isso o diz no rótulo. */ ?>
                        <?php if ($abertosPorAtribuir + $abertosEsperaLonga
                                  + $abertosConsultados + $abertosPorConsultar
                                  + $abertosEsquecidos + $entreguesPinPorAbrir > 0): ?>
                        <div class="atalhos">
                            <?php if ($abertosPorAtribuir > 0): ?>
                                <a class="atalho atalho--atribuir"
                                   href="pedidos.php?tecnico=sem&amp;estado=ativos&amp;ordem=entrada&amp;sentido=antigos#lista">
                                    <svg aria-hidden="true"><use href="#icone-atribuir"/></svg>
                                    <span>Por atribuir</span>
                                    <strong><?= $abertosPorAtribuir ?></strong>
                                    <?php /* Este recorte não tem idade própria na lista
                                             — a coluna do técnico só diz «Por atribuir»,
                                             sem contar os dias —, por isso abre pela
                                             entrada, a mais antiga primeiro: é a fila
                                             de quem espera há mais tempo por um técnico.
                                             O anúncio diz a ordem, com a frase do resumo
                                             da lista: o número diz quantos são, não em
                                             que ordem vêm. */ ?>
                                    <span class="visualmente-oculto"> — mostrar só estes, da entrada mais antiga para a mais recente</span>
                                </a>
                            <?php endif; ?>

                            <?php if ($abertosEsperaLonga > 0): ?>
                                <?php /* Mesma regra da pílula "Na oficina": mais de sete
                                         dias. Leva à lista já filtrada por esta espera,
                                         e já ordenada: este recorte mede o tempo na
                                         oficina, que corre desde a entrada, por isso
                                         abre pela entrada mais antiga — quem está lá
                                         há mais tempo em cima —, como o dos esquecidos
                                         abre pela espera do PIN, que é a sua. Assim a
                                         primeira linha é a da idade que o atalho conta,
                                         e o anúncio diz a ordem que a lista mostra. */ ?>
                                <a class="atalho atalho--espera"
                                   href="pedidos.php?espera=longa&amp;ordem=entrada&amp;sentido=antigos#lista">
                                    <svg aria-hidden="true"><use href="#icone-relogio"/></svg>
                                    <span>Há mais de uma semana</span>
                                    <strong><?= $abertosEsperaLonga ?></strong>
                                    <span class="visualmente-oculto"> — mostrar só estes, da entrada mais antiga para a mais recente</span>
                                </a>
                            <?php endif; ?>

                            <?php if ($abertosConsultados > 0): ?>
                                <?php /* Os que o cliente já abriu, pela consulta mais
                                         antiga: quem está à espera há mais tempo.
                                         A nota diz quanto tempo esperou o primeiro
                                         dessa lista, para o número não sugerir que
                                         está tudo fresco. A ordem anuncia-se com a
                                         frase do resumo da lista — «da última
                                         consulta mais antiga para a mais recente» —,
                                         para o atalho e o link do resumo se ouvirem
                                         da mesma maneira. */ ?>
                                <a class="atalho atalho--consultados"
                                   href="pedidos.php?consultas=com&amp;estado=ativos&amp;ordem=consulta&amp;sentido=antigos#lista">
                                    <svg aria-hidden="true"><use href="#icone-olho"/></svg>
                                    <span>Consultados há mais tempo</span>
                                    <span class="atalho__valores">
                                        <strong><?= $abertosConsultados ?></strong>
                                        <span class="atalho__nota">a mais antiga <?= h(haTempo($dataConsultaMaisAntiga)) ?><?php if ($dataConsultaMaisAntiga !== null): ?><span class="visualmente-oculto">, em <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $dataConsultaMaisAntiga))) ?>"><?= h(dataHora($dataConsultaMaisAntiga)) ?></time></span><?php endif; ?></span>
                                    </span>
                                    <span class="visualmente-oculto"> — mostrar só estes, da última consulta mais antiga para a mais recente</span>
                                </a>
                            <?php endif; ?>

                            <?php if ($abertosPorConsultar > 0): ?>
                                <?php /* Ainda sem uma única consulta ao PIN: o cliente
                                         não sabe o que se passa com o pedido. A nota
                                         diz há quanto tempo espera o que espera desde
                                         mais cedo, para o número não sugerir que é
                                         tudo recente. */ ?>
                                <a class="atalho atalho--consultar"
                                   href="pedidos.php?consultas=sem&amp;estado=ativos#lista">
                                    <svg aria-hidden="true"><use href="#icone-olho-cortado"/></svg>
                                    <span>Por consultar</span>
                                    <span class="atalho__valores">
                                        <strong><?= $abertosPorConsultar ?></strong>
                                        <span class="atalho__nota">o mais antigo sem consulta <?= h(haTempo($dataSemConsultaMaisAntigo)) ?><?php if ($dataSemConsultaMaisAntigo !== null): ?><span class="visualmente-oculto">, desde <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $dataSemConsultaMaisAntigo))) ?>"><?= h(dataHora($dataSemConsultaMaisAntigo)) ?></time></span><?php endif; ?></span>
                                    </span>
                                    <span class="visualmente-oculto"> — mostrar só estes, sem consultas ao PIN</span>
                                </a>
                            <?php endif; ?>

                            <?php if ($abertosEsquecidos > 0): ?>
                                <?php /* O mesmo número e as mesmas palavras da barra
                                         de filtros da lista: os pedidos cujo PIN o
                                         cliente não abre há mais de uma semana — nunca
                                         aberto ou aberto há muito. Mesmo aviso da
                                         lista, onde a idade destas linhas vai a âmbar.
                                         A lista abre ordenada pela espera do PIN, para
                                         a primeira linha ser a da nota. */ ?>
                                <a class="atalho atalho--pin"
                                   href="pedidos.php?consultas=esquecido&amp;ordem=espera&amp;sentido=antigos#lista">
                                    <svg aria-hidden="true"><use href="#icone-olho-cortado"/></svg>
                                    <span>Sem consulta há mais de uma semana</span>
                                    <span class="atalho__valores">
                                        <strong><?= $abertosEsquecidos ?></strong>
                                        <span class="atalho__nota">o mais antigo espera <?= h(haTempo($dataEsquecidoMaisAntigo)) ?><?php if ($dataEsquecidoMaisAntigo !== null): ?><span class="visualmente-oculto">, desde <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $dataEsquecidoMaisAntigo))) ?>"><?= h(dataHora($dataEsquecidoMaisAntigo)) ?></time></span><?php endif; ?></span>
                                    </span>
                                    <span class="visualmente-oculto"> — mostrar só estes, da espera do PIN mais antiga para a mais recente</span>
                                </a>
                            <?php endif; ?>

                            <?php if ($entreguesPinPorAbrir > 0): ?>
                                <?php /* O mesmo recorte já do lado dos entregues: o
                                         pedido saiu da oficina e o cliente nunca
                                         abriu o PIN. Fica em cinza, e não a âmbar
                                         dos atalhos de cima, porque não é trabalho
                                         à espera — é um pedido fechado.

                                         Abre pela espera mais antiga, como os
                                         outros atalhos: nestes pedidos não há
                                         consulta nenhuma antes da entrega, por isso
                                         a espera é a do próprio PIN, e a primeira
                                         linha é a do talão que já espera há mais
                                         tempo. */ ?>
                                <a class="atalho atalho--entregue"
                                   href="pedidos.php?estado=Entregue&amp;consultas=sem&amp;ordem=espera&amp;sentido=antigos#lista">
                                    <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                                    <span>Entregues com o PIN por abrir</span>
                                    <strong><?= $entreguesPinPorAbrir ?></strong>
                                    <span class="visualmente-oculto"> — mostrar só estes, da espera do PIN mais antiga para a mais recente</span>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <a class="ligacao-seta" href="pedidos.php">
                        <span>Ver todos</span>
                        <svg aria-hidden="true"><use href="#icone-seta-direita"/></svg>
                    </a>
                </div>

                <?php if ($sucessoAcao !== ''): ?>
                    <div class="alerta alerta--sucesso" role="status">
                        <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                        <div>
                            <p class="alerta__titulo">Pedido atualizado</p>
                            <p class="alerta__texto"><?= h($sucessoAcao) ?></p>
                        </div>
                    </div>
                <?php elseif ($erroAtribuicao !== ''): ?>
                    <div class="alerta" role="alert">
                        <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                        <div>
                            <p class="alerta__titulo">Técnico não guardado</p>
                            <p class="alerta__texto"><?= h($erroAtribuicao) ?></p>
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

                <?php if ($pedidosAbertos): ?>
                <div class="tabela__contentor">
                    <table class="tabela">
                        <caption class="visualmente-oculto">
                            Até cinco pedidos em aberto, pela ordem do ciclo de vida
                            (recebido, em diagnóstico, em reparação e pronto), com
                            cliente, equipamento, estado, técnico atribuído — mudável
                            na própria linha, ou há quanto tempo está por atribuir —,
                            data de entrada, há quantos dias está na oficina e ligações
                            para abrir o pedido e mudar o estado.
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Pedido</th>
                                <th scope="col">Cliente</th>
                                <th scope="col">Equipamento</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Técnico</th>
                                <th scope="col">Entrada</th>
                                <th scope="col">Na oficina</th>
                                <th scope="col" class="tabela__acao">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pedidosAbertos as $pedido): ?>
                                <?php
                                $diasAberto  = (int) $pedido['dias_aberto'];
                                $esperaLonga = $diasAberto > LIMITE_ESPERA_LONGA;
                                ?>
                                <tr>
                                    <td class="tabela__id" data-rotulo="Pedido">
                                        <a class="tabela__ligacao"
                                           href="pedido.php?id=<?= (int) $pedido['id'] ?>"
                                           aria-label="Abrir o pedido #<?= (int) $pedido['id'] ?>">#<?= (int) $pedido['id'] ?></a>
                                    </td>
                                    <td class="tabela__cliente" data-rotulo="Cliente"><?= h($pedido['cliente_nome']) ?></td>
                                    <td data-rotulo="Equipamento"><?= h($pedido['marca_modelo']) ?></td>
                                    <td data-rotulo="Estado"><span class="estado <?= $classesEstado[$pedido['estado']] ?? 'estado--recebido' ?>"><?= h($etiquetasEstado[$pedido['estado']] ?? $pedido['estado']) ?></span></td>
                                    <td class="tabela__tecnico" data-rotulo="Técnico">
                                        <?php
                                        // Mudar o técnico sem sair do painel: o
                                        // select traz o técnico atual — ou
                                        // "Por atribuir", para o devolver à fila —
                                        // e o botão grava só essa coluna.
                                        $tecnicoCelula = (int) ($pedido['tecnico_id'] ?? 0);
                                        ?>
                                        <?php if ($tecnicoCelula === 0): ?>
                                            <span class="tabela__por-atribuir">Por atribuir</span>
                                            <?php if ($historicoTecnicosOk): ?>
                                                <?php
                                                // A última vez que uma troca o deixou
                                                // sem técnico; sem trocas, conta desde
                                                // a entrada (nunca foi atribuído).
                                                $desdeSemTecnico = $semTecnicoDesde[(int) $pedido['id']]
                                                    ?? $pedido['data_entrada'];
                                                ?>
                                                <span class="tabela__sem-tecnico"><?= h(haTempo($desdeSemTecnico)) ?></span>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if ($tecnicosAtribuir !== []): ?>
                                            <form class="atribuir atribuir--painel"
                                                  method="post" action="painel.php#abertos">
                                                <input type="hidden" name="csrf" value="<?= h(Sessao::csrf()) ?>">
                                                <input type="hidden" name="acao" value="atribuir_tecnico">
                                                <input type="hidden" name="pedido_id" value="<?= (int) $pedido['id'] ?>">
                                                <label class="visualmente-oculto"
                                                       for="tecnico-<?= (int) $pedido['id'] ?>">Técnico do pedido #<?= (int) $pedido['id'] ?></label>
                                                <select class="entrada entrada--tabela"
                                                        id="tecnico-<?= (int) $pedido['id'] ?>"
                                                        name="tecnico_id">
                                                    <option value=""<?= $tecnicoCelula === 0 ? ' selected' : '' ?>>Por atribuir</option>
                                                    <?php foreach ($tecnicosAtribuir as $tecnicoLinha): ?>
                                                        <option value="<?= (int) $tecnicoLinha['id'] ?>"<?= $tecnicoCelula === (int) $tecnicoLinha['id'] ? ' selected' : '' ?>><?= h($tecnicoLinha['nome']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="botao botao--fantasma botao--pequeno" type="submit">Guardar<span class="visualmente-oculto"> o técnico do pedido #<?= (int) $pedido['id'] ?></span></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tabela__data" data-rotulo="Entrada"><?= h(dataCurta($pedido['data_entrada'])) ?></td>
                                    <td class="tabela__espera" data-rotulo="Na oficina">
                                        <?php
                                        // A mesma idade que a lista e a ficha
                                        // escrevem: "Hoje", "12 dias", "3 meses".
                                        $naOficina = ucfirst(tempoDecorrido($pedido['data_entrada']));
                                        ?>
                                        <?php if ($esperaLonga): ?>
                                            <span class="tabela__espera--longa"><?= h($naOficina) ?></span>
                                        <?php else: ?>
                                            <?= h($naOficina) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tabela__acao" data-rotulo="Ação">
                                        <a class="ligacao-acao"
                                           href="painel.php?alterar=<?= (int) $pedido['id'] ?>#alterar"
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
                    <span>A mostrar <?= count($pedidosAbertos) ?> de <?= $pedidosAtivos ?> <?= $pedidosAtivos === 1 ? 'pedido em aberto' : 'pedidos em aberto' ?></span>
                </div>
                <?php elseif ($totalPedidos > 0): ?>
                    <div class="aviso">
                        <span class="aviso__icone" aria-hidden="true">
                            <svg><use href="#icone-visto"/></svg>
                        </span>
                        <p class="aviso__titulo">Não há pedidos em aberto</p>
                        <p class="aviso__texto"><?= $totalPedidos === 1
                            ? 'O único pedido registado já foi entregue.'
                            : 'Os ' . $totalPedidos . ' pedidos registados já foram entregues.' ?>
                            Esta lista só mostra o que ainda está na oficina.</p>
                        <a class="ligacao-seta" href="pedidos.php?estado=Entregue">
                            <span>Ver os pedidos entregues</span>
                            <svg aria-hidden="true"><use href="#icone-seta-direita"/></svg>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="aviso">
                        <span class="aviso__icone" aria-hidden="true">
                            <svg><use href="#icone-pedidos"/></svg>
                        </span>
                        <?php /* O convite fica ao nível do cartão da lista:
                                 ícone, título e botão. A frase do topo já diz
                                 que a oficina está vazia e que os atalhos e o
                                 gráfico ficam a zero, e o botão é a ação — uma
                                 terceira linha a repetir o convite só fazia o
                                 cartão crescer. */ ?>
                        <p class="aviso__titulo">Ainda não há pedidos registados</p>
                        <a class="botao botao--primario" href="pedido-novo.php">
                            <svg aria-hidden="true"><use href="#icone-mais"/></svg>
                            <span>Novo pedido</span>
                        </a>
                    </div>
                <?php endif; ?>
            </section>

            <?php
            // Avisos do PIN de quem está a usar a sessão. A secção só existe
            // quando há algum: um aviso é para ser lido, e uma lista vazia
            // não avisa ninguém.
            ?>
            <?php if ($avisosPin): ?>
            <section class="painel" id="avisos-pin" aria-labelledby="titulo-avisos-pin">
                <div class="painel__cabecalho">
                    <div>
                        <h2 id="titulo-avisos-pin">Avisos de hoje</h2>
                        <p class="painel__descricao">
                            Pedidos que registaste e cujo PIN o cliente não abre há mais de uma
                            semana. Um aviso por dia, por pedido.
                        </p>
                    </div>
                    <?php /* A lista dos esquecidos, pela espera do PIN mais longa
                             primeiro: o mais antigo dos avisos é a primeira
                             linha, como no atalho do cabeçalho da fila. */ ?>
                    <a class="ligacao-seta" href="pedidos.php?consultas=esquecido&amp;ordem=espera&amp;sentido=antigos#lista">
                        <span>Ver todos</span>
                        <svg aria-hidden="true"><use href="#icone-seta-direita"/></svg>
                    </a>
                </div>

                <ol class="historico">
                    <?php foreach ($avisosPin as $aviso): ?>
                        <li class="historico__item historico__item--aviso">
                            <span class="historico__marca" aria-hidden="true"></span>
                            <div class="historico__corpo">
                                <div class="historico__topo">
                                    <a class="historico__etiqueta historico__etiqueta--ligacao"
                                       href="pedido.php?id=<?= (int) $aviso['pedido_id'] ?>"
                                       aria-label="Abrir o pedido #<?= (int) $aviso['pedido_id'] ?>">
                                        #<?= (int) $aviso['pedido_id'] ?>
                                    </a>
                                    <span class="historico__de"><?= h($aviso['marca_modelo']) ?> · <?= h($aviso['cliente_nome']) ?></span>
                                </div>
                                <p class="historico__mudanca">
                                    O cliente não abre o PIN há
                                    <strong><?= h(tempoDecorrido($aviso['espera_desde'] ?? null)) ?></strong><?php if (($aviso['espera_desde'] ?? null) !== null): ?>, desde <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $aviso['espera_desde']))) ?>"><?= h(dataHora($aviso['espera_desde'])) ?></time><?php endif; ?>.
                                </p>
                                <p class="historico__meta">
                                    Aviso de
                                    <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $aviso['criado_em']))) ?>"><?= h(dataHora($aviso['criado_em'])) ?></time>
                                </p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <?php endif; ?>

            <?php
            // Trocas de técnico. A secção só existe quando há registos: sem
            // histórico (base nova ou anterior à migração) o painel não
            // anuncia uma lista que não tem nada para mostrar.
            ?>
            <?php if ($trocasTecnico): ?>
            <section class="painel" id="trocas-tecnicos" aria-labelledby="titulo-trocas-tecnicos">
                <div class="painel__cabecalho">
                    <div>
                        <h2 id="titulo-trocas-tecnicos">Trocas de técnico</h2>
                        <p class="painel__descricao">As últimas mudanças de responsável, em todos os pedidos.</p>
                    </div>
                    <a class="ligacao-seta" href="pedidos.php">
                        <span>Ver pedidos</span>
                        <svg aria-hidden="true"><use href="#icone-seta-direita"/></svg>
                    </a>
                </div>

                <ol class="historico">
                    <?php foreach ($trocasTecnico as $troca): ?>
                        <li class="historico__item historico__item--tecnico">
                            <span class="historico__marca" aria-hidden="true"></span>
                            <div class="historico__corpo">
                                <div class="historico__topo">
                                    <a class="historico__etiqueta historico__etiqueta--ligacao"
                                       href="pedido.php?id=<?= (int) $troca['pedido_id'] ?>"
                                       aria-label="Abrir o pedido #<?= (int) $troca['pedido_id'] ?>">
                                        #<?= (int) $troca['pedido_id'] ?>
                                    </a>
                                    <span class="historico__de"><?= h($troca['marca_modelo']) ?> · <?= h($troca['cliente_nome']) ?></span>
                                </div>
                                <p class="historico__mudanca">
                                    <?php if (($troca['anterior_nome'] ?? null) !== null && $troca['anterior_nome'] !== ''): ?>
                                        <span class="historico__de"><?= h($troca['anterior_nome']) ?></span>
                                    <?php else: ?>
                                        <span class="historico__de">Por atribuir</span>
                                    <?php endif; ?>
                                    <span class="visualmente-oculto">passou para</span>
                                    <span aria-hidden="true">→</span>
                                    <?php if (($troca['novo_nome'] ?? null) !== null && $troca['novo_nome'] !== ''): ?>
                                        <strong><?= h($troca['novo_nome']) ?></strong>
                                    <?php else: ?>
                                        <strong>Por atribuir</strong>
                                        <span class="visualmente-oculto">(ficou sem técnico)</span>
                                    <?php endif; ?>
                                </p>
                                <p class="historico__meta">
                                    <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $troca['registado_em']))) ?>">
                                        <?= h(dataHora($troca['registado_em'])) ?>
                                    </time>
                                    <?php if (($troca['autor_nome'] ?? null) !== null && $troca['autor_nome'] !== ''): ?>
                                        <span aria-hidden="true"> · </span><?= h($troca['autor_nome']) ?>
                                    <?php else: ?>
                                        <span aria-hidden="true"> · </span><span class="tabela__por-atribuir">sem autor registado</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </section>
            <?php endif; ?>

            <?php endif; ?>

            <!-- =====================================================
                 COLUNA LATERAL
                 Perfil, semana e o que está por tratar. Os números são
                 das consultas já feitas acima — nenhum é inventado aqui.
                 ===================================================== -->
            <aside class="lateral-painel" aria-label="Resumo pessoal e pendentes">

                <section class="cartao-lateral">
                    <div class="perfil-lateral">
                        <span class="perfil-lateral__avatar" aria-hidden="true"><?= h(Sessao::iniciais((string) $utilizador['nome'])) ?></span>
                        <div>
                            <p class="perfil-lateral__nome"><?= h((string) $utilizador['nome']) ?></p>
                            <p class="perfil-lateral__cargo"><?= h((string) $utilizador['cargo']) ?></p>
                        </div>
                    </div>
                    <a class="cartao-lateral__acao" href="conta.php">A minha conta</a>
                </section>

                <section class="cartao-lateral">
                    <h2 class="cartao-lateral__titulo">Esta semana</h2>
                    <ol class="semana" aria-label="Dias desta semana">
                        <?php foreach ($diasDaSemana as $diaDaSemana): ?>
                            <li class="semana__dia<?= $diaDaSemana['hoje'] ? ' semana__dia--hoje' : '' ?>"<?= $diaDaSemana['hoje'] ? ' aria-current="date"' : '' ?>>
                                <span class="semana__letra" aria-hidden="true"><?= h($diaDaSemana['letra']) ?></span>
                                <span class="semana__numero"><?= (int) $diaDaSemana['numero'] ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="semana__nota">Hoje é <?= h($hojePorExtenso) ?>.</p>
                </section>

                <section class="cartao-lateral">
                    <h2 class="cartao-lateral__titulo">Por tratar</h2>

                    <?php if ($abertosPorAtribuir === 0 && $abertosEsperaLonga === 0
                              && $abertosPorConsultar === 0 && $solicitacoesNovas === 0): ?>
                        <p class="por-tratar__vazio">
                            Nada a pedir atenção: sem pedidos do site por contactar e
                            sem pedidos em aberto há mais de uma semana.
                        </p>
                    <?php else: ?>
                        <ul class="por-tratar">
                            <?php if ($solicitacoesNovas > 0): ?>
                                <li>
                                    <a href="solicitacoes.php?estado=Nova">
                                        <span class="por-tratar__marca" aria-hidden="true"></span>
                                        <span><strong><?= (int) $solicitacoesNovas ?></strong>
                                            <?= $solicitacoesNovas === 1 ? 'pedido do site por contactar' : 'pedidos do site por contactar' ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php if ($abertosPorAtribuir > 0): ?>
                                <li>
                                    <a href="pedidos.php">
                                        <span class="por-tratar__marca" aria-hidden="true"></span>
                                        <span><strong><?= (int) $abertosPorAtribuir ?></strong>
                                            <?= $abertosPorAtribuir === 1 ? 'pedido sem técnico' : 'pedidos sem técnico' ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php if ($abertosEsperaLonga > 0): ?>
                                <li>
                                    <a href="painel.php#abertos">
                                        <span class="por-tratar__marca" aria-hidden="true"></span>
                                        <span><strong><?= (int) $abertosEsperaLonga ?></strong>
                                            <?= $abertosEsperaLonga === 1 ? 'pedido aberto há mais de uma semana' : 'pedidos abertos há mais de uma semana' ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php if ($abertosPorConsultar > 0): ?>
                                <li>
                                    <a href="pedidos.php">
                                        <span class="por-tratar__marca" aria-hidden="true"></span>
                                        <span><strong><?= (int) $abertosPorConsultar ?></strong>
                                            <?= $abertosPorConsultar === 1 ? 'cliente ainda não abriu o PIN' : 'clientes ainda não abriram o PIN' ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                </section>

            </aside>

        </main>
    </div>
</div>

<?php if ($totalPedidos > 0): ?>
<!-- =================================================================
     GRÁFICO POR ESTADO — Chart.js (CDN, versão fixa)
     O canvas só é desenhado se a biblioteca estiver disponível. Os
     valores entram por json_encode; se o CDN falhar (sem rede), a
     legenda com os totais continua a ser a fonte de verdade.
     ================================================================= -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"
        integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt"
        crossorigin="anonymous"></script>
<script>
(function () {
    "use strict";

    var canvas = document.getElementById("grafico-estados");
    if (!canvas) {
        return;
    }

    var aviso  = document.getElementById("grafico-aviso");
    var centro = canvas.parentNode.querySelector(".grafico__centro");

    /* Sem a biblioteca (CDN bloqueado ou offline), escondemos o círculo
       vazio e explicamos; a lista ao lado mantém todos os números. */
    if (typeof Chart === "undefined") {
        canvas.style.display = "none";
        if (centro) { centro.style.display = "none"; }
        if (aviso) { aviso.hidden = false; }
        return;
    }

    /* Estrutura de dados injetada pelo PHP: rótulos, valores e cores
       saem das mesmas variáveis que desenham a legenda. Cada anel — a
       oficina por fora, os entregues por dentro — traz os seus estados. */
    var dados = <?= json_encode([
        'aneis' => $aneisGrafico,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

    /* Texto do gráfico com a tipografia e a cor do sistema do painel. */
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.size = 12;
    Chart.defaults.color = "#5A6C80";

    var reduzirMovimento = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* A fatia lida na dica de contexto é a fatia que se vê: a do seu anel. */
    function percentagemDoAnel(anel, valor) {
        return anel && anel.total > 0
            ? Math.round((valor / anel.total) * 100)
            : 0;
    }

    var aneis = dados.aneis;

    var grafico = new Chart(canvas, {
        type: "doughnut",
        data: {
            labels: [],
            datasets: aneis.map(function (anel) {
                return {
                    data: anel.valores,
                    backgroundColor: anel.cores,
                    borderColor: "#FFFFFF",
                    borderWidth: 3,
                    borderRadius: 3,
                    hoverOffset: 5
                };
            })
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            /* Dois anéis: o corte recua para o buraco do meio continuar a
               levar o número e a divisão. Com um anel só — um dos lados sem
               pedidos — volta o corte folgado de sempre. */
            cutout: aneis.length > 1 ? "66%" : "70%",
            animation: reduzirMovimento ? false : {
                duration: 750,
                easing: "easeOutQuart"
            },
            /* Só as fatias com destino mostram a mão: o anel dos entregues
               divide-se pela idade da entrega e não abre lista nenhuma. */
            onHover: function (evento, elementos) {
                if (!evento.native) {
                    return;
                }

                var anel = elementos.length ? aneis[elementos[0].datasetIndex] : null;
                var chave = anel ? anel.chaves[elementos[0].dataIndex] : null;
                evento.native.target.style.cursor = chave ? "pointer" : "default";
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: "#101C28",
                    titleColor: "#FFFFFF",
                    titleFont: { size: 12, weight: 600 },
                    bodyColor: "#E4E9F0",
                    bodyFont: { size: 12 },
                    padding: 10,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        /* O nome do estado vem do anel em que se está, e o
                           número diz de que total ele é — sem isso, "75%"
                           tanto podia ser do lado como do todo. */
                        title: function (itens) {
                            var anel = aneis[itens[0].datasetIndex];
                            return anel ? anel.rotulos[itens[0].dataIndex] : "";
                        },
                        label: function (contexto) {
                            var anel  = aneis[contexto.datasetIndex];
                            var valor = contexto.parsed;
                            return valor + " de " + anel.total + " " + anel.de
                                 + " · " + percentagemDoAnel(anel, valor) + "%";
                        }
                    }
                }
            }
        }
    });

    /* Cada fatia abre a lista filtrada por esse estado. Fica fora das
       opções do gráfico para não depender do ciclo interno de eventos da
       biblioteca; a legenda ao lado faz o mesmo e é o caminho acessível
       por teclado. */
    canvas.addEventListener("click", function (evento) {
        if (typeof grafico.getElementsAtEventForMode !== "function") {
            return;
        }

        var elementos = grafico.getElementsAtEventForMode(
            evento, "nearest", { intersect: true }, "point"
        );

        if (!elementos.length) {
            return;
        }

        var anel = aneis[elementos[0].datasetIndex];
        var chave = anel ? anel.chaves[elementos[0].dataIndex] : null;
        if (!chave) {
            return;
        }

        window.location.href = "pedidos.php?estado="
            + encodeURIComponent(chave) + "#lista";
    });
})();
</script>
<?php endif; ?>

<!-- Os números do gráfico de receita: um só sítio, escrito por PHP e lido
     por assets/js/grafico-receita.js. JSON_HEX_TAG impede que um `</script>`
     dentro de um valor feche o bloco antes do tempo. -->
<script type="application/json" id="dados-receita"><?= json_encode(
    [
        'meses'     => $receita['meses'],
        'longos'    => $receita['longos'],
        'atual'     => $receita['atual'],
        'anterior'  => $receita['anterior'],
        'variacoes' => $receita['variacoes'],
        'totais'    => $receita['totais'],
        'erro'      => $receitaErro !== '',
    ],
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>
<script src="../assets/js/grafico-receita.js" defer></script>
</body>
</html>
