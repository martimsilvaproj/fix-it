<?php
declare(strict_types=1);

/**
 * correr.php — testes do núcleo, sem framework e sem base de dados.
 *
 * Uso:
 *     php testes/correr.php
 *
 * Sai com código 0 se tudo passar e 1 se alguma verificação falhar, para
 * poder entrar numa verificação automática (`php testes/correr.php || echo
 * falhou`) sem mais nada.
 *
 * Só se testa o que é puro: contagem e corte de texto, leitura de
 * contactos e as constantes dos estados. As funções que falam com o MySQL
 * ficam de fora — não há base nesta máquina e um teste que precisa dela
 * não é um teste, é uma promessa.
 *
 * O núcleo é incluído tal como o site o inclui. `nucleo/solicitacoes.php`
 * carrega `conexao.php`, que só define uma função e o fuso horário: não
 * abre ligação nenhuma até alguém chamar obterConexao().
 */

require_once __DIR__ . '/../nucleo/texto.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';
require_once __DIR__ . '/../nucleo/metricas.php';

$total  = 0;
$falhas = 0;

/**
 * Compara e conta. A saída diz o que era esperado e o que saiu — um teste
 * que só diz «falhou» obriga a abrir o ficheiro outra vez.
 */
function verificar(string $rotulo, mixed $esperado, mixed $obtido): void
{
    global $total, $falhas;

    $total++;

    if ($esperado === $obtido) {
        echo "  ok    {$rotulo}\n";

        return;
    }

    $falhas++;

    echo "  FALHA {$rotulo}\n";
    echo '        esperado: ' . var_export($esperado, true) . "\n";
    echo '        obtido:   ' . var_export($obtido, true) . "\n";
}

/** Verificação de uma condição simples. */
function verificarQue(string $rotulo, bool $condicao, string $detalhe = ''): void
{
    global $total, $falhas;

    $total++;

    if ($condicao) {
        echo "  ok    {$rotulo}\n";

        return;
    }

    $falhas++;
    echo "  FALHA {$rotulo}" . ($detalhe !== '' ? " ({$detalhe})" : '') . "\n";
}

/** Um texto é UTF-8 válido? (não precisa de mbstring) */
function utf8Valido(string $texto): bool
{
    return preg_match('//u', $texto) === 1;
}

echo "texto.php\n";

verificar('texto vazio mede 0', 0, fixitTamanho(''));
verificar('texto ASCII mede o que tem', 3, fixitTamanho('abc'));

// Com acentos, sem mbstring conta bytes e com mbstring conta caracteres:
// em ambos os casos nunca menos do que as letras que se vêem.
verificarQue(
    'texto acentuado mede pelo menos 9',
    fixitTamanho('Reparação') >= 9,
    'mede ' . fixitTamanho('Reparação')
);

verificar('corte abaixo do limite devolve tudo', 'Reparação', fixitCortar('Reparação', 100));
verificar('corte a zero devolve vazio', '', fixitCortar('Reparação', 0));
verificar('corte negativo devolve vazio', '', fixitCortar('Reparação', -5));

// fixitCortarBytes() é o caminho usado quando não há mbstring. Testá-lo
// directamente cobre as duas implementações em qualquer máquina: antes, o
// teste do corte só passava porque a extensão faltava, e falhava à primeira
// vez que corresse num servidor com mbstring.
verificar('corte em bytes abaixo do limite devolve tudo', 'abc', fixitCortarBytes('abc', 9));
verificar('corte em bytes a zero devolve vazio', '', fixitCortarBytes('Reparação', 0));

$emBytes = fixitCortarBytes('Reparação', 9);
verificarQue('corte em bytes nunca passa o limite', strlen($emBytes) <= 9, 'ficou ' . strlen($emBytes));
verificarQue('corte em bytes é UTF-8 válido', utf8Valido($emBytes), bin2hex($emBytes));
verificarQue('corte em bytes preserva o início', str_starts_with($emBytes, 'Repar'), $emBytes);

// A fronteira que já produziu «Reparaç\xC3»: o corte não pode acabar num
// byte de continuação solto.
verificarQue('corte em bytes a 8 é UTF-8 válido', utf8Valido(fixitCortarBytes('Reparação', 8)), bin2hex(fixitCortarBytes('Reparação', 8)));

// fixitCortar() é o que as páginas chamam: o limite é em caracteres com
// mbstring e em bytes sem ela, mas nunca é excedido na unidade disponível.
$cortado = fixitCortar('Reparação', 9);
verificarQue('corte cabe no limite disponível', fixitTamanho($cortado) <= 9, 'ficou ' . fixitTamanho($cortado));
verificarQue('corte é UTF-8 válido', utf8Valido($cortado), bin2hex($cortado));
verificarQue('corte preserva o início', str_starts_with($cortado, 'Repar'), $cortado);

// Todos os limites de 1 a 20, nas duas implementações, para não ficar só o
// caso que se lembrou.
$todosValidos = true;

