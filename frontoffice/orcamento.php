<?php
declare(strict_types=1);

/**
 * orcamento.php — pedido de orçamento a partir do site.
 *
 * Fluxo:
 *   1. GET  → formulário.
 *   2. POST → valida tudo, grava em `solicitacoes` e responde 303 para
 *             ?enviado=1 (PRG: um refresh não repete o pedido).
 *   3. GET ?enviado=1 → confirmação com o que acontece a seguir.
 *
 * Regras:
 *   • Um pedido de orçamento NÃO é uma ordem de serviço: nunca escreve em
 *     `pedidos`, não gera PIN. Um pedido só nasce no balcão.
 *   • CSRF de sessão, isca para robôs, limite por endereço de rede e
 *     validação no servidor de todos os campos.
 *   • Nenhum dado pessoal vai para o log nem para o URL.
 *   • Sem base de dados, a página diz que não conseguiu — nunca um
 *     «obrigado» falso.
 */

require_once __DIR__ . '/front-comum.php';

// Sessão própria do site, separada da área da oficina (fixit_painel).
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_name('fixit_site');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
    session_start();
}

const MAX_PEDIDOS   = 5;    // por janela
const JANELA        = 600;  // 10 minutos
const MAX_NOME      = 80;
const MAX_CONTACTO  = 120;
const MAX_MODELO    = 120;
const MIN_DESCRICAO = 10;
const MAX_DESCRICAO = 2000;

/** Token CSRF desta página. */
function tokenOrcamento(): string
{
    if (empty($_SESSION['csrf_orcamento'])) {
        $_SESSION['csrf_orcamento'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_orcamento'];
}

/** Endereço de rede do pedido, ou '' quando não é válido. */
function enderecoIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
}

/**
 * Ficheiro do limite de pedidos por endereço.
 * O nome é um resumo do IP: a pasta temporária não fica com a lista.
 */
function ficheiroLimite(string $ip): ?string
{
    $pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixit-orcamento';

    if (!is_dir($pasta) && !@mkdir($pasta, 0700, true) && !is_dir($pasta)) {
        return null;
    }

    return $pasta . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json';
}

/** @return array{n: int, inicio: int} */
function lerLimite(string $ip): array
{
    $agora = time();
    $vazio = ['n' => 0, 'inicio' => $agora];
    $ficheiro = $ip === '' ? null : ficheiroLimite($ip);

    if ($ficheiro === null) {
        return $vazio;
    }

    $bruto = @file_get_contents($ficheiro);
    $dados = is_string($bruto) ? json_decode($bruto, true) : null;

    if (!is_array($dados) || !isset($dados['n'], $dados['inicio'])) {
        return $vazio;
    }

    $inicio = (int) $dados['inicio'];

    return $agora - $inicio > JANELA ? $vazio : ['n' => (int) $dados['n'], 'inicio' => $inicio];
}

function gravarLimite(string $ip, int $total, int $inicio): void
{
    $ficheiro = $ip === '' ? null : ficheiroLimite($ip);

    if ($ficheiro !== null) {
        @file_put_contents($ficheiro, json_encode(['n' => $total, 'inicio' => $inicio]), LOCK_EX);
    }
}

/** Tipos de equipamento aceites, a partir da oferta confirmada. */
function tiposAceites(): array
{
    global $oficina;

    $tipos = [];

    foreach ($oficina['oferta'] as $linha) {
        $tipos[] = $linha['tipo'];
    }

    return $tipos;
}

$valores = [
    'nome'         => '',
    'contacto'     => '',
    'equipamento'  => '',
    'marca_modelo' => '',
    'descricao'    => '',
];

$erros    = [];
$erroGeral = '';
$enviado  = isset($_GET['enviado']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enviado = false;

    foreach ($valores as $campo => $ignorado) {
        $posto = $_POST[$campo] ?? '';
        $valores[$campo] = is_string($posto) ? trim($posto) : '';
    }

    $isca  = $_POST['empresa'] ?? '';
    $token = $_POST['csrf'] ?? '';

    $ip      = enderecoIp();
    $limite  = lerLimite($ip);
    $espera  = $limite['n'] >= MAX_PEDIDOS ? JANELA - (time() - $limite['inicio']) : 0;

    if ($espera > 0) {
        $minutos = max(1, (int) ceil($espera / 60));
        $erroGeral = 'Demasiados pedidos deste endereço. Tenta novamente daqui a '
            . $minutos . ' minuto' . ($minutos === 1 ? '' : 's') . ', ou traz o equipamento ao balcão.';
    } elseif (is_string($isca) && $isca !== '') {
        // Robô: não se grava nada e a resposta é a mesma de um erro de envio.
        $erroGeral = 'Não foi possível enviar o pedido. Tenta novamente.';
    } elseif (!is_string($token) || empty($_SESSION['csrf_orcamento'])
        || !hash_equals($_SESSION['csrf_orcamento'], $token)) {
        $erroGeral = 'A sessão expirou. Recarrega a página e envia outra vez.';
    } else {
        $tamanhoNome = strlen($valores['nome']);

        if ($tamanhoNome < 2 || $tamanhoNome > MAX_NOME) {
            $erros['nome'] = 'Escreve o teu nome (até ' . MAX_NOME . ' caracteres).';
        }

        $tamanhoContacto = strlen($valores['contacto']);

        if ($tamanhoContacto < 5 || $tamanhoContacto > MAX_CONTACTO) {
            $erros['contacto'] = 'Deixa um telefone ou email para te respondermos.';
        }

        if (!in_array($valores['equipamento'], tiposAceites(), true)) {
            $erros['equipamento'] = 'Escolhe o tipo de equipamento.';
        }

        if (strlen($valores['marca_modelo']) > MAX_MODELO) {
            $erros['marca_modelo'] = 'Marca e modelo até ' . MAX_MODELO . ' caracteres.';
        }

        $tamanhoDescricao = strlen($valores['descricao']);

        if ($tamanhoDescricao < MIN_DESCRICAO) {
            $erros['descricao'] = 'Descreve a avaria em pelo menos ' . MIN_DESCRICAO . ' caracteres.';
        } elseif ($tamanhoDescricao > MAX_DESCRICAO) {
            $erros['descricao'] = 'A descrição não pode passar ' . MAX_DESCRICAO . ' caracteres.';
        }

        // O contador subia aqui, antes de saber se o pedido era válido: quem
        // se enganasse cinco vezes no formulário ficava dez minutos sem poder
        // enviar — castigado por erros que não gravaram nada. Passou para
        // baixo, para o caminho em que o pedido fica mesmo registado.
        if ($erros === []) {
            try {
                $pdo = obterConexao();
                $inserir = $pdo->prepare(
                    'INSERT INTO solicitacoes (nome, contacto, equipamento, marca_modelo, descricao)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $inserir->execute([
                    $valores['nome'],
                    $valores['contacto'],
                    $valores['equipamento'],
                    $valores['marca_modelo'] !== '' ? $valores['marca_modelo'] : null,
                    $valores['descricao'],
                ]);

                // Só agora o pedido conta para o limite por endereço: o
                // contador mede pedidos que ficaram registados, não tentativas.
                gravarLimite($ip, $limite['n'] + 1, $limite['inicio']);

                // Referência curta para o cliente ter o que dizer ao
                // telefone. Deriva do ID gravado: não é um dado pessoal e
                // não precisa de coluna nem de sorteio.
                $_SESSION['orcamento_referencia'] = sprintf(
                    'ORC-%s-%04d',
                    date('Y'),
                    (int) $pdo->lastInsertId()
                );

                unset($_SESSION['csrf_orcamento']);

                header('Location: orcamento.php?enviado=1', true, 303);
                exit;
            } catch (Throwable $excecao) {
                error_log('[FixIt][Orcamento] ' . $excecao->getMessage());
                $erroGeral = 'Não foi possível registar o pedido agora. Tenta novamente '
                    . 'daqui a pouco ou traz o equipamento ao balcão.';
            }
        }
    }
}

