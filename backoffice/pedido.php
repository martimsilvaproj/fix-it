<?php
declare(strict_types=1);

/**
 * pedido.php — Detalhe de uma ordem de serviço.
 *
 * Mostra o pedido completo (cliente, equipamento, avaria, datas e PIN de
 * consulta), o histórico de mudanças de estado e a ação de mudar estado
 * partilhada com o painel e a lista (estado-pedido.php).
 *
 * O PIN de consulta pode ser substituído aqui: gerar um novo grava-o no
 * pedido e invalida o anterior no mesmo instante (a consulta pública lê a
 * coluna em cada pedido). O cliente tem de receber o PIN novo. A troca
 * deixa rasto no histórico: quem a fez e quando (historico_pins), na mesma
 * transação que muda o PIN. O PIN inicial, emitido ao registar o pedido,
 * está na mesma tabela — pedido.php só o mostra.
 *
 * Como o estado é gravado em `pedidos` e a consulta pública (frontoffice/index.php)
 * lê essa coluna em cada pedido HTTP, uma mudança guardada aqui aparece
 * imediatamente ao cliente — não há cópia intermédia nem cache.
 *
 * Estrutura:
 *   1. Sessão, ID pedido no URL e ligação.
 *   2. Carregamento do pedido, do histórico e da ação de estado.
 *   3. HTML + PHP de apresentação.
 *
 * Requisitos: PHP 8.0+, pdo_mysql e a base de dados fixit.
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/estado-pedido.php';
require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/layout-painel.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';
require_once __DIR__ . '/../nucleo/pin-consulta.php';

// A área da oficina exige sessão: sem login válido, Sessao::exigir()
// manda para o login.php antes de qualquer consulta. A mesma sessão
// guarda o token CSRF da ação e a confirmação que sobrevive ao
// redirecionamento (POST → Redirect → GET).
Sessao::iniciar();

/** @var array{id: int, nome: string, email: string, cargo: string} $utilizador */
$utilizador = Sessao::exigir();

// Contador do menu: quantos pedidos do site estão por contactar.
$solicitacoesNovas = solicitacoesNovas();

// Mais de uma semana — o mesmo limite estrito da lista de pedidos e do
// painel: sete dias certos ainda contam como espera normal. A ficha passou
// a precisar do número para marcar a espera do PIN a partir daqui.
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

/** Data e hora, para o histórico: "22 out 2026, 14:05". */
function dataHora(?string $dataHora): string
{
    if ($dataHora === null || $dataHora === '') {
        return '—';
    }

    $momento = strtotime($dataHora);

    return $momento === false ? '—' : dataCurta($dataHora) . ', ' . date('H:i', $momento);
}

/**
 * Dias de calendário desde um momento — a diferença entre datas, não entre
 * instantes, como o DATEDIFF do painel e a idade da lista de pedidos. Uma
 * data no futuro (relógio adiantado) conta como hoje. Null quando não há
 * data legível.
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

/** Formata um valor monetário para leitura (ex.: 45,90 €). */
function euros(float $valor): string
{
    return number_format($valor, 2, ',', ' ') . ' €';
}

/**
 * Converte o que o técnico escreveu num valor em euros.
 *
 * Aceita a vírgula portuguesa ("149,90"), o ponto ("149.90") e espaços
 * ou o símbolo € à volta. Num valor com vírgula, os pontos são
 * separadores de milhares ("1.234,56"). Devolve null quando o formato
 * não é reconhecido ou passa dos 10 dígitos de DECIMAL(10,2).
 */
function valorEuros(string $texto): ?float
{
    $limpo = str_replace(["\u{00A0}", ' ', '€'], '', trim($texto));

    if (str_contains($limpo, ',')) {
        $limpo = str_replace('.', '', $limpo);
        $limpo = str_replace(',', '.', $limpo);
    }

    if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $limpo) !== 1) {
        return null;
    }

    return (float) $limpo;
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

// ---------------------------------------------------------------------
// ID do pedido, sempre vindo do URL. Um valor inválido cai na página
// "não encontrado" em vez de consultar o pedido 0.
// ---------------------------------------------------------------------
$idPedido = 0;
$idRecebido = $_GET['id'] ?? null;

if (is_string($idRecebido) && ctype_digit($idRecebido) && (int) $idRecebido > 0) {
    $idPedido = (int) $idRecebido;
}

$pedido              = null;
$historicoEstados    = [];
$historicoOrcamentos = [];
$historicoPins       = [];
$historicoTecnicos   = [];
$eventos             = [];
$consultasPin        = 0;
$ultimaConsulta      = null;
$consultasDepois     = 0;
$ultimaDepois        = null;
$consultasDisponiveis = false;
$pinEmitidoEm        = null;
$esperaPinDesde      = null;
$esperaPinDias       = null;
$consultaAntiga      = false;
$usosPin             = [];
$ultimasUsoPin       = [];
$entregaEm           = null;
$indiceEstadoAtual   = null;
$acaoEstado          = [];
$tecnicosOrcamento   = [];
$tecnicoOrcamento    = 0;
$sucessoAcao         = '';
$sucessoOrcamento    = [];
$valorOrcamento      = '';
$errosOrcamento      = [];
$removerOrcamento    = false;
$erroPin             = '';
$sucessoPin          = [];
$pedidosAtivos       = 0;
$erroDados           = '';

