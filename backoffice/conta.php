<?php
declare(strict_types=1);

/**
 * conta.php — a conta de quem está autenticado.
 *
 * Qualquer utilizador com sessão (técnico ou administrador) pode entrar
 * aqui — a ligação está no perfil, no canto superior direito de todas as
 * páginas do painel — e mudar a própria palavra-passe.
 *
 * Segurança:
 *   • a mudança exige a palavra-passe ATUAL, confirmada com
 *     password_verify(): uma sessão deixada aberta noutro computador não
 *     chega para tomar conta da conta;
 *   • a nova palavra-passe tem de ter pelo menos 8 caracteres, ser
 *     repetida e ser diferente da atual;
 *   • POST com o token CSRF da conta (as duas ações da página partilham-no);
 *     limite de tentativas só na mudança de palavra-passe;
 *   • o ID de sessão é regenerado depois da mudança, e a confirmação
 *     segue num redireccionamento (POST → Redirect → GET);
 *   • nunca se imprime nem se devolve ao HTML nenhuma palavra-passe.
 *
 * Sessões: a sessão guarda uma impressão do hash da palavra-passe e
 * Sessao::exigir() compara-a com a base de dados em cada pedido. Ao mudar
 * a palavra-passe o hash muda, por isso as sessões abertas noutros
 * dispositivos caem no pedido seguinte — só esta continua, porque recebe
 * a impressão nova logo depois da gravação. O mesmo acontece quando um
 * administrador repõe a palavra-passe a alguém em Utilizadores.
 *
 * O cartão "Sessões abertas" faz o mesmo sem tocar na palavra-passe:
 * grava um valor novo em utilizadores.sessao_versao e todas as sessões
 * com o valor antigo caem (ver sql/migracao-sessoes.sql).
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/../nucleo/sessao.php';
require_once __DIR__ . '/../nucleo/layout-painel.php';
require_once __DIR__ . '/../nucleo/solicitacoes.php';

Sessao::iniciar();

/** @var array{id: int, nome: string, email: string, cargo: string} $utilizador */
$utilizador = Sessao::exigir();

// Contador do menu: quantos pedidos do site estão por contactar.
$solicitacoesNovas = solicitacoesNovas();

header('Cache-Control: private, no-store');

const MIN_PALAVRA       = 8;
const MAX_PALAVRA       = 200;
const MAX_TENTATIVAS    = 5;
const JANELA_TENTATIVAS = 600;

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

/** Comprimento em caracteres (mbstring quando existe), não em bytes. */
function comprimento(string $texto): int
{
    return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : strlen($texto);
}

/** Campo do POST, sem espaços nas pontas. Nunca é impresso de volta. */
function textoPost(string $campo): string
{
    $valor = $_POST[$campo] ?? '';

    return is_string($valor) ? trim($valor) : '';
}

/** Classe de erro do campo. */
function classeCampo(array $erros, string $campo): string
{
    return isset($erros[$campo]) ? ' campo--invalido' : '';
}

/** Parágrafo de erro de um campo (sempre presente, oculto sem erro). */
function erroCampo(array $erros, string $campo, string $id): string
{
    if (isset($erros[$campo])) {
        return '<p class="campo__erro" id="' . h($id) . '">' . h($erros[$campo]) . '</p>';
    }

    return '<p class="campo__erro" id="' . h($id) . '" hidden></p>';
}

/** Valida a nova palavra-passe e a repetição. */
function validarPalavra(string $palavra, string $confirmar): array
{
    $erros = [];

    if ($palavra === '') {
        $erros['palavra_passe'] = 'Define a nova palavra-passe.';
    } elseif (comprimento($palavra) < MIN_PALAVRA) {
        $erros['palavra_passe'] = 'A nova palavra-passe precisa de pelo menos ' . MIN_PALAVRA . ' caracteres.';
    } elseif (comprimento($palavra) > MAX_PALAVRA) {
        $erros['palavra_passe'] = 'A palavra-passe não pode passar de ' . MAX_PALAVRA . ' caracteres.';
    } elseif ($confirmar !== $palavra) {
        $erros['confirmar'] = 'As duas palavras-passe não coincidem.';
    }

    if ($palavra !== '' && $confirmar === '') {
        $erros['confirmar'] = 'Repete a nova palavra-passe.';
    }

    return $erros;
}

