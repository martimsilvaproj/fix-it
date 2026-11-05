<?php
declare(strict_types=1);

/**
 * metricas.php — janelas de meses, séries e contas do gráfico de receita.
 *
 * Tudo aqui é puro: não abre base de dados, não escreve, não lê o relógio
 * sem lhe ser dito qual é. É isso que permite testar a matemática do
 * gráfico em `testes/correr.php`, sem MySQL e sem depender do dia em que
 * os testes correm. As consultas ficam em `backoffice/painel.php`, que é
 * quem tem a ligação.
 *
 * Porque existe: o gráfico compara doze meses com os doze do ano
 * anterior. Essas duas janelas, o preenchimento dos meses sem registos e
 * a variação percentual são a parte que se pode enganar em silêncio —
 * um mês a menos, um deslocamento de um, uma divisão por zero. Ficam
 * aqui, com nome, em vez de espalhadas em três consultas SQL.
 */

/** Nomes curtos dos meses, para o eixo e para as dicas. */
const METRICAS_MESES_CURTOS = [
    'jan', 'fev', 'mar', 'abr', 'mai', 'jun',
    'jul', 'ago', 'set', 'out', 'nov', 'dez',
];

/** Nomes por extenso, para o texto que só os leitores de ecrã ouvem. */
const METRICAS_MESES_LONGOS = [
    'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
    'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
];

/**
 * Uma janela de meses, do mais antigo para o mais recente.
 *
 * @param int $quantos     Quantos meses a janela tem (1 a 60).
 * @param int $recuo       Meses a recuar a partir do mês de referência.
 * @param DateTimeImmutable|null $referencia Mês de referência; por omissão,
 *                                           o mês corrente.
 *
 * @return list<array{chave: string, curto: string, longo: string, ano: int, mes: int}>
 */
function metricasJanela(
    int $quantos,
    int $recuo = 0,
    ?DateTimeImmutable $referencia = null
): array {
    $quantos = max(1, min(60, $quantos));
    $recuo   = max(0, min(120, $recuo));

    // Sempre no dia 1: assim «-1 month» a partir de 31 de março não salta
    // para 3 de março (o PHP normaliza 31 de fevereiro para 3 de março) e
    // cai em fevereiro, que é o que qualquer pessoa espera.
    $fim = ($referencia ?? new DateTimeImmutable())
        ->modify('first day of this month')
        ->setTime(0, 0);

    if ($recuo > 0) {
        $fim = $fim->modify('-' . $recuo . ' months');
    }

    $inicio = $quantos > 1
        ? $fim->modify('-' . ($quantos - 1) . ' months')
        : $fim;

    $meses = [];

    for ($mes = $inicio; $mes <= $fim; $mes = $mes->modify('+1 month')) {
        $numero = (int) $mes->format('n');

        $meses[] = [
            'chave' => $mes->format('Y-m'),
            'curto' => METRICAS_MESES_CURTOS[$numero - 1] . ' ' . $mes->format('Y'),
            'longo' => METRICAS_MESES_LONGOS[$numero - 1] . ' de ' . $mes->format('Y'),
            'ano'   => (int) $mes->format('Y'),
            'mes'   => $numero,
        ];
    }

    return $meses;
}

/**
 * Alinha uma série com a janela: um valor por mês, pela ordem da janela.
 *
 * Meses sem registos valem zero — é a diferença entre «não houve receita»
 * e «não há mês nenhum ali». O mapa pode trazer números simples ou linhas
 * com colunas (o resultado de um GROUP BY); nesse caso lê-se `$campo`.
 *
 * @param list<array{chave: string}> $meses
 * @param array<string, mixed>       $porChave
 *
 * @return list<float>
 */
function metricasPreencher(array $meses, array $porChave, string $campo = 'valor'): array
{
    $serie = [];

    foreach ($meses as $mes) {
        $valor = $porChave[$mes['chave']] ?? 0;

        if (is_array($valor)) {
            $valor = $valor[$campo] ?? 0;
        }

        $serie[] = round((float) $valor, 2);
    }

    return $serie;
}

/**
 * Variação percentual entre dois valores, arredondada a uma casa.
 *
 * Devolve null quando não há comparação possível: sem mês anterior, ou
 * com o anterior a zero. «+100%» a partir de zero seria inventar um
 * crescimento que ninguém mediu.
 */
function metricasVariacao(?float $atual, ?float $anterior): ?float
{
    if ($atual === null || $anterior === null || $anterior <= 0.0) {
        return null;
    }

    return round((($atual - $anterior) / $anterior) * 100, 1);
}

/**
 * A variação mês a mês de uma série inteira.
 *
 * O primeiro elemento é sempre null: não há mês antes dele na janela.
 *
 * @param list<float> $valores
 *
 * @return list<float|null>
 */
function metricasVariacoes(array $valores): array
{
    $variacoes = [];
    $anterior  = null;

    foreach ($valores as $valor) {
        $variacoes[] = $anterior === null
            ? null
            : metricasVariacao((float) $valor, (float) $anterior);

        $anterior = $valor;
    }

    return $variacoes;
}

/** Euros à portuguesa: «1 234,50 €». Sem intl, que não está garantido. */
function metricasMoeda(float $valor, bool $comCentimos = true): string
{
    return number_format($valor, $comCentimos ? 2 : 0, ',', ' ') . ' €';
}

/** Contagem à portuguesa: «1 234». */
function metricasNumero(float $valor): string
{
    return number_format($valor, 0, ',', ' ');
}
