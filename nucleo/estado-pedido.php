<?php
declare(strict_types=1);

/**
 * estado-pedido.php — Ação de mudança de estado, partilhada pelo painel
 * (painel.php) e pela página de pedidos (pedidos.php).
 *
 * Concentra o token CSRF, a validação, o UPDATE, o redirecionamento
 * (POST → Redirect → GET) e o formulário, para as duas páginas não
 * divergirem. A sessão tem de estar iniciada antes de usar a classe.
 *
 * O histórico é escrito pelo trigger AFTER UPDATE de `pedidos`; esta
 * classe só atualiza o pedido — nunca a tabela `historico_pedidos`.
 */

final class AcaoEstadoPedido
{
    /** Chave da confirmação guardada entre o POST e o GET seguinte. */
    private const CHAVE_FLASH = 'painel_sucesso';

    /** Data curta em português, no formato do painel ("22 out 2026"). */
    private static function dataCurta(?string $dataHora): string
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

    /** Escapa texto para HTML. */
    public static function h(?string $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Token CSRF da sessão, criado na primeira utilização. */
    public static function token(): string
    {
        if (empty($_SESSION['csrf_painel'])) {
            $_SESSION['csrf_painel'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_painel'];
    }

    /** Lê um ID numérico do POST; devolve 0 quando falta ou não é válido. */
    private static function inteiroPost(string $campo): int
    {
        $valor = $_POST[$campo] ?? null;

        return is_string($valor) && ctype_digit($valor) ? (int) $valor : 0;
    }

    /** Pedido em edição, com cliente e equipamento, ou null se não existir. */
    public static function carregarPedido(PDO $pdo, int $id): ?array
    {
        $consulta = $pdo->prepare(
            'SELECT p.id, p.estado, p.tecnico_id, p.data_entrada,
                    e.marca_modelo, c.nome AS cliente_nome
               FROM pedidos p
         INNER JOIN equipamentos e ON e.id = p.equipamento_id
         INNER JOIN clientes c     ON c.id = e.cliente_id
              WHERE p.id = ?
              LIMIT 1'
        );
        $consulta->execute([$id]);

        $linha = $consulta->fetch();

        return $linha ?: null;
    }

    /**
     * Guarda a confirmação para o GET seguinte. Pública porque a
     * atribuição de técnico na lista partilha o mesmo aviso.
     */
    public static function guardarFlash(string $mensagem): void
    {
        $_SESSION[self::CHAVE_FLASH] = $mensagem;
    }

    /** Confirmação pendente do POST anterior (uma só leitura). */
    public static function consumirFlash(): string
    {
        $mensagem = $_SESSION[self::CHAVE_FLASH] ?? '';
        unset($_SESSION[self::CHAVE_FLASH]);

        return is_string($mensagem) ? $mensagem : '';
    }

    /**
     * Abre o formulário (?alterar=ID) e processa a submissão.
     *
     * Em caso de sucesso grava, guarda a confirmação e redireciona com
     * 303 para $urlSucesso (que a página escolhe, para preservar a
     * paginação). Em caso de erro, devolve o estado para a página
     * desenhar o formulário outra vez.
     *
     * $idPorOmissao é o pedido a carregar num GET sem ?alterar=ID — a
     * página de detalhe, onde o formulário está sempre aberto. As listas
     * deixam-no a null e continuam a abrir o formulário só a pedido.
     *
     * @param array<string, string> $etiquetas
     *
     * @return array{
     *     pedido: ?array,
     *     erros: array<string, string>,
     *     estado: string,
     *     tecnico: int,
     *     tecnicos: array<int, array>,
     *     tecnicosPorId: array<int, string>
     * }
     */
    public static function preparar(
        PDO $pdo,
        array $etiquetas,
        string $urlSucesso,
        ?int $idPorOmissao = null
    ): array {
        $resultado = [
            'pedido'        => null,
            'erros'         => [],
            'estado'        => '',
            'tecnico'       => 0,
            'tecnicos'      => [],
            'tecnicosPorId' => [],
        ];

        $post = $_SERVER['REQUEST_METHOD'] === 'POST'
            && ($_POST['acao'] ?? '') === 'alterar_estado';

        $pedidoId = $post ? self::inteiroPost('pedido_id') : 0;

        if (!$post) {
            $parametro = $_GET['alterar'] ?? null;

            if (is_string($parametro) && ctype_digit($parametro)) {
                $pedidoId = (int) $parametro;
            } elseif ($idPorOmissao !== null && $idPorOmissao > 0) {
                $pedidoId = $idPorOmissao;
            }
        }

        if ($pedidoId <= 0) {
            return $resultado;
        }

        // Lista para o select e para validar o técnico escolhido.
        $consulta = $pdo->prepare('SELECT id, nome FROM utilizadores ORDER BY nome');
        $consulta->execute();

        foreach ($consulta->fetchAll() as $utilizador) {
            $resultado['tecnicos'][] = $utilizador;
            $resultado['tecnicosPorId'][(int) $utilizador['id']] = (string) $utilizador['nome'];
        }

        $pedido = self::carregarPedido($pdo, $pedidoId);
        $resultado['pedido'] = $pedido;

        if ($pedido === null) {
            $resultado['erros']['geral'] = 'O pedido #' . $pedidoId . ' já não existe.';

            return $resultado;
        }

        // Estado e técnico atuais preenchem o formulário no GET.
        $resultado['estado'] = (string) $pedido['estado'];
        $resultado['tecnico'] = (int) ($pedido['tecnico_id'] ?? 0);

        if (!$post) {
            return $resultado;
        }

        $estadoEnviado = $_POST['estado'] ?? null;
        $resultado['estado'] = is_string($estadoEnviado) ? $estadoEnviado : '';
        $resultado['tecnico'] = self::inteiroPost('tecnico_id');

        if (!isset($etiquetas[$resultado['estado']])) {
            $resultado['erros']['estado'] = 'Escolhe um estado da lista.';
        }

        if (!isset($resultado['tecnicosPorId'][$resultado['tecnico']])) {
            $resultado['erros']['tecnico_id'] = $resultado['tecnicos'] !== []
                ? 'Escolhe o técnico que fez a mudança.'
                : 'Ainda não há utilizadores registados na base de dados.';
        }

        $token = $_POST['csrf'] ?? '';

        if (!is_string($token)
            || empty($_SESSION['csrf_painel'])
            || !hash_equals($_SESSION['csrf_painel'], $token)
        ) {
            $resultado['erros']['geral'] = 'A sessão expirou. Recarrega a página e tenta novamente.';
        }

        if ($resultado['erros'] === []
            && $resultado['estado'] === (string) $pedido['estado']
            && $resultado['tecnico'] === (int) ($pedido['tecnico_id'] ?? 0)
        ) {
            $resultado['erros']['geral'] = 'O pedido já está neste estado, com este técnico.';
        }

        if ($resultado['erros'] !== []) {
            return $resultado;
        }

        // O trigger AFTER UPDATE escreve a linha do histórico com este
        // técnico na mesma instrução — as duas gravações são atómicas,
        // sem transação explícita no PHP.
        $atualizar = $pdo->prepare(
            'UPDATE pedidos
                SET estado = ?,
                    tecnico_id = ?,
                    data_conclusao = CASE
                        WHEN ? = \'Entregue\'
                        THEN COALESCE(data_conclusao, NOW())
                        ELSE NULL
                    END
              WHERE id = ?'
        );
        $atualizar->execute([
            $resultado['estado'],
            $resultado['tecnico'],
            $resultado['estado'],
            $pedidoId,
        ]);

        $mensagem = 'Pedido #' . $pedidoId . ' atualizado';

        if ($resultado['estado'] !== (string) $pedido['estado']) {
            $mensagem .= ' para ' . $etiquetas[$resultado['estado']];
        }

        $_SESSION[self::CHAVE_FLASH] = $mensagem
            . ', por ' . $resultado['tecnicosPorId'][$resultado['tecnico']] . '.';

        header('Location: ' . $urlSucesso, true, 303);
        exit;
    }

    /**
     * Formulário completo de mudança de estado. Não imprime nada quando
     * não há pedido em edição.
     *
     * $titulo vazio usa o título por omissão ("Atualizar pedido #N"); a
     * página de detalhe passa "Mudar o estado" para não repetir o <h1>.
     *
     * @param array<string, string> $etiquetas
     * @param array<string, string> $classes
     */
    public static function renderFormulario(
        array $acao,
        array $etiquetas,
        array $classes,
        string $actionUrl,
        string $cancelarUrl,
        string $titulo = ''
    ): void {
        $pedido = $acao['pedido'] ?? null;

        if (!is_array($pedido)) {
            return;
        }

        $titulo = $titulo !== '' ? $titulo : 'Atualizar pedido #' . (int) $pedido['id'];

        $erros    = is_array($acao['erros'] ?? null) ? $acao['erros'] : [];
        $estado   = (string) ($acao['estado'] ?? '');
        $tecnico  = (int) ($acao['tecnico'] ?? 0);
        $tecnicos = is_array($acao['tecnicos'] ?? null) ? $acao['tecnicos'] : [];
        ?>
        <section class="painel" id="alterar" aria-labelledby="titulo-alterar">
            <div class="painel__cabecalho">
                <div>
                    <h2 id="titulo-alterar"><?= self::h($titulo) ?></h2>
                    <p class="painel__descricao">
                        <?= self::h($pedido['cliente_nome']) ?> · <?= self::h($pedido['marca_modelo']) ?>
                        · entrou a <?= self::h(self::dataCurta($pedido['data_entrada'])) ?>
                    </p>
                </div>
                <p class="acao-estado__atual">
                    <span class="visualmente-oculto">Estado atual: </span>
                    <span class="estado <?= self::h($classes[$pedido['estado']] ?? 'estado--recebido') ?>"><?= self::h($etiquetas[$pedido['estado']] ?? $pedido['estado']) ?></span>
                </p>
            </div>

            <form class="acao-estado" method="post" action="<?= self::h($actionUrl) ?>" novalidate>
                <input type="hidden" name="csrf" value="<?= self::h(self::token()) ?>">
                <input type="hidden" name="acao" value="alterar_estado">
                <input type="hidden" name="pedido_id" value="<?= (int) $pedido['id'] ?>">

                <?php if (isset($erros['geral'])): ?>
                    <div class="alerta" role="alert">
                        <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                        <div>
                            <p class="alerta__titulo">Não foi possível guardar</p>
                            <p class="alerta__texto"><?= self::h($erros['geral']) ?></p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="grelha acao-estado__campos">
                    <div class="campo<?= isset($erros['estado']) ? ' campo--invalido' : '' ?>">
                        <label class="campo__rotulo" for="estado-acao">Novo estado</label>
                        <select class="entrada" id="estado-acao" name="estado"
                                aria-describedby="erro-estado-acao"
                                <?= isset($erros['estado']) ? 'aria-invalid="true"' : '' ?>>
                            <?php foreach ($etiquetas as $valorEstado => $rotuloEstado): ?>
                                <option value="<?= self::h($valorEstado) ?>"
                                    <?= $estado === $valorEstado ? 'selected' : '' ?>><?= self::h($rotuloEstado) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="campo__erro" id="erro-estado-acao"
                           <?= isset($erros['estado']) ? '' : 'hidden' ?>><?= self::h($erros['estado'] ?? '') ?></p>
                    </div>

                    <div class="campo<?= isset($erros['tecnico_id']) ? ' campo--invalido' : '' ?>">
                        <label class="campo__rotulo" for="tecnico-acao">Técnico que fez a mudança</label>
                        <select class="entrada" id="tecnico-acao" name="tecnico_id"
                                aria-describedby="erro-tecnico-acao"
                                <?= isset($erros['tecnico_id']) ? 'aria-invalid="true"' : '' ?>
                                <?= $tecnicos === [] ? 'disabled' : '' ?>>
                            <option value="">Escolher técnico…</option>
                            <?php foreach ($tecnicos as $linhaTecnico): ?>
                                <option value="<?= (int) $linhaTecnico['id'] ?>"
                                    <?= $tecnico === (int) $linhaTecnico['id'] ? 'selected' : '' ?>><?= self::h($linhaTecnico['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="campo__erro" id="erro-tecnico-acao"
                           <?= isset($erros['tecnico_id']) ? '' : 'hidden' ?>><?= self::h($erros['tecnico_id'] ?? '') ?></p>
                    </div>
                </div>

                <?php if ($tecnicos === []): ?>
                    <p class="acao-estado__vazio">
                        Ainda não há utilizadores registados. Sem um técnico não é
                        possível registar a mudança.
                    </p>
                <?php endif; ?>

                <div class="acao-estado__rodape">
                    <p class="acao-estado__nota">
                        O técnico fica associado ao pedido; cada mudança de estado
                        é registada no histórico, com a data.
                    </p>
                    <div class="acao-estado__botoes">
                        <a class="botao botao--fantasma" href="<?= self::h($cancelarUrl) ?>">Cancelar</a>
                        <button class="botao botao--primario" type="submit"
                                <?= $tecnicos === [] ? 'disabled' : '' ?>>Guardar estado</button>
                    </div>
                </div>
            </form>
        </section>
        <?php /* Quem chega ao painel pelo «Mudar» da lista — ou do painel —
                 traz `#alterar` no endereço: o browser desloca a página
                 até aqui, mas o foco fica no topo do documento e o Tab
                 seguinte volta a percorrer a página toda. O foco pousa no
                 seletor do novo estado, que é o passo seguinte de quem
                 pediu para mudar o estado — o mesmo destino que a pílula
                 da ficha dá ao ser ativada. Vive aqui, e não em cada
                 página, porque o painel é um só: ficha, lista e painel
                 chegam a este `#alterar`. */ ?>
        <script>
        (function () {
            "use strict";

            var seletor = document.getElementById("estado-acao");

            if (!seletor || window.location.hash !== "#alterar") {
                return;
            }

            /* No fim do carregamento, e não a meio da leitura do documento:
               o salto para o painel acontece depois, e um foco posto antes
               dele não sobrevive ao arranque da página. O `preventScroll`
               evita um segundo salto — a este ponto a página já está no
               painel. */
            window.addEventListener("load", function () {
                seletor.focus({ preventScroll: true });
            });
        })();
        </script>
        <?php
    }
}