$erros   = [];
$alerta  = '';
$alertaSessoes = '';
$flash   = $_SESSION['conta_flash'] ?? '';
$conta   = null;
$erroDados = '';

unset($_SESSION['conta_flash']);

$tentativas = $_SESSION['conta_tentativas'] ?? ['n' => 0, 'desde' => time()];

if ((time() - (int) $tentativas['desde']) > JANELA_TENTATIVAS) {
    $tentativas = ['n' => 0, 'desde' => time()];
}

try {
    $pdo = obterConexao();

    try {
        $consulta = $pdo->prepare(
            'SELECT id, nome, email, cargo, criado_em, palavra_passe, sessao_versao
               FROM utilizadores
              WHERE id = ?
              LIMIT 1'
        );
        $consulta->execute([(int) $utilizador['id']]);
    } catch (PDOException $excecao) {
        // Base de dados sem a migração sql/migracao-sessoes.sql: a página
        // abre na mesma, sem a contagem de sessões.
        if ($excecao->getCode() !== '42S22') {
            throw $excecao;
        }

        $consulta = $pdo->prepare(
            'SELECT id, nome, email, cargo, criado_em, palavra_passe
               FROM utilizadores
              WHERE id = ?
              LIMIT 1'
        );
        $consulta->execute([(int) $utilizador['id']]);
    }

    $conta = $consulta->fetch();

    if (!is_array($conta)) {
        // A conta desapareceu entretanto (removida na base de dados).
        Sessao::sair();
        header('Location: login.php?motivo=entrada', true, 303);
        exit;
    }

    // Quantas outras sessões desta conta estão abertas agora. null quando
    // o servidor não deixa ler a pasta de sessões.
    $outrasSessoes = Sessao::sessoesAbertas(
        (int) $conta['id'],
        (string) ($conta['sessao_versao'] ?? ''),
        true
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token    = is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null;
        $acaoPost = is_string($_POST['acao'] ?? null) ? $_POST['acao'] : '';

        if ($acaoPost === 'terminar_sessoes') {
            // Fechar as outras sessões não gasta tentativas: não há
            // palavra-passe para adivinhar aqui.
            if (!Sessao::csrfValido($token, 'csrf_conta')) {
                $alertaSessoes = 'O formulário expirou. Volta a tentar.';
            } else {
                $novaVersao = Sessao::novaVersao();

                try {
                    $pdo->prepare(
                        'UPDATE utilizadores SET sessao_versao = ? WHERE id = ?'
                    )->execute([$novaVersao, (int) $conta['id']]);

                    Sessao::registarVersao($novaVersao);

                    $_SESSION['conta_flash'] = 'As outras sessões foram terminadas. Esta continua aberta.';

                    header('Location: conta.php', true, 303);
                    exit;
                } catch (PDOException $excecao) {
                    if ($excecao->getCode() !== '42S22') {
                        throw $excecao;
                    }

                    $alertaSessoes = 'Falta aplicar a migração sql/migracao-sessoes.sql a esta base de dados; sem ela não é possível terminar as sessões.';
                }
            }
        } elseif (!Sessao::csrfValido($token, 'csrf_conta')) {
            $alerta = 'O formulário expirou. Volta a preencher e tenta outra vez.';
        } elseif ($tentativas['n'] >= MAX_TENTATIVAS) {
            $alerta = 'Demasiadas tentativas falhadas. Espera alguns minutos antes de tentar outra vez.';
        } else {
            $atual    = textoPost('palavra_atual');
            $nova     = textoPost('palavra_passe');
            $confirmar = textoPost('confirmar');

            if ($atual === '') {
                $erros['palavra_atual'] = 'Indica a palavra-passe atual.';
            } elseif (!password_verify($atual, (string) $conta['palavra_passe'])) {
                $erros['palavra_atual'] = 'A palavra-passe atual não confere.';

                $tentativas['n'] = (int) $tentativas['n'] + 1;
                $_SESSION['conta_tentativas'] = $tentativas;

                usleep(250000);
            }

            $erros += validarPalavra($nova, $confirmar);

            if (!isset($erros['palavra_passe']) && $nova !== '' && password_verify($nova, (string) $conta['palavra_passe'])) {
                $erros['palavra_passe'] = 'A nova palavra-passe tem de ser diferente da atual.';
            }

            if ($erros === []) {
                $novoHash   = password_hash($nova, PASSWORD_DEFAULT);
                $novaVersao = Sessao::novaVersao();

                // Palavra-passe nova e versão nova na mesma alteração: as
                // outras sessões caem pela impressão do hash e, quando a
                // coluna existe, também pela versão.
                try {
                    $pdo->prepare(
                        'UPDATE utilizadores SET palavra_passe = ?, sessao_versao = ? WHERE id = ?'
                    )->execute([$novoHash, $novaVersao, (int) $conta['id']]);

                    $versaoGravada = true;
                } catch (PDOException $excecao) {
                    if ($excecao->getCode() !== '42S22') {
                        throw $excecao;
                    }

                    $pdo->prepare(
                        'UPDATE utilizadores SET palavra_passe = ? WHERE id = ?'
                    )->execute([$novoHash, (int) $conta['id']]);

                    $versaoGravada = false;
                }

                // Credenciais mudaram: ID de sessão novo, por precaução.
                session_regenerate_id(true);

                // Esta sessão passa a valer o hash novo; todas as outras
                // desta conta caem no pedido seguinte.
                Sessao::registarCredencial($novoHash);

                if ($versaoGravada) {
                    Sessao::registarVersao($novaVersao);
                }

                unset($_SESSION['conta_tentativas']);
                $_SESSION['conta_flash'] = 'Palavra-passe alterada. As sessões abertas noutros dispositivos foram terminadas.';

                header('Location: conta.php', true, 303);
                exit;
            }
        }
    }
} catch (Throwable $excecao) {
    error_log('[FixIt][Conta] ' . $excecao->getMessage());
    $erroDados = 'A base de dados não respondeu. Tenta novamente dentro de momentos.';
}
?>
<?php painelAbrir(
    'conta',
    'A minha conta',
    'A tua conta na área da oficina FixIt.',
    [
        ['Painel', 'painel.php'],
        ['A minha conta', null],
    ],
    $erroDados,
    true,
    $pedidosAtivos ?? -1
); ?>

        <main class="principal" id="conteudo" tabindex="-1">

            <div class="cabecalho">
                <div class="cabecalho__texto">
                    <h1>A minha conta</h1>
                    <p class="cabecalho__descricao">
                        <?php if ($conta !== null): ?>
                            <?= h($conta['email']) ?> · <?= h($conta['cargo']) ?> ·
                            conta criada a <?= h(dataCurta($conta['criado_em'])) ?>.
                        <?php else: ?>
                            A tua conta na oficina.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($flash !== ''): ?>
                <div class="alerta alerta--sucesso" role="status">
                    <svg aria-hidden="true"><use href="#icone-visto"/></svg>
                    <p class="alerta__texto"><?= h($flash) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($erroDados !== ''): ?>
                <section class="painel" aria-labelledby="titulo-erro">
                    <div class="aviso aviso--erro">
                        <span class="aviso__icone" aria-hidden="true">
                            <svg><use href="#icone-info"/></svg>
                        </span>
                        <p class="aviso__titulo" id="titulo-erro">Não foi possível carregar a conta</p>
                        <p class="aviso__texto"><?= h($erroDados) ?></p>
                        <a class="botao botao--fantasma" href="conta.php">Tentar novamente</a>
                    </div>
                </section>
            <?php else: ?>

                <section class="painel" id="palavra-passe" aria-labelledby="titulo-palavra">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-palavra">Mudar a palavra-passe</h2>
                            <p class="painel__descricao">
                                Pede a palavra-passe atual e escolhe uma nova com pelo menos
                                <?= MIN_PALAVRA ?> caracteres.
                            </p>
                        </div>
                    </div>

                    <form class="acao-estado" method="post" action="conta.php" novalidate>
                        <input type="hidden" name="csrf" value="<?= h(Sessao::csrf('csrf_conta')) ?>">

                        <?php if ($alerta !== ''): ?>
                            <div class="alerta" role="alert">
                                <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                                <p class="alerta__texto"><?= h($alerta) ?></p>
                            </div>
                        <?php endif; ?>

                        <div class="grelha acao-estado__campos">
                            <div class="campo campo--largo<?= classeCampo($erros, 'palavra_atual') ?>">
                                <label class="campo__rotulo" for="palavra-atual">Palavra-passe atual</label>
                                <input class="entrada" type="password" id="palavra-atual"
                                       name="palavra_atual" autocomplete="current-password"
                                       maxlength="<?= MAX_PALAVRA ?>"
                                       aria-describedby="erro-palavra-atual"
                                       <?= isset($erros['palavra_atual']) ? 'aria-invalid="true"' : '' ?>
                                       required>
                                <?= erroCampo($erros, 'palavra_atual', 'erro-palavra-atual') ?>
                            </div>

                            <div class="campo<?= classeCampo($erros, 'palavra_passe') ?>">
                                <label class="campo__rotulo" for="palavra-nova">Nova palavra-passe</label>
                                <input class="entrada" type="password" id="palavra-nova"
                                       name="palavra_passe" autocomplete="new-password"
                                       maxlength="<?= MAX_PALAVRA ?>"
                                       aria-describedby="erro-palavra-nova ajuda-palavra-nova"
                                       <?= isset($erros['palavra_passe']) ? 'aria-invalid="true"' : '' ?>
                                       required>
                                <p class="campo__ajuda" id="ajuda-palavra-nova">
                                    Pelo menos <?= MIN_PALAVRA ?> caracteres, diferente da atual.
                                </p>
                                <?= erroCampo($erros, 'palavra_passe', 'erro-palavra-nova') ?>
                            </div>

                            <div class="campo<?= classeCampo($erros, 'confirmar') ?>">
                                <label class="campo__rotulo" for="palavra-confirmar">Repetir a nova palavra-passe</label>
                                <input class="entrada" type="password" id="palavra-confirmar"
                                       name="confirmar" autocomplete="new-password"
                                       maxlength="<?= MAX_PALAVRA ?>"
                                       aria-describedby="erro-palavra-confirmar"
                                       <?= isset($erros['confirmar']) ? 'aria-invalid="true"' : '' ?>
                                       required>
                                <?= erroCampo($erros, 'confirmar', 'erro-palavra-confirmar') ?>
                            </div>
                        </div>

                        <div class="acao-estado__rodape">
                            <p class="acao-estado__nota">
                                Ao guardar, as sessões abertas noutros dispositivos são
                                terminadas. Esta continua aberta.
                            </p>
                            <div class="acao-estado__botoes">
                                <a class="botao botao--fantasma" href="painel.php">Voltar ao painel</a>
                                <button class="botao botao--primario" type="submit">
                                    Mudar palavra-passe
                                </button>
                            </div>
                        </div>
                    </form>
                </section>

                <section class="painel" id="sessoes" aria-labelledby="titulo-sessoes">
                    <div class="painel__cabecalho">
                        <div>
                            <h2 id="titulo-sessoes">Sessões abertas</h2>
                            <p class="painel__descricao">
                                Cada computador onde entraste tem a sua própria sessão.
                                Se deixaste a conta aberta onde não devias, fecha as
                                outras por aqui.
                            </p>
                        </div>
                    </div>

                    <form class="acao-estado acao-estado--simples" method="post" action="conta.php">
                        <input type="hidden" name="csrf" value="<?= h(Sessao::csrf('csrf_conta')) ?>">
                        <input type="hidden" name="acao" value="terminar_sessoes">

                        <?php if ($alertaSessoes !== ''): ?>
                            <div class="alerta" role="alert">
                                <svg aria-hidden="true"><use href="#icone-alerta"/></svg>
                                <p class="alerta__texto"><?= h($alertaSessoes) ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($outrasSessoes === 0): ?>
                            <p class="sessoes__contagem">Só esta sessão está aberta.</p>
                        <?php else: ?>
                            <?php if ($outrasSessoes !== null): ?>
                                <p class="sessoes__contagem">
                                    <?php if ($outrasSessoes === 1): ?>
                                        Há mais <strong>1</strong> sessão aberta além desta.
                                    <?php else: ?>
                                        Há mais <strong><?= (int) $outrasSessoes ?></strong> sessões abertas além desta.
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>

                            <div class="acao-estado__rodape">
                                <p class="acao-estado__nota">
                                    Quem estiver nas outras tem de entrar outra vez, com
                                    a mesma palavra-passe. Esta sessão continua aberta.
                                </p>
                                <div class="acao-estado__botoes">
                                    <button class="botao botao--perigo-fantasma" type="submit">
                                        <svg aria-hidden="true"><use href="#icone-sair"/></svg>
                                        Terminar outras sessões
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
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
</body>
</html>