try {
    $pdo = obterConexao();

    // Contador da sidebar: os pedidos ainda por entregar.
    $pedidosAtivos = (int) $pdo->query(
        'SELECT COUNT(*) FROM pedidos WHERE estado <> \'Entregue\''
    )->fetchColumn();

    if ($idPedido > 0) {
        $consulta = $pdo->prepare(
            'SELECT p.id, p.estado, p.tecnico_id, p.descricao_avaria, p.orcamento,
                    p.pin_consulta, p.data_entrada, p.data_conclusao,
                    e.id AS equipamento_id, e.tipo, e.marca_modelo, e.numero_serie,
                    c.nome AS cliente_nome, c.nif AS cliente_nif,
                    c.telefone AS cliente_telefone, c.email AS cliente_email,
                    u.nome AS tecnico_nome
               FROM pedidos p
         INNER JOIN equipamentos e ON e.id = p.equipamento_id
         INNER JOIN clientes c     ON c.id = e.cliente_id
          LEFT JOIN utilizadores u ON u.id = p.tecnico_id
              WHERE p.id = ?
              LIMIT 1'
        );
        $consulta->execute([$idPedido]);
        $pedido = $consulta->fetch() ?: null;

        if ($pedido !== null) {
            // Histórico de estados, do mais recente para o mais antigo.
            $consulta = $pdo->prepare(
                'SELECT h.id, h.estado, h.registado_em, u.nome AS tecnico_nome
                   FROM historico_pedidos h
              LEFT JOIN utilizadores u ON u.id = h.tecnico_id
                  WHERE h.pedido_id = ?
               ORDER BY h.registado_em DESC, h.id DESC'
            );
            $consulta->execute([$idPedido]);
            $historicoEstados = $consulta->fetchAll();

            // Fotografias anexadas na receção. Opcional como as restantes: numa
            // base anterior à migração a tabela não existe e a ficha continua a
            // mostrar o resto em vez de falhar por completo.
            $fotosPedido = [];

            try {
                $consulta = $pdo->prepare(
                    'SELECT id, caminho_foto, descricao, data_upload
                       FROM fotos_equipamento
                      WHERE pedido_id = ?
                   ORDER BY id'
                );
                $consulta->execute([$idPedido]);
                $fotosPedido = $consulta->fetchAll();
            } catch (PDOException $excecao) {
                error_log('[FixIt][Pedido][Fotos] ' . $excecao->getMessage());
            }

            // Histórico de alterações ao orçamento. Numa base anterior à
            // migração a tabela não existe: a página continua a mostrar o
            // histórico de estados em vez de falhar por completo.
            try {
                $consulta = $pdo->prepare(
                    'SELECT h.id, h.valor_anterior, h.valor_novo, h.registado_em,
                            u.nome AS tecnico_nome
                       FROM historico_orcamentos h
                  LEFT JOIN utilizadores u ON u.id = h.tecnico_id
                      WHERE h.pedido_id = ?
                   ORDER BY h.registado_em DESC, h.id DESC'
                );
                $consulta->execute([$idPedido]);
                $historicoOrcamentos = $consulta->fetchAll();
            } catch (PDOException $excecao) {
                error_log('[FixIt][Pedido][Orçamentos] ' . $excecao->getMessage());
            }

            // Histórico de atribuições de técnico, com quem estava, quem
            // ficou e quem fez a troca. Opcional como os outros: numa base
            // anterior à migração a tabela não existe e a ficha mostra o
            // resto do histórico em vez de falhar.
            try {
                $consulta = $pdo->prepare(
                    'SELECT h.id, h.tecnico_anterior, h.tecnico_novo, h.registado_em,
                            autor.nome AS tecnico_nome,
                            antes.nome AS anterior_nome,
                            novo.nome AS novo_nome
                       FROM historico_tecnicos h
                  LEFT JOIN utilizadores autor ON autor.id = h.autor_id
                  LEFT JOIN utilizadores antes ON antes.id = h.tecnico_anterior
                  LEFT JOIN utilizadores novo  ON novo.id  = h.tecnico_novo
                      WHERE h.pedido_id = ?
                   ORDER BY h.registado_em DESC, h.id DESC'
                );
                $consulta->execute([$idPedido]);
                $historicoTecnicos = $consulta->fetchAll();
            } catch (PDOException $excecao) {
                error_log('[FixIt][Pedido][Técnicos] ' . $excecao->getMessage());
            }

            // Histórico de PINs emitidos (o inicial e cada renovação).
            // Como o do orçamento, é opcional: numa base anterior à
            // migração a tabela não existe e o histórico mostra o resto em
            // vez de falhar por completo.
            try {
                $consulta = $pdo->prepare(
                    'SELECT h.id, h.motivo, h.registado_em, u.nome AS tecnico_nome
                       FROM historico_pins h
                  LEFT JOIN utilizadores u ON u.id = h.tecnico_id
                      WHERE h.pedido_id = ?
                   ORDER BY h.registado_em DESC, h.id DESC'
                );
                $consulta->execute([$idPedido]);
                $historicoPins = $consulta->fetchAll();
            } catch (PDOException $excecao) {
                error_log('[FixIt][Pedido][PINs] ' . $excecao->getMessage());
            }

            // Momento da entrega, quando o pedido já saiu da oficina:
            // pedidos.data_conclusao é escrita no instante em que o
            // estado passa a Entregue. Um pedido Entregue sem essa data
            // (base anterior ou linha inserida à mão) fica sem entrega
            // datada, e a ficha não fala dela. Sai antes da contagem das
            // consultas porque é ela que lhe fecha a janela.
            $entregaEm = $pedido['data_conclusao'] !== null
                ? (string) $pedido['data_conclusao']
                : null;

            // Consultas públicas feitas com o PIN atual. O "atual" é o
            // emitido mais recentemente (a primeira linha de
            // historico_pins, já ordenada); num pedido anterior ao
            // histórico de PINs, conta-se desde a entrada. Assim, gerar
            // um PIN novo recomeça o contador sem apagar nada.
            //
            // Num pedido já entregue a contagem fecha na entrega: o que
            // interessa é o que o cliente viu enquanto o equipamento
            // estava na oficina. Uma consulta feita depois de sair não é
            // espera por informação — o trabalho já está feito — e,
            // somada às outras, dava a ideia de um acompanhamento que
            // acabou antes do fim.
            //
            // Opcional, como o histórico: sem a tabela a ficha abre sem
            // a contagem, em vez de afirmar que não houve consultas.
            try {
                $pinEmitidoEm = $historicoPins !== []
                    ? (string) $historicoPins[0]['registado_em']
                    : (string) $pedido['data_entrada'];

                $sqlConsultas = 'SELECT COUNT(*) AS total, MAX(registado_em) AS ultima
                                   FROM consultas_publicas
                                  WHERE pedido_id = ? AND registado_em >= ?';
                $parametrosConsultas = [$idPedido, $pinEmitidoEm];

                if ($entregaEm !== null) {
                    $sqlConsultas .= ' AND registado_em <= ?';
                    $parametrosConsultas[] = $entregaEm;
                }

                $consulta = $pdo->prepare($sqlConsultas);
                $consulta->execute($parametrosConsultas);
                $linha = $consulta->fetch();

                $consultasPin   = (int) ($linha['total'] ?? 0);
                $ultimaConsulta = $linha['ultima'] ?? null;
                $consultasDisponiveis = true;
            } catch (PDOException $excecao) {
                error_log('[FixIt][Pedido][Consultas] ' . $excecao->getMessage());
            }

            // Consultas posteriores à entrega, com o mesmo PIN: ficam de
            // fora da contagem de cima por serem outra coisa — o
            // acompanhamento acabou, mas o cliente voltou ao PIN. Dum
            // lado as duas não se confundem; somadas, davam a ideia de um
            // seguimento que durou até ao fim. A janela do PIN é a mesma
            // da contagem, para os dois números serem do mesmo talão.
            if ($consultasDisponiveis && $entregaEm !== null) {
                try {
                    $consulta = $pdo->prepare(
                        'SELECT COUNT(*) AS total, MAX(registado_em) AS ultima
                           FROM consultas_publicas
                          WHERE pedido_id = ? AND registado_em > ? AND registado_em >= ?'
                    );
                    $consulta->execute([$idPedido, $entregaEm, $pinEmitidoEm]);
                    $linha = $consulta->fetch();

                    $consultasDepois = (int) ($linha['total'] ?? 0);
                    $ultimaDepois    = $linha['ultima'] ?? null;
                } catch (PDOException $excecao) {
                    error_log('[FixIt][Pedido][Consultas depois] ' . $excecao->getMessage());
                }
            }

            // Desde quando corre a espera do PIN atual — a mesma
            // referência da lista de pedidos e dos avisos do painel: a
            // última consulta do cliente e, num talão que ninguém abriu,
            // a emissão. É o momento que a ficha mostra ao lado dos dias.
            if ($consultasDisponiveis) {
                $esperaPinDesde = $ultimaConsulta !== null && $ultimaConsulta !== ''
                    ? (string) $ultimaConsulta
                    : $pinEmitidoEm;
            }

            // A espera longa, resolvida uma vez: o cabeçalho marca o pedido
            // e o painel da consulta veste os dias com a pílula âmbar. Sete
            // dias certos ainda contam como espera normal, e um pedido
            // entregue não espera nada — é a mesma regra da lista.
            $esperaPinDias = diasDesde($esperaPinDesde);
            $consultaAntiga = $esperaPinDias !== null
                && $esperaPinDias > LIMITE_ESPERA_LONGA
                && (string) $pedido['estado'] !== 'Entregue';

            // O aviso do cabeçalho e a idade do painel da consulta levam os
            // dois ao mesmo recorte — a lista dos esquecidos, pela espera do
            // PIN mais longa primeiro. O endereço fica escrito uma vez para
            // os dois lados não apontarem para sítios diferentes.
            $urlEsquecidos = 'pedidos.php?consultas=esquecido&ordem=espera&sentido=antigos#lista';

            // Quantos pedidos esse aviso vai abrir: o mesmo recorte do
            // filtro dos esquecidos da lista — pedidos em aberto sem
            // consulta ao PIN há mais de uma semana, contando desde a
            // última consulta ou, num talão que ninguém abriu, desde a
            // emissão. É a régua de pedidos.php (sqlDiasSemAbrirPin),
            // escrita aqui porque a ficha não inclui a lista; sem o
            // número, o link prometia um recorte sem tamanho.
            $totalEsquecidos = 0;

            if ($consultasDisponiveis) {
                try {
                    $consulta = $pdo->prepare(
                        'SELECT COUNT(*)
                           FROM pedidos p
                          WHERE p.estado <> \'Entregue\'
                            AND DATEDIFF(CURDATE(), DATE(COALESCE(
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
                                      p.data_entrada)))) > ' . LIMITE_ESPERA_LONGA
                    );
                    $consulta->execute();
                    $totalEsquecidos = (int) $consulta->fetchColumn();
                } catch (PDOException $excecao) {
                    error_log('[FixIt][Pedido][Esquecidos] ' . $excecao->getMessage());
                    $totalEsquecidos = 0;
                }
            }

            // O tamanho do recorte, para o número também se ler a olho nu no
            // cabeçalho. Com um pedido só fica vazio: esse é o que está
            // aberto, e «· o único pedido» não dizia nada a mais. Sem
            // contagem — a tabela das consultas em falta — também fica
            // vazio.
            $contagemEsquecidos = $totalEsquecidos > 1
                ? $totalEsquecidos . ' pedidos'
                : '';

            // O recorte, a ordem e o número são os mesmos nos dois avisos: o
            // tamanho do recorte vai à vista na pílula dos dois — «· 3
            // pedidos» ao lado dos dias, no cabeçalho e no painel da consulta
            // —, e o texto escondido diz apenas que lista o clique abre.
            // Dito duas vezes, o número lia-se duas vezes.
            $recorteEsquecidos = ' sem consulta há mais de uma semana, da espera do PIN mais antiga para a mais recente';

            $anuncioCabecalho = ' — ver '
                . ($totalEsquecidos === 1 ? 'o pedido' : 'os pedidos')
                . $recorteEsquecidos;

            // Consultas que recebeu cada talão — quantas e a última. A
            // janela de um talão substituído vai da sua emissão à
            // emissão seguinte; a do PIN atual (a primeira linha de
            // historico_pins) vai da emissão até agora. Derivado das
            // datas já gravadas, sem coluna nova: vale também para
            // talões trocados antes de existir este contador e nunca
            // fica a divergir do que está guardado.
            if ($consultasDisponiveis) {
                try {
                    $contarUso = $pdo->prepare(
                        'SELECT COUNT(*) AS total, MAX(registado_em) AS ultima
                           FROM consultas_publicas
                          WHERE pedido_id = ?
                            AND registado_em >= ? AND registado_em < ?'
                    );

                    // Do talão em uso o rodapé já dá a contagem e a
                    // última consulta — ambas fechadas na entrega, num
                    // pedido já entregue. Falta só o que o rodapé não
                    // sabe dizer, quando o pedido já saiu da oficina: a
                    // última consulta antes da entrega, com a janela
                    // fechada pelo momento em que foi entregue.
                    if ($entregaEm !== null) {
                        $ultimaUsoAteEntrega = $pdo->prepare(
                            'SELECT MAX(registado_em) AS ultima
                               FROM consultas_publicas
                              WHERE pedido_id = ?
                                AND registado_em >= ? AND registado_em <= ?'
                        );
                    }

                    foreach ($historicoPins as $indicePin => $linhaPin) {
                        $inicioUso = (string) $linhaPin['registado_em'];

                        // A primeira linha é o PIN atual: ainda a contar.
                        if ($indicePin === 0) {
                            if ($entregaEm !== null) {
                                $ultimaUsoAteEntrega->execute([$idPedido, $inicioUso, $entregaEm]);
                                $linhaUso = $ultimaUsoAteEntrega->fetch();
                                $ultimasUsoPin[(int) $linhaPin['id']] = $linhaUso['ultima'] ?? null;
                            }

                            continue;
                        }

                        $fimUso = (string) $historicoPins[$indicePin - 1]['registado_em'];

                        // Duas emissões no mesmo segundo: a janela é vazia.
                        if ($fimUso <= $inicioUso) {
                            $usosPin[(int) $linhaPin['id']] = 0;
                            continue;
                        }

                        $contarUso->execute([$idPedido, $inicioUso, $fimUso]);
                        $linhaUso = $contarUso->fetch();

                        $usosPin[(int) $linhaPin['id']] = (int) ($linhaUso['total'] ?? 0);
                        $ultimasUsoPin[(int) $linhaPin['id']] = $linhaUso['ultima'] ?? null;
                    }
                } catch (PDOException $excecao) {
                    $usosPin = [];
                    $ultimasUsoPin = [];
                    error_log('[FixIt][Pedido][Consultas][PIN] ' . $excecao->getMessage());
                }
            }

            // Valor e técnico do formulário do orçamento. Arredondado a
            // duas casas e com vírgula, como é escrito em Portugal.
            $valorOrcamento = $pedido['orcamento'] !== null
                ? number_format((float) $pedido['orcamento'], 2, ',', '')
                : '';
            $tecnicoOrcamento = (int) ($pedido['tecnico_id'] ?? 0);

            // Estado atual do módulo partilhado. $idPorOmissao mantém o
            // formulário aberto nesta página, mesmo sem ?alterar=ID.
            $acaoEstado = AcaoEstadoPedido::preparar(
                $pdo,
                $etiquetasEstado,
                'pedido.php?id=' . $idPedido,
                $idPedido
            );

            // A lista de técnicos já foi carregada pelo módulo; o
            // orçamento usa a mesma, para os dois selects não divergirem.
            $tecnicosOrcamento = $acaoEstado['tecnicos'] ?? [];
            $tecnicosOrcamentoPorId = $acaoEstado['tecnicosPorId'] ?? [];

            // --- Orçamento (POST → Redirect → GET) ---
            // Independente da ação de estado, com o mesmo token CSRF da
            // sessão. Guardar e remover passam pelo mesmo caminho: o
            // UPDATE e a linha do histórico são uma só transação.
            $acaoOrcamento = $_POST['acao'] ?? null;
            $removerOrcamento = $acaoOrcamento === 'remover_orcamento';

            if ($acaoOrcamento === 'alterar_orcamento' || $removerOrcamento) {
                $bruto = $_POST['orcamento'] ?? null;
                $bruto = is_string($bruto) ? trim($bruto) : '';
                $valorNovo = null;

                // Numa remoção o campo do valor não conta: mantém-se o
                // valor atual no formulário caso a remoção falhe.
                if (!$removerOrcamento) {
                    $valorOrcamento = $bruto;
                }

                $tecnicoEnviado = $_POST['tecnico_id'] ?? null;
                $tecnicoOrcamento = is_string($tecnicoEnviado) && ctype_digit($tecnicoEnviado)
                    ? (int) $tecnicoEnviado
                    : 0;

                $token = $_POST['csrf'] ?? '';

                if (!is_string($token)
                    || empty($_SESSION['csrf_painel'])
                    || !hash_equals($_SESSION['csrf_painel'], $token)
                ) {
                    $errosOrcamento['geral'] = 'A sessão expirou. Recarrega a página e tenta novamente.';
                }

                if ($removerOrcamento) {
                    if ($pedido['orcamento'] === null) {
                        $errosOrcamento['geral'] = 'O orçamento já está por definir. Não há nada para remover.';
                    }
                } elseif ($bruto === '') {
                    $errosOrcamento['valor'] = 'Indica o valor do orçamento.';
                } else {
                    $valorNovo = valorEuros($bruto);

                    if ($valorNovo === null) {
                        $errosOrcamento['valor'] = 'Escreve um valor em euros, por exemplo 149,90.';
                    }
                }

                if (!isset($tecnicosOrcamentoPorId[$tecnicoOrcamento])) {
                    $errosOrcamento['tecnico_id'] = $tecnicosOrcamento !== []
                        ? 'Escolhe o técnico que fez a alteração.'
                        : 'Ainda não há utilizadores registados na base de dados.';
                }

                $valorAtualCentesimos = $pedido['orcamento'] !== null
                    ? (int) round((float) $pedido['orcamento'] * 100)
                    : null;

                if (!$removerOrcamento
                    && $valorNovo !== null
                    && $valorAtualCentesimos !== null
                    && (int) round($valorNovo * 100) === $valorAtualCentesimos
                ) {
                    $errosOrcamento['valor'] = 'O orçamento já está em '
                        . euros((float) $pedido['orcamento']) . '.';
                }

                if ($errosOrcamento === [] && ($removerOrcamento || $valorNovo !== null)) {
                    $valorAnteriorSql = $pedido['orcamento'] !== null
                        ? number_format((float) $pedido['orcamento'], 2, '.', '')
                        : null;
                    $valorNovoSql = $removerOrcamento
                        ? null
                        : number_format((float) $valorNovo, 2, '.', '');

                    try {
                        $pdo->beginTransaction();

                        $atualizar = $pdo->prepare(
                            'UPDATE pedidos SET orcamento = ? WHERE id = ?'
                        );
                        $atualizar->execute([$valorNovoSql, $idPedido]);

                        $registar = $pdo->prepare(
                            'INSERT INTO historico_orcamentos
                                 (pedido_id, valor_anterior, valor_novo, tecnico_id)
                             VALUES (?, ?, ?, ?)'
                        );
                        $registar->execute([
                            $idPedido,
                            $valorAnteriorSql,
                            $valorNovoSql,
                            $tecnicoOrcamento,
                        ]);

                        $pdo->commit();
                    } catch (Throwable $erroGravacao) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        error_log('[FixIt][Pedido][Orçamento] ' . $erroGravacao->getMessage());
                        $errosOrcamento['geral'] = 'Não foi possível guardar a alteração ao orçamento e o registo no histórico. Confirma que a tabela historico_orcamentos foi criada (sql/migracao-historico-orcamentos.sql).';
                    }
                }

                if ($errosOrcamento === [] && ($removerOrcamento || $valorNovo !== null)) {
                    $_SESSION['pedido_orcamento_sucesso'] = $removerOrcamento
                        ? [
                            'titulo'   => 'Orçamento removido',
                            'texto'    => 'Orçamento removido, por '
                                . $tecnicosOrcamentoPorId[$tecnicoOrcamento] . '.',
                            'removido' => true,
                        ]
                        : [
                            'titulo'   => 'Orçamento guardado',
                            'texto'    => 'Orçamento atualizado para '
                                . euros((float) $valorNovo) . ', por '
                                . $tecnicosOrcamentoPorId[$tecnicoOrcamento] . '.',
                            'removido' => false,
                        ];

                    header('Location: pedido.php?id=' . $idPedido . '#orcamento', true, 303);
                    exit;
                }
            }

            // --- Novo PIN de consulta (POST → Redirect → GET) ---
            // O UPDATE troca o PIN no pedido: a consulta pública usa essa
            // coluna como chave, por isso o talão antigo deixa de abrir o
            // pedido no pedido HTTP seguinte. O PIN novo aparece na ficha
            // depois do redirecionamento.
            if (($_POST['acao'] ?? null) === 'novo_pin') {
                $tokenPin = $_POST['csrf'] ?? '';

                if (!is_string($tokenPin)
                    || empty($_SESSION['csrf_painel'])
                    || !hash_equals($_SESSION['csrf_painel'], $tokenPin)
                ) {
                    $erroPin = 'A sessão expirou. Recarrega a página e tenta novamente.';
                } else {
                    try {
                        $pinAnterior = (string) $pedido['pin_consulta'];
                        $pinNovo     = gerarPinUnico($pdo);

                        // O PIN e a linha do histórico são escritos juntos:
                        // um PIN novo nunca fica sem saber quem o gerou.
                        $pdo->beginTransaction();

                        $pdo->prepare(
                            'UPDATE pedidos SET pin_consulta = ? WHERE id = ?'
                        )->execute([$pinNovo, $idPedido]);

                        registarPinNoHistorico(
                            $pdo,
                            $idPedido,
                            (int) $utilizador['id'],
                            'renovacao'
                        );

                        $pdo->commit();

                        $_SESSION['pedido_pin_sucesso'] = [
                            'anterior' => $pinAnterior,
                            'novo'     => $pinNovo,
                        ];

                        header('Location: pedido.php?id=' . $idPedido . '#consulta', true, 303);
                        exit;
                    } catch (Throwable $erroGravacao) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        error_log('[FixIt][Pedido][PIN] ' . $erroGravacao->getMessage());
                        $erroPin = 'Não foi possível gerar um PIN novo e registá-lo no '
                            . 'histórico. Tenta novamente. Se o erro persistir, confirma '
                            . 'que a tabela historico_pins foi criada '
                            . '(sql/migracao-historico-pins.sql).';
                    }
                }
            }

            $sucessoAcao = AcaoEstadoPedido::consumirFlash();

            // Confirmação deixada pelo POST do orçamento (guardar ou remover).
            $mensagemOrcamento = $_SESSION['pedido_orcamento_sucesso'] ?? null;
            unset($_SESSION['pedido_orcamento_sucesso']);

            if (is_array($mensagemOrcamento)
                && is_string($mensagemOrcamento['titulo'] ?? null)
                && is_string($mensagemOrcamento['texto'] ?? null)
            ) {
                $sucessoOrcamento = [
                    'titulo'   => $mensagemOrcamento['titulo'],
                    'texto'    => $mensagemOrcamento['texto'],
                    'removido' => (bool) ($mensagemOrcamento['removido'] ?? false),
                ];
            }

            // Confirmação deixada pelo POST do PIN novo.
            $mensagemPin = $_SESSION['pedido_pin_sucesso'] ?? null;
            unset($_SESSION['pedido_pin_sucesso']);

            if (is_array($mensagemPin)
                && is_string($mensagemPin['anterior'] ?? null)
                && is_string($mensagemPin['novo'] ?? null)
            ) {
                $sucessoPin = [
                    'anterior' => $mensagemPin['anterior'],
                    'novo'     => $mensagemPin['novo'],
                ];
            }

            // --- Um único histórico, ordenado por data ---
            $eventos = [];

            foreach ($historicoEstados as $linha) {
                $eventos[] = [
                    'tipo'         => 'estado',
                    'estado'       => $linha['estado'],
                    'registado_em' => $linha['registado_em'],
                    'tecnico_nome' => $linha['tecnico_nome'],
                    'ordem'        => (int) $linha['id'],
                ];
            }

            foreach ($historicoOrcamentos as $linha) {
                $eventos[] = [
                    'tipo'           => 'orcamento',
                    'valor_anterior' => $linha['valor_anterior'],
                    'valor_novo'     => $linha['valor_novo'],
                    'registado_em'   => $linha['registado_em'],
                    'tecnico_nome'   => $linha['tecnico_nome'],
                    'ordem'          => (int) $linha['id'],
                ];
            }

            foreach ($historicoTecnicos as $linha) {
                $eventos[] = [
                    'tipo'          => 'tecnico',
                    'anterior_nome' => $linha['anterior_nome'],
                    'novo_nome'     => $linha['novo_nome'],
                    'registado_em'  => $linha['registado_em'],
                    'tecnico_nome'  => $linha['tecnico_nome'],
                    'ordem'         => (int) $linha['id'],
                ];
            }

            foreach ($historicoPins as $indicePin => $linha) {
                $eventos[] = [
                    'tipo'         => 'pin',
                    'motivo'       => $linha['motivo'] === 'inicial' ? 'inicial' : 'renovacao',
                    'registado_em' => $linha['registado_em'],
                    'tecnico_nome' => $linha['tecnico_nome'],
                    'ordem'        => (int) $linha['id'],
                    // null = PIN atual ou contagem indisponível.
                    'usos'         => $usosPin[(int) $linha['id']] ?? null,
                    // Data da última consulta na janela do talão;
                    // null = nenhuma (ou contagem indisponível).
                    'ultima'       => $ultimasUsoPin[(int) $linha['id']] ?? null,
                    // O talão da emissão mais recente é o que está em uso.
                    'emUso'        => $indicePin === 0,
                ];
            }

            usort($eventos, static function (array $a, array $b): int {
                $porData = strcmp((string) $b['registado_em'], (string) $a['registado_em']);

                return $porData !== 0 ? $porData : ($b['ordem'] <=> $a['ordem']);
            });

            // O estado atual é a passagem de estado mais recente.
            foreach ($eventos as $indice => $evento) {
                if ($evento['tipo'] === 'estado') {
                    $indiceEstadoAtual = $indice;
                    break;
                }
            }
        }
    }
} catch (Throwable $erro) {
    error_log('[FixIt][Pedido] ' . $erro->getMessage());
    $erroDados = 'Confirma que o servidor MySQL está ligado e que a base de dados fixit foi importada.';
    $pedido = null;
}

