<?php
declare(strict_types=1);

/**
 * texto.php — contagem e corte de texto sem depender de mbstring.
 *
 * Porque existe este ficheiro: o projecto é PHP puro e nem todas as
 * instalações trazem a extensão `mbstring` (a máquina onde o FixIt foi
 * montado não trazia — e uma página que chamava `mb_strlen` sem guarda
 * deixou de desenhar). Duas páginas já se protegiam com
 * `function_exists()`; repetir essa guarda em nove sítios é pedir que um
 * deles fique de fora.
 *
 * Uso:
 *
 *     require_once __DIR__ . '/texto.php';
 *
 *     if (fixitTamanho($nome) > 120) { … }
 *     $nome = fixitCortar($nome, 120);
 *
 * Sem mbstring, a contagem é em bytes: um nome com acentos conta um pouco
 * mais do que as letras que tem. É o comportamento conservador certo — um
 * limite de coluna nunca é excedido por causa disso.
 */

/**
 * Comprimento de um texto, em caracteres quando há mbstring e em bytes
 * quando não há.
 */
function fixitTamanho(string $texto): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($texto, 'UTF-8')
        : strlen($texto);
}

/**
 * Corta um texto num limite, sem partir um caractere multibyte a meio.
 *
 * Serve para nomes de ficheiro e para valores que vão para colunas
 * VARCHAR: o resultado nunca é maior do que `$limite`.
 *
 * A unidade do limite é a mesma de fixitTamanho(): caracteres quando há
 * mbstring e bytes quando não há. Num servidor com a extensão (o caso
 * normal) um valor acentuado aproveita o limite todo; sem ela fica mais
 * curto, porque cada acento ocupa dois bytes. Em ambos os casos o
 * resultado é pelo menos tão curto como o limite na unidade certa, e é
 * sempre UTF-8 válido — é isso que interessa a quem chama.
 */
function fixitCortar(string $texto, int $limite): string
{
    if ($limite <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($texto, 0, $limite, 'UTF-8');
    }

    return fixitCortarBytes($texto, $limite);
}

/**
 * O caminho sem mbstring, exposto para poder ser testado em qualquer
 * máquina (com a extensão, fixitCortar() nunca o usa).
 *
 * Corta em bytes: um limite de 9 dá no máximo 9 bytes, o que com acentos
 * corresponde a menos letras. A alternativa seria devolver mais bytes do
 * que o pedido, e é precisamente isso que um limite de coluna não perdoa.
 */
function fixitCortarBytes(string $texto, int $limite): string
{
    if ($limite <= 0) {
        return '';
    }

    // Cortar em bytes pode deixar um caractere a meio. O resultado tem de
    // sair sempre UTF-8 válido — senão um nome de ficheiro ou um valor de
    // coluna ficava corrompido no fim.
    $cortado = substr($texto, 0, $limite);

    if (preg_match('//u', $cortado) !== 1) {
        // Primeiro as continuações pendentes, depois o byte de cabeça que
        // as abria (cortar a 9 bytes de «Reparação» deixava `Reparaç\xC3`).
        $cortado = preg_replace('/[\x80-\xBF]+$/', '', $cortado) ?? $cortado;

        if (preg_match('//u', $cortado) !== 1) {
            $cortado = preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $cortado) ?? $cortado;
        }
    }

    return $cortado;
}
