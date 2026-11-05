<?php
declare(strict_types=1);

/**
 * pin-consulta.php — PIN de consulta pública de um pedido.
 *
 * O PIN tem 6 algarismos (com zeros à esquerda) e é a única chave da
 * consulta pública em frontoffice/index.php. Partilhado por pedido-novo.php (novo
 * pedido) e pedido.php (gerar um PIN novo para um pedido existente), para
 * os dois caminhos usarem a mesma regra de unicidade.
 */

/**
 * Gera um PIN numérico de 6 dígitos (com zeros à esquerda) que ainda não
 * exista na tabela pedidos. O índice UNIQUE da coluna é a última defesa
 * contra uma colisão entre dois pedidos simultâneos.
 */
function gerarPinUnico(PDO $pdo): string
{
    $consulta = $pdo->prepare('SELECT 1 FROM pedidos WHERE pin_consulta = ? LIMIT 1');

    for ($tentativa = 0; $tentativa < 20; $tentativa++) {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $consulta->execute([$pin]);

        if ($consulta->fetchColumn() === false) {
            return $pin;
        }
    }

    throw new RuntimeException('Não foi possível gerar um PIN de consulta único.');
}

/**
 * Regista no histórico que um talão foi emitido: o PIN inicial do pedido
 * ('inicial') ou uma substituição ('renovacao'). Deve correr dentro da
 * transação que cria o pedido ou que troca pedidos.pin_consulta, para o
 * registo e o PIN nunca divergirem.
 *
 * Não engole erros: quem chama decide se a falta da tabela impede a ação
 * (pedido.php, onde o PIN não muda sem rasto) ou se apenas fica no log
 * (pedido-novo.php, onde um pedido não deixa de ser registado por causa
 * do histórico).
 */
function registarPinNoHistorico(PDO $pdo, int $idPedido, ?int $idTecnico, string $motivo): void
{
    $registar = $pdo->prepare(
        'INSERT INTO historico_pins (pedido_id, tecnico_id, motivo)
         VALUES (?, ?, ?)'
    );
    $registar->execute([
        $idPedido,
        $idTecnico !== null && $idTecnico > 0 ? $idTecnico : null,
        $motivo === 'inicial' ? 'inicial' : 'renovacao',
    ]);
}