if ($pedido === null && $erroDados === '') {
    http_response_code(404);
}

// O estado acompanha o número na migalha e no separador: com várias fichas
// abertas, "Pedido #1042" sozinho obriga a entrar em cada uma para saber em
// que ponto está. A etiqueta sai da mesma tabela que o histórico e o painel
// de mudança de estado usam, para não haver uma segunda nomenclatura.
$estadoAtualPedido = $pedido !== null
    ? ($etiquetasEstado[$pedido['estado']] ?? (string) $pedido['estado'])
    : '';
$tituloPedido = $pedido !== null
    ? 'Pedido #' . (int) $pedido['id'] . ' · ' . $estadoAtualPedido
    : 'Pedido não encontrado';
?>
<?php painelAbrir(
    'pedidos',
    $tituloPedido,
    'Detalhe da ordem de serviço FixIt, com histórico de estados, dados do cliente e consulta pública.',
    [
        ['Painel', 'painel.php'],
        ['Pedidos', 'pedidos.php'],
        [$pedido !== null ? '#' . (int) $pedido['id'] . ' · ' . $estadoAtualPedido : 'Não encontrado', null],
    ],
    $erroDados,
    true,
    $pedidosAtivos ?? -1
); ?>

<main class="principal" id="conteudo" tabindex="-1">

    <div class="cabecalho">
        <div class="cabecalho__texto">
            <?php
            // O número sozinho obrigava a descer ao painel da consulta para
            // saber em que ponto está o pedido. O estado vai ao lado dele, na
            // mesma pílula do histórico e da lista — a cor diz o mesmo que as
            // linhas da lista dizem. É o mesmo par da migalha e do separador,
            // para a página, o caminho e o título do browser contarem a mesma
            // história.
            ?>
            <h1 class="cabecalho__titulo-ficha">
                <?php if ($pedido !== null): ?>
                    <span>Pedido #<?= (int) $pedido['id'] ?></span>
                    <?php /* A pílula é o atalho para o painel que a muda: o
                             mesmo painel que o «Mudar» da lista abre, mas
                             sem sair desta página — o painel já está aqui
                             em baixo, em `#alterar`. Sem o texto escondido,
                             o link ler-se-ia só como o estado atual, e não
                             como a forma de o mudar. */ ?>
                    <a class="estado <?= h($classesEstado[$pedido['estado']] ?? 'estado--recebido') ?>"
                       href="#alterar"><?= h($estadoAtualPedido) ?><span class="visualmente-oculto"> — abrir o painel para mudar o estado deste pedido</span></a>
                <?php else: ?>
                    <span>Pedido não encontrado</span>
                <?php endif; ?>
            </h1>
            <?php
            // A espera longa lê-se no cabeçalho, antes de se chegar ao painel
            // que a explica: é a marca âmbar que a lista e o painel da
            // consulta já usam, com a mesma régua — a palavra «sem consulta»
            // serve tanto a um talão que ninguém abriu como a um que o
            // cliente deixou de abrir.
            //
            // É também o caminho para de onde ela vem: a lista dos
            // esquecidos, pela espera do PIN mais longa primeiro, como a
            // idade marcada na coluna da lista. Um aviso sem saída obrigava a
            // ir à lista e aplicar o filtro à mão para ver os restantes.
            ?>
            <?php if ($pedido !== null && $consultaAntiga): ?>
                <p class="cabecalho__marca">
                    <a class="consulta__idade consulta__idade--longa" href="<?= h($urlEsquecidos) ?>">Sem consulta <?= h(haTempo($esperaPinDesde)) ?><?php if ($contagemEsquecidos !== ''): ?><span class="consulta__idade-contagem"> · <?= $contagemEsquecidos ?></span><?php endif ?><span class="visualmente-oculto"><?= h($anuncioCabecalho) ?></span></a>
                </p>
            <?php endif; ?>
            <p class="cabecalho__descricao">
                <?php if ($pedido !== null): ?>
                    <?= h($pedido['cliente_nome']) ?> · <?= h($pedido['marca_modelo']) ?>
                    · entrou a <?= h(dataCurta($pedido['data_entrada'])) ?>
                <?php elseif ($erroDados !== ''): ?>
                    Não foi possível ler os dados deste pedido.
                <?php else: ?>
                    O pedido #<?= (int) $idPedido ?> não existe ou foi eliminado.
                <?php endif; ?>
            </p>
        </div>

        <a class="botao botao--fantasma" href="pedidos.php">
            <svg aria-hidden="true"><use href="#icone-seta-esquerda"/></svg>
            <span>Voltar aos pedidos</span>
        </a>
    </div>

    <?php if ($erroDados !== ''): ?>
        <section class="painel" aria-labelledby="titulo-erro">
            <div class="aviso aviso--erro">
                <span class="aviso__icone" aria-hidden="true">
                    <svg><use href="#icone-info"/></svg>
                </span>
                <p class="aviso__titulo" id="titulo-erro">Não foi possível carregar o pedido</p>
                <p class="aviso__texto"><?= h($erroDados) ?></p>
                <a class="botao botao--fantasma" href="pedidos.php">Voltar aos pedidos</a>
            </div>
        </section>
    <?php elseif ($pedido === null): ?>
        <section class="painel" aria-labelledby="titulo-nao-encontrado">
            <div class="aviso">
                <span class="aviso__icone" aria-hidden="true">
                    <svg><use href="#icone-pesquisa"/></svg>
                </span>
                <p class="aviso__titulo" id="titulo-nao-encontrado">Este pedido não está disponível</p>
                <p class="aviso__texto">
                    Confirma o número na lista de pedidos. Um link antigo pode
                    apontar para um pedido que já foi eliminado.
                </p>
                <a class="botao botao--fantasma" href="pedidos.php">Ver todos os pedidos</a>
            </div>
        </section>
    <?php else: ?>

        <?php if ($sucessoAcao !== ''): ?>
            <div class="alerta alerta--sucesso" role="status">
                <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                <div>
                    <p class="alerta__titulo">Pedido atualizado</p>
                    <p class="alerta__texto">
                        <?= h($sucessoAcao) ?>
                        O novo estado já está visível na
                        <a class="alerta__ligacao" href="../frontoffice/index.php">consulta pública</a>.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <div class="detalhe-pedido">

            <!-- =========================================================
                 COLUNA PRINCIPAL: mudar estado, avaria, histórico
                 ========================================================= -->
            <div class="detalhe-pedido__coluna">

                <?php
                // Ação de mudança de estado. O módulo desenha o painel,
                // valida o POST e grava; o título evita repetir o <h1>.
                // "Cancelar" recarrega esta página, sem gravar.
                AcaoEstadoPedido::renderFormulario(
                    $acaoEstado,
                    $etiquetasEstado,
                    $classesEstado,
                    'pedido.php?id=' . $idPedido . '#alterar',
                    'pedido.php?id=' . $idPedido,
                    'Mudar o estado'
                );
                ?>

                <section class="painel" id="orcamento" aria-labelledby="titulo-orcamento">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-orcamento">Orçamento</h2>
                            <p class="painel__descricao">
                                O valor que o cliente vê na consulta pública.
                            </p>
                        </div>
                        <p class="orcamento__atual<?= $pedido['orcamento'] !== null ? '' : ' orcamento__atual--vazio' ?>">
                            <span class="visualmente-oculto">Orçamento atual: </span>
                            <?= $pedido['orcamento'] !== null
                                ? h(euros((float) $pedido['orcamento']))
                                : 'Por definir' ?>
                        </p>
                    </div>

                    <?php if ($sucessoOrcamento !== []): ?>
                        <div class="alerta alerta--sucesso" role="status">
                            <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                            <div>
                                <p class="alerta__titulo"><?= h($sucessoOrcamento['titulo']) ?></p>
                                <p class="alerta__texto">
                                    <?= h($sucessoOrcamento['texto']) ?>
                                    <?php if ($sucessoOrcamento['removido']): ?>
                                        O pedido volta a «A definir após diagnóstico» na
                                    <?php else: ?>
                                        O valor já está visível na
                                    <?php endif; ?>
                                    <a class="alerta__ligacao" href="../frontoffice/index.php">consulta pública</a>.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form class="orcamento" method="post"
                          action="pedido.php?id=<?= (int) $pedido['id'] ?>#orcamento" novalidate>
                        <input type="hidden" name="csrf" value="<?= h(AcaoEstadoPedido::token()) ?>">
                        <input type="hidden" name="pedido_id" value="<?= (int) $pedido['id'] ?>">

                        <?php if (isset($errosOrcamento['geral'])): ?>
                            <div class="alerta" role="alert">
                                <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                                <div>
                                    <p class="alerta__titulo">Não foi possível
                                        <?= $removerOrcamento ? 'remover' : 'guardar' ?></p>
                                    <p class="alerta__texto"><?= h($errosOrcamento['geral']) ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="orcamento__linha">
                            <div class="campo<?= isset($errosOrcamento['valor']) ? ' campo--invalido' : '' ?>">
                                <label class="campo__rotulo" for="orcamento-valor">Valor em euros</label>
                                <input class="entrada" type="text" id="orcamento-valor" name="orcamento"
                                       inputmode="decimal" autocomplete="off" placeholder="149,90"
                                       value="<?= h($valorOrcamento) ?>"
                                       aria-describedby="erro-orcamento-valor"
                                       <?= isset($errosOrcamento['valor']) ? 'aria-invalid="true"' : '' ?>>
                                <p class="campo__erro" id="erro-orcamento-valor"
                                   <?= isset($errosOrcamento['valor']) ? '' : 'hidden' ?>><?= h($errosOrcamento['valor'] ?? '') ?></p>
                            </div>

                            <div class="campo<?= isset($errosOrcamento['tecnico_id']) ? ' campo--invalido' : '' ?>">
                                <label class="campo__rotulo" for="orcamento-tecnico">Técnico que fez a alteração</label>
                                <select class="entrada" id="orcamento-tecnico" name="tecnico_id"
                                        aria-describedby="erro-orcamento-tecnico"
                                        <?= isset($errosOrcamento['tecnico_id']) ? 'aria-invalid="true"' : '' ?>
                                        <?= $tecnicosOrcamento === [] ? 'disabled' : '' ?>>
                                    <option value="">Escolher técnico…</option>
                                    <?php foreach ($tecnicosOrcamento as $linhaTecnico): ?>
                                        <option value="<?= (int) $linhaTecnico['id'] ?>"
                                            <?= $tecnicoOrcamento === (int) $linhaTecnico['id'] ? 'selected' : '' ?>><?= h($linhaTecnico['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="campo__erro" id="erro-orcamento-tecnico"
                                   <?= isset($errosOrcamento['tecnico_id']) ? '' : 'hidden' ?>><?= h($errosOrcamento['tecnico_id'] ?? '') ?></p>
                            </div>

                            <button class="botao botao--primario" type="submit"
                                    name="acao" value="alterar_orcamento"
                                    <?= $tecnicosOrcamento === [] ? 'disabled' : '' ?>>Guardar orçamento</button>
                        </div>

                        <?php if ($tecnicosOrcamento === []): ?>
                            <p class="orcamento__vazio">
                                Ainda não há utilizadores registados. Sem um técnico não
                                é possível registar a alteração.
                            </p>
                        <?php endif; ?>

                        <p class="orcamento__nota">
                            Aceita 149,90 ou 149.90. Cada alteração fica no histórico
                            com o valor anterior, o novo e o técnico.
                        </p>

                        <?php if ($pedido['orcamento'] !== null): ?>
                            <div class="orcamento__remover">
                                <details>
                                    <summary class="botao botao--perigo-fantasma orcamento__remover-botao">
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-linecap="round"
                                             stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                        Remover orçamento
                                    </summary>

                                    <div class="orcamento__confirmacao">
                                        <p class="orcamento__confirmacao-titulo">
                                            Remover o orçamento de
                                            <strong><?= h(euros((float) $pedido['orcamento'])) ?></strong>?
                                        </p>
                                        <p class="orcamento__confirmacao-texto" id="orcamento-confirmacao-texto">
                                            O pedido volta a «A definir após diagnóstico» na consulta
                                            pública. O valor anterior e o técnico escolhido ficam no
                                            histórico.
                                        </p>
                                        <div class="orcamento__confirmacao-acoes">
                                            <button class="botao botao--fantasma" type="button"
                                                    data-fechar-remocao>Cancelar</button>
                                            <button class="botao botao--perigo" type="submit"
                                                    name="acao" value="remover_orcamento"
                                                    aria-describedby="orcamento-confirmacao-texto"
                                                    <?= $tecnicosOrcamento === [] ? 'disabled' : '' ?>>Sim, remover</button>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        <?php endif; ?>
                    </form>
                </section>

                <section class="painel" id="avaria" aria-labelledby="titulo-avaria">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-avaria">Avaria descrita na receção</h2>
                            <p class="painel__descricao">
                                O que o cliente relatou quando deixou o equipamento.
                            </p>
                        </div>
                    </div>
                    <div class="painel__corpo">
                        <?php if (trim((string) $pedido['descricao_avaria']) !== ''): ?>
                            <p class="avaria"><?= h($pedido['descricao_avaria']) ?></p>
                        <?php else: ?>
                            <p class="avaria avaria--vazia">Sem descrição registada neste pedido.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="painel" id="fotografias" aria-labelledby="titulo-fotografias">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-fotografias">Fotografias</h2>
                            <p class="painel__descricao">
                                O estado do equipamento à entrada, tal como foi fotografado no balcão.
                            </p>
                        </div>
                    </div>
                    <div class="painel__corpo">
                        <?php if ($fotosPedido !== []): ?>
                            <ul class="galeria">
                                <?php foreach ($fotosPedido as $indiceFoto => $fotoPedido): ?>
                                    <li class="galeria__item">
                                        <a class="galeria__ligacao" href="../<?= h($fotoPedido['caminho_foto']) ?>"
                                           target="_blank" rel="noopener">
                                            <img class="galeria__imagem"
                                                 src="../<?= h($fotoPedido['caminho_foto']) ?>"
                                                 alt="<?= h(trim((string) $fotoPedido['descricao']) !== ''
                                                     ? (string) $fotoPedido['descricao']
                                                     : 'Fotografia ' . ($indiceFoto + 1) . ' do equipamento') ?>"
                                                 loading="lazy">
                                        </a>
                                        <p class="galeria__legenda">
                                            <?= h(date('d/m/Y', strtotime((string) $fotoPedido['data_upload']))) ?>
                                        </p>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="avaria avaria--vazia">Sem fotografias anexadas a este pedido.</p>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="painel" id="historico" aria-labelledby="titulo-historico">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-historico">Histórico</h2>
                            <p class="painel__descricao">
                                <?php if ($eventos !== []): ?>
                                    <?= count($eventos) ?> <?= count($eventos) === 1 ? 'registo' : 'registos' ?> de estado,
                                    de técnico, de orçamento e de PIN, do mais recente para o mais antigo.
                                <?php else: ?>
                                    Ainda sem registos para este pedido.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <?php if ($eventos === []): ?>
                        <div class="painel__corpo">
                            <p class="avaria avaria--vazia">
                                Sem histórico guardado. Cada mudança de estado, cada
                                troca de técnico, cada alteração ao orçamento e cada
                                PIN emitido ficam aqui registados, com data e autor.
                            </p>
                        </div>
                    <?php else: ?>
                        <ol class="historico" aria-label="Mudanças de estado, trocas de técnico, alterações ao orçamento e PINs emitidos deste pedido">
                            <?php foreach ($eventos as $indice => $evento): ?>
                                <?php
                                // A passagem de estado mais recente é marcada
                                // como atual se coincidir com o estado do pedido.
                                $atual = $evento['tipo'] === 'estado'
                                    && $indice === $indiceEstadoAtual
                                    && $evento['estado'] === $pedido['estado'];
                                ?>
                                <?php if ($evento['tipo'] === 'pin'): ?>
                                    <?php $pinInicial = ($evento['motivo'] ?? '') === 'inicial'; ?>
                                    <li class="historico__item historico__item--pin">
                                        <span class="historico__marca" aria-hidden="true"></span>
                                        <div class="historico__corpo">
                                            <div class="historico__topo">
                                                <span class="historico__etiqueta">PIN</span>
                                            </div>
                                            <p class="historico__mudanca">
                                                <?php if ($pinInicial): ?>
                                                    <strong>PIN de consulta atribuído</strong>
                                                    <span class="historico__de">o primeiro talão deste pedido</span>
                                                <?php else: ?>
                                                    <strong>PIN de consulta renovado</strong>
                                                    <span class="historico__de">o talão anterior deixou de funcionar</span>
                                                <?php endif; ?>
                                            </p>
                                            <?php if (($evento['usos'] ?? null) !== null): ?>
                                                <p class="historico__pin-uso">
                                                    <?php if ($evento['usos'] === 0): ?>
                                                        Não foi consultado antes de ser substituído.
                                                    <?php else: ?>
                                                        Foi consultado
                                                        <strong><?= (int) $evento['usos'] ?> <?= $evento['usos'] === 1 ? 'vez' : 'vezes' ?></strong>
                                                        antes de ser substituído<?php if (($evento['ultima'] ?? null) !== null): ?>,
                                                        a última em
                                                        <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $evento['ultima']))) ?>"><?= h(dataHora($evento['ultima'])) ?></time>
                                                        <?php /* Há quanto tempo foi, ao lado da data: a data
                                                                 diz quando, a idade diz quanto. A mesma
                                                                 régua de dias da ficha e da lista. */ ?>
                                                        — <?= h(haTempo($evento['ultima'])) ?><?php endif; ?>.
                                                    <?php endif; ?>
                                                </p>
                                            <?php elseif ($entregaEm !== null && $consultasDisponiveis && ($evento['emUso'] ?? false)): ?>
                                                <p class="historico__pin-uso">
                                                    <?php if (($evento['ultima'] ?? null) !== null): ?>
                                                        Foi consultado pela última vez antes da entrega, em
                                                        <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $evento['ultima']))) ?>"><?= h(dataHora($evento['ultima'])) ?></time>
                                                        — <?= h(haTempo($evento['ultima'])) ?>.
                                                    <?php else: ?>
                                                        Não foi consultado antes da entrega.
                                                    <?php endif; ?>
                                                </p>
                                            <?php endif; ?>
                                            <p class="historico__meta">
                                                <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $evento['registado_em']))) ?>">
                                                    <?= h(dataHora($evento['registado_em'])) ?>
                                                </time>
                                                <?php if ($evento['tecnico_nome'] !== null && $evento['tecnico_nome'] !== ''): ?>
                                                    <span aria-hidden="true"> · </span><?= h($evento['tecnico_nome']) ?>
                                                <?php else: ?>
                                                    <span aria-hidden="true"> · </span><span class="tabela__por-atribuir">sem autor registado</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php elseif ($evento['tipo'] === 'tecnico'): ?>
                                    <li class="historico__item historico__item--tecnico">
                                        <span class="historico__marca" aria-hidden="true"></span>
                                        <div class="historico__corpo">
                                            <div class="historico__topo">
                                                <span class="historico__etiqueta">Técnico</span>
                                            </div>
                                            <p class="historico__mudanca">
                                                <?php if (($evento['anterior_nome'] ?? null) !== null && $evento['anterior_nome'] !== ''): ?>
                                                    <span class="historico__de"><?= h($evento['anterior_nome']) ?></span>
                                                <?php else: ?>
                                                    <span class="historico__de">Por atribuir</span>
                                                <?php endif; ?>
                                                <span class="visualmente-oculto">passou para</span>
                                                <span aria-hidden="true">→</span>
                                                <?php if (($evento['novo_nome'] ?? null) !== null && $evento['novo_nome'] !== ''): ?>
                                                    <strong><?= h($evento['novo_nome']) ?></strong>
                                                <?php else: ?>
                                                    <strong>Por atribuir</strong>
                                                    <span class="visualmente-oculto">(ficou sem técnico)</span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="historico__meta">
                                                <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $evento['registado_em']))) ?>">
                                                    <?= h(dataHora($evento['registado_em'])) ?>
                                                </time>
                                                <?php if ($evento['tecnico_nome'] !== null && $evento['tecnico_nome'] !== ''): ?>
                                                    <span aria-hidden="true"> · </span><?= h($evento['tecnico_nome']) ?>
                                                <?php else: ?>
                                                    <span aria-hidden="true"> · </span><span class="tabela__por-atribuir">sem autor registado</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php elseif ($evento['tipo'] === 'orcamento'): ?>
                                    <li class="historico__item historico__item--orcamento">
                                        <span class="historico__marca" aria-hidden="true"></span>
                                        <div class="historico__corpo">
                                            <div class="historico__topo">
                                                <span class="historico__etiqueta">Orçamento</span>
                                            </div>
                                            <p class="historico__mudanca">
                                                <?php if ($evento['valor_anterior'] !== null): ?>
                                                    <span class="historico__de"><?= h(euros((float) $evento['valor_anterior'])) ?></span>
                                                <?php else: ?>
                                                    <span class="historico__de">Por definir</span>
                                                <?php endif; ?>
                                                <span class="visualmente-oculto">passou para</span>
                                                <span aria-hidden="true">→</span>
                                                <?php if ($evento['valor_novo'] !== null): ?>
                                                    <strong><?= h(euros((float) $evento['valor_novo'])) ?></strong>
                                                <?php else: ?>
                                                    <strong>Por definir</strong>
                                                    <span class="visualmente-oculto">(orçamento removido)</span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="historico__meta">
                                                <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $evento['registado_em']))) ?>">
                                                    <?= h(dataHora($evento['registado_em'])) ?>
                                                </time>
                                                <?php if ($evento['tecnico_nome'] !== null && $evento['tecnico_nome'] !== ''): ?>
                                                    <span aria-hidden="true"> · </span><?= h($evento['tecnico_nome']) ?>
                                                <?php else: ?>
                                                    <span aria-hidden="true"> · </span><span class="tabela__por-atribuir">sem técnico registado</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php else: ?>
                                    <li class="historico__item<?= $atual ? ' historico__item--atual' : '' ?>">
                                        <span class="historico__marca" aria-hidden="true"></span>
                                        <div class="historico__corpo">
                                            <div class="historico__topo">
                                                <span class="estado <?= h($classesEstado[$evento['estado']] ?? 'estado--recebido') ?>">
                                                    <?= h($etiquetasEstado[$evento['estado']] ?? $evento['estado']) ?>
                                                </span>
                                                <?php if ($atual): ?>
                                                    <span class="historico__atual">Estado atual</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="historico__meta">
                                                <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $evento['registado_em']))) ?>">
                                                    <?= h(dataHora($evento['registado_em'])) ?>
                                                </time>
                                                <?php if ($evento['tecnico_nome'] !== null && $evento['tecnico_nome'] !== ''): ?>
                                                    <span aria-hidden="true"> · </span><?= h($evento['tecnico_nome']) ?>
                                                <?php else: ?>
                                                    <span aria-hidden="true"> · </span><span class="tabela__por-atribuir">sem técnico registado</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>

                    <?php if ($consultasDisponiveis): ?>
                        <?php /* Num pedido entregue a contagem fecha na entrega,
                                 e o rodapé di-lo: sem isso, um número maior do
                                 que as consultas que a oficina acompanhou lê-se
                                 como se o cliente tivesse seguido o pedido até
                                 ao fim. */ ?>
                        <div class="painel__rodape historico__consultas">
                            <?php if ($consultasPin > 0): ?>
                                <p>
                                    <strong><?= $consultasPin ?> <?= $consultasPin === 1 ? 'consulta' : 'consultas' ?></strong>
                                    com o PIN atual<?= $entregaEm !== null ? ' antes da entrega' : '' ?>,
                                    <?= $consultasPin === 1 ? 'em' : 'a última em' ?>
                                    <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $ultimaConsulta))) ?>"><?= h(dataHora($ultimaConsulta)) ?></time>.
                                </p>
                            <?php elseif ($entregaEm !== null): ?>
                                <p>Nenhuma consulta com o PIN atual antes da entrega.</p>
                            <?php else: ?>
                                <p>Ainda sem consultas com o PIN atual.</p>
                            <?php endif; ?>
                            <?php /* O que o cliente viu depois de levantar a
                                     máquina não entra na conta de cima, mas
                                     também não desaparece da ficha: dito à
                                     parte, o número de cima continua a ser o
                                     da oficina e este o do depois. */ ?>
                            <?php if ($consultasDepois > 0): ?>
                                <p class="historico__consultas-depois">
                                    Mais <strong><?= $consultasDepois ?> <?= $consultasDepois === 1 ? 'consulta' : 'consultas' ?></strong>
                                    depois da entrega,
                                    <?= $consultasDepois === 1 ? 'em' : 'a última em' ?>
                                    <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $ultimaDepois))) ?>"><?= h(dataHora($ultimaDepois)) ?></time>.
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </div>

            <!-- =========================================================
                 COLUNA LATERAL: quem, o quê, como consulta o cliente
                 ========================================================= -->
            <aside class="detalhe-pedido__coluna" aria-label="Dados do pedido">

                <section class="painel" id="cliente" aria-labelledby="titulo-cliente">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-cliente">Cliente</h2>
                        </div>
                    </div>
                    <dl class="ficha-pedido">
                        <div class="ficha-pedido__item">
                            <dt>Nome</dt>
                            <dd><?= h($pedido['cliente_nome']) ?></dd>
                        </div>
                        <div class="ficha-pedido__item">
                            <dt>NIF</dt>
                            <dd class="ficha-pedido__numero"><?= h($pedido['cliente_nif']) ?></dd>
                        </div>
                        <div class="ficha-pedido__item">
                            <dt>Telefone</dt>
                            <dd class="ficha-pedido__numero"><?= h($pedido['cliente_telefone']) ?></dd>
                        </div>
                        <div class="ficha-pedido__item">
                            <dt>Email</dt>
                            <?php if (($pedido['cliente_email'] ?? '') !== '' && $pedido['cliente_email'] !== null): ?>
                                <dd><?= h($pedido['cliente_email']) ?></dd>
                            <?php else: ?>
                                <dd class="ficha-pedido__vazio">Não indicado</dd>
                            <?php endif; ?>
                        </div>
                    </dl>
                </section>

                <section class="painel" id="equipamento" aria-labelledby="titulo-equipamento">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-equipamento">Equipamento</h2>
                        </div>
                    </div>
                    <dl class="ficha-pedido">
                        <div class="ficha-pedido__item">
                            <dt>Modelo</dt>
                            <dd><?= h($pedido['marca_modelo']) ?></dd>
                        </div>
                        <div class="ficha-pedido__item">
                            <dt>Tipo</dt>
                            <dd><?= h($pedido['tipo']) ?></dd>
                        </div>
                        <div class="ficha-pedido__item">
                            <dt>Nº de série</dt>
                            <?php if (($pedido['numero_serie'] ?? '') !== '' && $pedido['numero_serie'] !== null): ?>
                                <dd class="ficha-pedido__numero"><?= h($pedido['numero_serie']) ?></dd>
                            <?php else: ?>
                                <dd class="ficha-pedido__vazio">Sem número de série</dd>
                            <?php endif; ?>
                        </div>
                    </dl>
                    <div class="painel__rodape">
                        <a class="ligacao-acao" href="pedidos.php?equipamento=<?= (int) $pedido['equipamento_id'] ?>#lista">
                            Ver todos os pedidos desta máquina
                        </a>
                    </div>
                </section>

                <section class="painel" id="consulta" aria-labelledby="titulo-consulta">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-consulta">Consulta pública</h2>
                            <p class="painel__descricao">O que o cliente vê, sem conta.</p>
                        </div>
                    </div>

                    <?php if ($sucessoPin !== []): ?>
                        <div class="alerta alerta--sucesso" role="status">
                            <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                            <div>
                                <p class="alerta__titulo">PIN de consulta atualizado</p>
                                <p class="alerta__texto">
                                    O PIN anterior (<?= h($sucessoPin['anterior']) ?>) deixou de
                                    funcionar. Entrega o novo ao cliente — é com ele que a
                                    consulta pública abre.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($erroPin !== ''): ?>
                        <div class="alerta alerta--erro" role="alert">
                            <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                            <div>
                                <p class="alerta__titulo">PIN não alterado</p>
                                <p class="alerta__texto"><?= h($erroPin) ?></p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="consulta">
                        <p class="consulta__rotulo">PIN de consulta</p>
                        <p class="consulta__pin"><?= h($pedido['pin_consulta']) ?></p>
                        <?php
                        // Há quanto tempo espera este PIN e desde quando —
                        // a lista mostra-os no mesmo par, "há 12 dias
                        // (17/10/2026, 09:12)", e o painel nas notas dos
                        // atalhos. Num pedido já consultado a espera conta
                        // desde a última consulta; num talão que ninguém
                        // abriu, desde a emissão, que é o que a lista
                        // também usa. O <time> dá a data legível às
                        // máquinas, como no resto da ficha.
                        // A partir de uma semana a espera veste a mesma pílula
                        // âmbar da lista de pedidos e da marca do cabeçalho —
                        // é a marca que os "há mais de uma semana" da oficina
                        // partilham. Os dias ficam dentro dela e a data de onde
                        // contam fica de fora, como na coluna das consultas: a
                        // cor sublinha a idade, não a data. Um pedido entregue
                        // não a leva: o âmbar pede atenção a quem ainda tem o
                        // trabalho em mãos, e a lista também o deixa de fora.
                        ?>
                        <?php if ($esperaPinDias !== null): ?>
                            <?php
                            // A idade âmbar é o segundo caminho para o mesmo
                            // recorte do aviso do cabeçalho: a lista dos
                            // esquecidos, pela espera do PIN mais longa
                            // primeiro, onde estão os restantes. Leva o mesmo
                            // número à vista do cabeçalho — o tamanho do
                            // recorte, quando é mais do que este pedido — e o
                            // mesmo anúncio, para os dois avisos da ficha se
                            // lerem da mesma maneira. Abaixo de uma semana a
                            // idade fica texto simples: não há recorte a que
                            // ela leve.
                            $idadePinHtml = $consultaAntiga
                                ? '<a class="consulta__idade consulta__idade--longa" href="' . h($urlEsquecidos) . '">'
                                  . h(haTempo($esperaPinDesde))
                                  . ($contagemEsquecidos !== ''
                                      ? '<span class="consulta__idade-contagem"> · ' . h($contagemEsquecidos) . '</span>'
                                      : '')
                                  . '<span class="visualmente-oculto">' . h($anuncioCabecalho) . '</span>'
                                  . '</a>'
                                : '<span class="consulta__idade">' . h(haTempo($esperaPinDesde)) . '</span>';
                            ?>
                            <p class="consulta__estado">
                                <?php if ($consultasPin > 0): ?>
                                    Última consulta <?= $idadePinHtml ?>,
                                    em <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $esperaPinDesde))) ?>"><?= h(dataHora($esperaPinDesde)) ?></time>.
                                <?php else: ?>
                                    PIN por abrir <?= $idadePinHtml ?>,
                                    desde <time datetime="<?= h(date('Y-m-d\TH:i', strtotime((string) $esperaPinDesde))) ?>"><?= h(dataHora($esperaPinDesde)) ?></time>.
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p class="consulta__nota">
                            Com este PIN, o cliente acompanha o pedido. Não precisa
                            de conta nem de NIF. Assim que guardares, a consulta
                            pública já mostra o novo estado e o orçamento.
                        </p>
                        <a class="botao botao--fantasma botao--pequeno" href="../frontoffice/index.php">
                            Abrir consulta pública
                        </a>

                        <form class="consulta__gerar" method="post"
                              action="pedido.php?id=<?= $idPedido ?>#consulta">
                            <input type="hidden" name="csrf" value="<?= h(Sessao::csrf()) ?>">
                            <input type="hidden" name="acao" value="novo_pin">

                            <details>
                                <summary class="botao botao--fantasma botao--pequeno consulta__gerar-botao">
                                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-linecap="round"
                                         stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    Gerar PIN novo
                                </summary>

                                <div class="consulta__confirmacao">
                                    <p class="consulta__confirmacao-titulo">
                                        Gerar um PIN novo para este pedido?
                                    </p>
                                    <p class="consulta__confirmacao-texto" id="consulta-confirmacao-texto">
                                        O PIN atual (<strong><?= h($pedido['pin_consulta']) ?></strong>)
                                        deixa de funcionar imediatamente e quem tiver o talão
                                        antigo perde o acesso. O cliente tem de receber o novo.
                                    </p>
                                    <div class="consulta__confirmacao-acoes">
                                        <button class="botao botao--fantasma" type="button"
                                                data-fechar-pin>Cancelar</button>
                                        <button class="botao botao--perigo" type="submit"
                                                aria-describedby="consulta-confirmacao-texto">
                                            Sim, gerar PIN novo
                                        </button>
                                    </div>
                                </div>
                            </details>
                        </form>
                    </div>
                </section>

                <section class="painel" id="datas" aria-labelledby="titulo-datas">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-datas">Datas</h2>
                        </div>
                    </div>
                    <dl class="ficha-pedido">
                        <div class="ficha-pedido__item">
                            <dt>Técnico atual</dt>
                            <?php if (($pedido['tecnico_nome'] ?? '') !== '' && $pedido['tecnico_nome'] !== null): ?>
                                <dd><?= h($pedido['tecnico_nome']) ?></dd>
                            <?php else: ?>
                                <dd class="ficha-pedido__vazio">Por atribuir</dd>
                            <?php endif; ?>
                        </div>
                        <div class="ficha-pedido__item">
                            <dt>Entrada</dt>
                            <dd class="ficha-pedido__numero"><?= h(dataHora($pedido['data_entrada'])) ?></dd>
                        </div>
                        <div class="ficha-pedido__item">
                            <dt>Conclusão</dt>
                            <dd class="ficha-pedido__numero">
                                <?= $pedido['data_conclusao'] !== null
                                    ? h(dataHora($pedido['data_conclusao']))
                                    : 'Ainda em curso' ?>
                            </dd>
                        </div>
                    </dl>
                </section>

            </aside>

        </div>

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