pagina_inicio(
    'Pedir orçamento',
    'Descreve a avaria e deixa um contacto. O orçamento é comunicado antes de qualquer reparação.',
    '',
    ['formulario.css']
);
?>

<section class="bloco bloco--primeiro" aria-labelledby="titulo-orcamento">
    <h1 class="bloco__titulo" id="titulo-orcamento">Pedir orçamento</h1>
    <p class="bloco__lede">
        Diz-nos o que está avariado e como te contactamos. O orçamento é
        comunicado antes de mexermos no equipamento — e o pedido de serviço só
        é aberto quando o trouxeres ao balcão, com talão e PIN.
    </p>
</section>

<?php if ($enviado): ?>

    <div class="site-sucesso" role="status">
        <p class="site-sucesso__titulo">Pedido enviado.</p>

        <?php if (!empty($_SESSION['orcamento_referencia'])): ?>
            <p class="bloco__texto--junto">
                Referência
                <strong class="referencia"><?= h((string) $_SESSION['orcamento_referencia']) ?></strong>
                — guarda-a ou dizê-la ao telefone para encontrarem o pedido.
            </p>
        <?php endif; ?>

        <p class="bloco__texto--junto">
            Ficou registado com o contacto que deixaste. A oficina fala contigo
            por aí para perceber o que se passa com o equipamento.
        </p>
        <p class="bloco__texto--junto">
            Se preferires tratar disto hoje, traz o equipamento ao balcão: a
            receção é feita contigo e recebes o talão com o PIN.
        </p>
        <p class="bloco__texto--seguido">
            <a class="ligacao-texto" href="como-funciona.php">Ver o percurso do equipamento</a>
        </p>
    </div>

    <p class="bloco__lede bloco__lede--seguido">
        <a class="ligacao-texto" href="index.php">Voltar à entrada do site</a>
    </p>