for ($limite = 1; $limite <= 20; $limite++) {
    if (!utf8Valido(fixitCortar('Reparação de telemóveis', $limite))
        || !utf8Valido(fixitCortarBytes('Reparação de telemóveis', $limite))) {
        $todosValidos = false;
        break;
    }
}

verificarQue('corte é UTF-8 válido em todos os limites de 1 a 20', $todosValidos);

echo "\nsolicitacoes.php — contactos\n";

verificar('email é reconhecido', ['tipo' => 'email', 'valor' => 'nome@exemplo.pt'], classificarContacto('nome@exemplo.pt'));
verificar('email inválido não é aceite', 'desconhecido', classificarContacto('nome@')[ 'tipo']);
verificar('telefone simples', ['tipo' => 'telefone', 'valor' => '912345678'], classificarContacto('912345678'));
verificar('telefone com espaços', ['tipo' => 'telefone', 'valor' => '912345678'], classificarContacto('912 345 678'));
verificar('telefone com indicativo', ['tipo' => 'telefone', 'valor' => '+351912345678'], classificarContacto('+351 912 345 678'));
verificar('telefone com 00351', ['tipo' => 'telefone', 'valor' => '+351912345678'], classificarContacto('00351 912345678'));
verificar('contacto em texto livre', 'desconhecido', classificarContacto('liguem depois das 18h')['tipo']);
verificar('contacto vazio', ['tipo' => 'desconhecido', 'valor' => ''], classificarContacto('   '));

verificar('telefone curto é recusado', null, normalizarTelefone('12345'));
verificar('telefone com letras é recusado', null, normalizarTelefone('91234abc8'));
verificar('telefone fixo é aceite', '213456789', normalizarTelefone('213 456 789'));

echo "\nsolicitacoes.php — estados\n";

verificar('quatro estados', 4, count(SOLICITACOES_ESTADOS));
verificarQue('«Convertida» existe', in_array('Convertida', SOLICITACOES_ESTADOS, true));
verificarQue(
    '«Convertida» não se marca à mão',
    !in_array('Convertida', SOLICITACOES_ESTADOS_MANUAIS, true)
);
verificarQue(
    'todos os estados manuais são estados válidos',
    array_diff(SOLICITACOES_ESTADOS_MANUAIS, SOLICITACOES_ESTADOS) === []
);

echo "\nmetricas.php — janelas e séries\n";

// A referência é fixa: um teste que depende do mês em que corre falha em
// janeiro por razões que não têm nada a ver com o código.
$janelaDoze = metricasJanela(12, 0, new DateTimeImmutable('2026-11-05'));

verificar('a janela de 12 meses tem 12 meses', 12, count($janelaDoze));
verificar('a janela acaba no mês de referência', '2026-11', $janelaDoze[11]['chave']);
verificar('a janela começa 11 meses antes', '2025-12', $janelaDoze[0]['chave']);
verificar('o rótulo curto é «mês ano»', 'nov 2026', $janelaDoze[11]['curto']);
verificar('o rótulo longo escreve o mês por extenso', 'novembro de 2026', $janelaDoze[11]['longo']);

// 31 de março recuado um mês tem de cair em fevereiro, e não em 3 de março.
$fevereiro = metricasJanela(1, 1, new DateTimeImmutable('2026-03-31'));
verificar('recuar de 31 de março cai em fevereiro', '2026-02', $fevereiro[0]['chave']);

$semDados = metricasPreencher($janelaDoze, ['2026-11' => ['receita' => 95.5]], 'receita');
verificar('mês sem registos vale zero', 0.0, $semDados[0]);
verificar('mês com registos devolve o valor', 95.5, $semDados[11]);
verificar('a série tem o mesmo tamanho da janela', 12, count($semDados));

verificar('de 100 para 120 é +20%', 20.0, metricasVariacao(120.0, 100.0));
verificar('de 100 para 80 é -20%', -20.0, metricasVariacao(80.0, 100.0));
verificar('sem mês anterior não há variação', null, metricasVariacao(10.0, null));
verificar('mês anterior a zero não inventa variação', null, metricasVariacao(10.0, 0.0));

$variacoes = metricasVariacoes([10.0, 15.0, 12.0]);
verificar('a primeira variação não existe', null, $variacoes[0]);
verificar('a segunda variação é +50%', 50.0, $variacoes[1]);
verificar('a terceira variação é -20%', -20.0, $variacoes[2]);

verificar('moeda com cêntimos', '1 234,50 €', metricasMoeda(1234.5));
verificar('moeda sem cêntimos', '1 235 €', metricasMoeda(1234.5, false));
verificar('número inteiro', '1 234', metricasNumero(1234.0));

echo "\n";

if ($falhas === 0) {
    echo "Tudo certo: {$total} verificações.\n";
    exit(0);
}

echo "{$falhas} de {$total} verificações falharam.\n";
exit(1);