/* =====================================================================
   Confirmação da remoção do orçamento.
   "Cancelar" e Escape fecham o painel sem recarregar a página (o
   <details> é nativo, por isso sem JavaScript a seta continua a abrir
   e a fechar). O Escape cede ao menu lateral quando este está aberto.
   ===================================================================== */
(function () {
    "use strict";

    var seletor = ".orcamento__remover details, .consulta__gerar details";

    function fechar(detalhe) {
        detalhe.open = false;

        var resumo = detalhe.querySelector("summary");
        if (resumo) {
            resumo.focus();
        }
    }

    document.querySelectorAll("[data-fechar-remocao], [data-fechar-pin]").forEach(function (botao) {
        botao.addEventListener("click", function () {
            var detalhe = botao.closest("details");

            if (detalhe) {
                fechar(detalhe);
            }
        });
    });

    // Na fase de captura, para ver o menu lateral ainda aberto: o
    // primeiro Escape fecha o menu e só um segundo chega aqui.
    document.addEventListener("keydown", function (evento) {
        if (evento.key !== "Escape" || document.body.classList.contains("nav-aberto")) {
            return;
        }

        var detalhe = document.querySelector(seletor + "[open]");

        if (detalhe) {
            fechar(detalhe);
        }
    }, true);

    // Um clique fora fecha a confirmação, mas sem tirar o foco ao que
    // foi clicado: quem clica num campo quer escrever nele.
    document.addEventListener("click", function (evento) {
        var detalhe = document.querySelector(seletor + "[open]");

        if (detalhe && !detalhe.contains(evento.target)) {
            detalhe.open = false;
        }
    });
})();
</script>

<script>
/* =====================================================================
   A pílula do estado leva ao painel que o muda.
   O endereço `#alterar` só desloca a página: o foco ficava no atalho, no
   cabeçalho, e de lá o Tab seguia o documento todo até chegar ao seletor do
   novo estado. Aqui o atalho pousa o foco nesse seletor, que é o passo
   seguinte de quem o ativou — com o teclado ou com o rato.
   ===================================================================== */
(function () {
    "use strict";

    var atalho  = document.querySelector(".cabecalho__titulo-ficha a.estado");
    var seletor = document.getElementById("estado-acao");

    /* Sem pedido, ou sem técnicos, o painel não existe: o atalho continua a
       ser só um salto para o `#alterar`. */
    if (!atalho || !seletor) {
        return;
    }

    atalho.addEventListener("click", function () {
        /* O salto e o `#alterar` no endereço ficam como estão — é o que leva
           a página até ao painel. O foco é posto logo a seguir: o
           `preventScroll` evita um segundo salto enquanto a página ainda
           está a rolar até ao painel. */
        window.setTimeout(function () {
            seletor.focus({ preventScroll: true });
        }, 0);
    });
})();
</script>
</body>
</html>