<?php else: ?>

    <?php if ($erroGeral !== ''): ?>
        <div class="site-aviso" role="alert">
            <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
            <div>
                <p class="site-aviso__titulo">Não foi possível enviar</p>
                <p class="site-aviso__texto"><?= h($erroGeral) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($erros !== [] && $erroGeral === ''): ?>
        <div class="site-aviso" role="alert" tabindex="-1">
            <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
            <div>
                <p class="site-aviso__titulo">Falta corrigir isto</p>
                <ul class="site-aviso__lista">
                    <?php foreach ($erros as $mensagem): ?>
                        <li><?= h($mensagem) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form class="form-site" method="post" action="orcamento.php" novalidate>
        <input type="hidden" name="csrf" value="<?= h(tokenOrcamento()) ?>">

        <div class="form-site__isca" aria-hidden="true">
            <label for="empresa">Empresa</label>
            <input type="text" id="empresa" name="empresa" tabindex="-1" autocomplete="off">
        </div>

        <div class="form-site__grelha">
            <div class="campo<?= isset($erros['nome']) ? ' campo--invalido' : '' ?>">
                <label class="campo__rotulo" for="nome">Nome</label>
                <input class="entrada" type="text" id="nome" name="nome"
                       value="<?= h($valores['nome']) ?>"
                       autocomplete="name" maxlength="<?= MAX_NOME ?>"
                       aria-describedby="erro-nome"
                       <?= isset($erros['nome']) ? 'aria-invalid="true"' : '' ?> required>
                <p class="campo__erro" id="erro-nome"
                   <?= isset($erros['nome']) ? '' : 'hidden' ?>><?= h($erros['nome'] ?? '') ?></p>
            </div>

            <div class="campo<?= isset($erros['contacto']) ? ' campo--invalido' : '' ?>">
                <label class="campo__rotulo" for="contacto">Telefone ou email</label>
                <input class="entrada" type="text" id="contacto" name="contacto"
                       value="<?= h($valores['contacto']) ?>"
                       autocomplete="tel" maxlength="<?= MAX_CONTACTO ?>"
                       aria-describedby="ajuda-contacto erro-contacto"
                       <?= isset($erros['contacto']) ? 'aria-invalid="true"' : '' ?> required>
                <p class="campo__ajuda" id="ajuda-contacto">Como queres que a oficina te responda.</p>
                <p class="campo__erro" id="erro-contacto"
                   <?= isset($erros['contacto']) ? '' : 'hidden' ?>><?= h($erros['contacto'] ?? '') ?></p>
            </div>

            <div class="campo<?= isset($erros['equipamento']) ? ' campo--invalido' : '' ?>">
                <label class="campo__rotulo" for="equipamento">Equipamento</label>
                <select class="entrada" id="equipamento" name="equipamento"
                        aria-describedby="erro-equipamento"
                        <?= isset($erros['equipamento']) ? 'aria-invalid="true"' : '' ?> required>
                    <option value="">Escolher…</option>
                    <?php foreach (tiposAceites() as $tipo): ?>
                        <option value="<?= h($tipo) ?>"
                            <?= $valores['equipamento'] === $tipo ? 'selected' : '' ?>><?= h($tipo) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="campo__erro" id="erro-equipamento"
                   <?= isset($erros['equipamento']) ? '' : 'hidden' ?>><?= h($erros['equipamento'] ?? '') ?></p>
            </div>

            <div class="campo<?= isset($erros['marca_modelo']) ? ' campo--invalido' : '' ?>">
                <label class="campo__rotulo" for="marca_modelo">Marca e modelo <span class="campo__opcional">(opcional)</span></label>
                <input class="entrada" type="text" id="marca_modelo" name="marca_modelo"
                       value="<?= h($valores['marca_modelo']) ?>"
                       maxlength="<?= MAX_MODELO ?>"
                       aria-describedby="erro-marca"
                       <?= isset($erros['marca_modelo']) ? 'aria-invalid="true"' : '' ?>>
                <p class="campo__erro" id="erro-marca"
                   <?= isset($erros['marca_modelo']) ? '' : 'hidden' ?>><?= h($erros['marca_modelo'] ?? '') ?></p>
            </div>

            <div class="campo form-site__campo--largo<?= isset($erros['descricao']) ? ' campo--invalido' : '' ?>">
                <label class="campo__rotulo" for="descricao">O que está avariado</label>
                <textarea class="entrada" id="descricao" name="descricao" rows="6"
                          maxlength="<?= MAX_DESCRICAO ?>"
                          aria-describedby="ajuda-descricao erro-descricao"
                          <?= isset($erros['descricao']) ? 'aria-invalid="true"' : '' ?> required><?= h($valores['descricao']) ?></textarea>
                <p class="campo__ajuda" id="ajuda-descricao">
                    O que falhou, desde quando e se já foi reparado antes.
                </p>
                <p class="campo__erro" id="erro-descricao"
                   <?= isset($erros['descricao']) ? '' : 'hidden' ?>><?= h($erros['descricao'] ?? '') ?></p>
            </div>
        </div>

        <div class="form-site__rodape">
            <button class="botao botao--primario botao--grande" type="submit">Enviar pedido</button>
            <p class="form-site__nota">
                Guardamos o nome, o contacto, o equipamento e a descrição para te
                respondermos. Ver <a class="ligacao-texto" href="privacidade.php">privacidade</a>.
            </p>
        </div>
    </form>

<?php endif; ?>

<?php
pagina_fim();
