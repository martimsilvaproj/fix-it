<?php
declare(strict_types=1);

/**
 * layout-painel.php — a moldura única das páginas da área da oficina.
 *
 * Porque existe: as sete páginas do painel repetiam, cada uma, o mesmo
 * `<head>`, o mesmo sprite de ícones (≈95 linhas), a mesma barra lateral
 * (≈80 linhas) e a mesma barra de topo. Acrescentar uma entrada de menu
 * custava sete edições, e bastava uma ficar para trás para a navegação
 * divergir — foi o que aconteceu com «Definições», que durante uma fase
 * apontou para um ficheiro inexistente em todas as páginas.
 *
 * Uso numa página:
 *
 *     require_once __DIR__ . '/../nucleo/layout-painel.php';
 *     painelAbrir('clientes', $tituloRecorte, 'Descrição…', [
 *         ['Painel', 'painel.php'],
 *         ['Clientes', null],
 *     ], $erroDados, false, $pedidosAtivos);
 *
 *     <main class="principal" id="conteudo" tabindex="-1">
 *         … conteúdo da página …
 *     </main>
 *
 * O que a função NÃO faz: fechar o `<main>`, o `</div>` do conteúdo nem o
 * `</body>` — disso continua a tratar cada página, porque umas fecham com
 * `<script>` e outras não.
 *
 * Parâmetros:
 *   $ativo        — item a marcar com aria-current: painel, pedidos,
 *                   solicitacoes, clientes, equipamentos, utilizadores, conta.
 *   $titulo       — título da janela, sem o « — FixIt» (é acrescentado).
 *   $descricao    — meta description.
 *   $migalhas     — [[rótulo, href|null], …]; o último é a página actual.
 *   $erroDados    — mensagem de erro de base, para o rodapé da barra lateral.
 *   $noindex      — true nas páginas que não devem ser indexadas.
 *   $pedidosAtivos— contador de pedidos em aberto; menos de 0 manda contar.
 */

/** Escapa texto para HTML (as páginas têm o seu `h()`, este é autónomo). */
function painelEsc(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Pedidos por entregar. Usa a mesma regra do painel: tudo o que não está
 * «Entregue» conta como activo. Falha em silêncio (devolve 0) para a
 * moldura nunca impedir a página de ser desenhada.
 */
function painelPedidosAtivos(): int
{
    try {
        $pdo = obterConexao();

        return (int) $pdo->query("SELECT COUNT(*) FROM pedidos WHERE estado <> 'Entregue'")
            ->fetchColumn();
    } catch (Throwable $excecao) {
        error_log('[FixIt][Moldura][Pedidos ativos] ' . $excecao->getMessage());

        return 0;
    }
}

function painelAbrir(
    string $ativo,
    string $titulo,
    string $descricao,
    array $migalhas,
    string $erroDados = '',
    bool $noindex = false,
    int $pedidosAtivos = -1
): void {
    $utilizador = Sessao::utilizador();
    $nome       = is_array($utilizador) ? (string) ($utilizador['nome'] ?? '') : '';
    $cargo      = is_array($utilizador) ? (string) ($utilizador['cargo'] ?? '') : '';

    if ($pedidosAtivos < 0) {
        $pedidosAtivos = painelPedidosAtivos();
    }

    $novas = function_exists('solicitacoesNovas') ? solicitacoesNovas() : 0;

    // Itens da navegação: [chave, href, rótulo, ícone, contagem].
    // «Pedidos do site» usa um SVG em linha próprio (o ícone da marquesa).
    $itens = [
        ['painel', 'painel.php', 'Visão geral', '<svg aria-hidden="true"><use href="#icone-visao-geral"/></svg>', 0],
        ['pedidos', 'pedidos.php', 'Pedidos', '<svg aria-hidden="true"><use href="#icone-pedidos"/></svg>', $pedidosAtivos],
        ['solicitacoes', 'solicitacoes.php', 'Pedidos do site', '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 13h4l2 3h6l2-3h4"/><path d="M4.5 5.5h15l1.5 7.5v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4l1.5-7.5Z"/></svg>', $novas],
        ['clientes', 'clientes.php', 'Clientes', '<svg aria-hidden="true"><use href="#icone-clientes"/></svg>', 0],
        ['equipamentos', 'equipamentos.php', 'Equipamentos', '<svg aria-hidden="true"><use href="#icone-equipamentos"/></svg>', 0],
    ];
    ?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= painelEsc($titulo) ?> — FixIt</title>
    <meta name="description" content="<?= painelEsc($descricao) ?>">
    <?php if ($noindex): ?>
    <meta name="robots" content="noindex">
    <?php endif; ?>
    <meta name="theme-color" content="#0C2038">

    <!-- A luz da oficina: «papel» (claro) ou «tinta» (escuro). O atributo
         tem de existir antes do primeiro desenho, senão a página aparece
         clara e só depois escurece. O botão que o troca vive na barra de
         topo; a memória é deste browser (assets/js/tema.js). -->
    <script>
        try {
            var temaGuardado = localStorage.getItem('fixit-tema');
            if (temaGuardado === 'tinta' || temaGuardado === 'papel') {
                document.documentElement.setAttribute('data-tema', temaGuardado);
            }
        } catch (e) {}
    </script>

    <!-- Tipografia do sistema (sem pedidos externos). Para usar uma fonte
         própria, define @font-face e troca a variável --fonte no CSS. -->
    <!-- A fonte da interface vem de casa: um .woff2 variável em
         assets/fonts/, pré-carregado para o texto não trocar a meio. -->
    <link rel="preload" href="../assets/fonts/plus-jakarta-sans-latin.woff2" as="font"
          type="font/woff2" crossorigin>

    <link rel="stylesheet" href="../assets/css/painel.css">
    <link rel="stylesheet" href="../assets/css/formulario.css">
    <!-- A camada de cima: a pele de produto do interior do painel. -->
    <link rel="stylesheet" href="../assets/css/painel-saas.css">
</head>
<body>

<a class="saltar-conteudo" href="#conteudo">Saltar para o conteúdo</a>

<!-- =================================================================
     BIBLIOTECA DE ÍCONES
     Um único sprite SVG: traço 1.7, 24x24, sem dependências externas.
     Uso: <svg aria-hidden="true"><use href="#icone-pedidos"/></svg>
     ================================================================= -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <g id="icone-visao-geral" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3.5" y="3.5" width="7" height="7" rx="1.8"/>
            <rect x="13.5" y="3.5" width="7" height="7" rx="1.8"/>
            <rect x="3.5" y="13.5" width="7" height="7" rx="1.8"/>
            <rect x="13.5" y="13.5" width="7" height="7" rx="1.8"/>
        </g>
        <g id="icone-pedidos" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4.5" y="4.5" width="15" height="15" rx="2.5"/>
            <path d="M8.5 9h7M8.5 12.5h7M8.5 16h4.5"/>
        </g>
        <g id="icone-clientes" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9" cy="8.5" r="3.25"/>
            <path d="M3.5 19.75c0-3.1 2.5-5.25 5.5-5.25s5.5 2.15 5.5 5.25"/>
            <path d="M16.1 5.6a3.2 3.2 0 0 1 0 6.3"/>
            <path d="M17.2 14.9c2.1.55 3.3 2.2 3.3 4.5"/>
        </g>
        <g id="icone-equipamentos" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3.5" y="5.25" width="17" height="11" rx="1.9"/>
            <path d="M2 19.5h20"/>
        </g>
        <g id="icone-utilizadores" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 3.5 5 6v5.6c0 4 3 7 7 8.4 4-1.4 7-4.4 7-8.4V6l-7-2.5Z"/>
            <path d="m9.1 11.6 2 2 3.9-3.9"/>
        </g>
        <g id="icone-sair" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M14.5 5.5H7.6A1.6 1.6 0 0 0 6 7.1v9.8a1.6 1.6 0 0 0 1.6 1.6h6.9"/>
            <path d="M17.75 12h-7.5"/>
            <path d="m15.25 8.75 3.25 3.25-3.25 3.25"/>
        </g>
        <g id="icone-menu" fill="none" stroke="currentColor" stroke-linecap="round">
            <path d="M4 7h16M4 12h16M4 17h11"/>
        </g>
        <g id="icone-fechar" fill="none" stroke="currentColor" stroke-linecap="round">
            <path d="m6 6 12 12M18 6 6 18"/>
        </g>
        <g id="icone-mais" fill="none" stroke="currentColor" stroke-linecap="round">
            <path d="M12 5.25v13.5M5.25 12h13.5"/>
        </g>
        <g id="icone-seta-direita" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4.5 12h14M13 6.5l5.5 5.5L13 17.5"/>
        </g>
        <g id="icone-seta-esquerda" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5"/>
            <path d="m11 6-6 6 6 6"/>
        </g>
        <g id="icone-subida" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6.5 17.5 17.5 6.5"/>
            <path d="M9.5 6.5h8v8"/>
        </g>
        <g id="icone-relogio" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="8.25"/>
            <path d="M12 7.5V12l3 1.9"/>
        </g>
        <g id="icone-atribuir" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="9.5" cy="8.5" r="3.25"/>
            <path d="M4 19.75c0-3.1 2.5-5.25 5.5-5.25.9 0 1.7.2 2.4.55"/>
            <path d="M17.5 10.75v6M14.5 13.75h6"/>
        </g>
        <g id="icone-olho" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2.6 12S6.2 6.6 12 6.6 21.4 12 21.4 12 17.8 17.4 12 17.4 2.6 12 2.6 12Z"/>
            <circle cx="12" cy="12" r="2.7"/>
        </g>
        <g id="icone-olho-cortado" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2.6 12S6.2 6.6 12 6.6 21.4 12 21.4 12 17.8 17.4 12 17.4 2.6 12 2.6 12Z"/>
            <path d="M4.4 4.6 19.6 19.4"/>
        </g>
        <g id="icone-pesquisa" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="10.5" cy="10.5" r="6.5"/>
            <path d="m15.5 15.5 4.5 4.5"/>
        </g>
        <g id="icone-info" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="8.25"/>
            <path d="M12 11v5"/>
            <path d="M12 8.1h.01"/>
        </g>
        <g id="icone-editar" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3Z"/>
            <path d="m13.5 6.5 3 3"/>
        </g>
        <g id="icone-alerta" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 4.5 21 20H3L12 4.5Z"/>
            <path d="M12 10v4"/>
            <path d="M12 17h.01"/>
        </g>
        <g id="icone-visto" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="m5 12.5 4.5 4.5L19 7"/>
        </g>
    </defs>
</svg>

<div class="aplicacao">

    <!-- =============================================================
         BARRA LATERAL
         ============================================================= -->
    <aside class="sidebar" id="sidebar" aria-label="Navegação principal">
        <div class="sidebar__topo">
            <a class="marca" href="painel.php">
                <span class="marca__simbolo" aria-hidden="true">
                    <!-- Marca: cabeça de parafuso em cruz. -->
                    <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round">
                        <circle cx="12" cy="12" r="8.25"/>
                        <path d="M12 7.5v9M7.5 12h9"/>
                    </svg>
                </span>
                <span class="marca__texto">
                    <span class="marca__nome">FixIt</span>
                    <span class="marca__descricao">Assistência técnica</span>
                </span>
            </a>

            <button class="botao-icone sidebar__fechar" type="button" id="fechar-nav"
                    aria-label="Fechar menu">
                <svg aria-hidden="true"><use href="#icone-fechar"/></svg>
            </button>
        </div>

        <!-- A oficina identifica-se aqui. Não é um menu: há uma só
             unidade, e um botão a fingir de selector seria uma promessa
             que não se cumpre. -->
        <div class="organizacao">
            <span class="organizacao__ponto" aria-hidden="true"></span>
            <span class="organizacao__texto">
                <span class="organizacao__nome">FixIt</span>
                <span class="organizacao__nota">Oficina · todos os pedidos</span>
            </span>
        </div>

        <nav class="navegacao" aria-label="Secções do painel">
            <p class="navegacao__titulo" id="grupo-operacao">Operação</p>
            <ul class="navegacao__lista" aria-labelledby="grupo-operacao">
                <?php foreach ($itens as [$chave, $href, $rotulo, $icone, $contagem]): ?>
                    <li>
                        <a class="navegacao__ligacao" href="<?= painelEsc($href) ?>"<?= $chave === $ativo ? ' aria-current="page"' : '' ?>>
                            <?= $icone ?>
                            <span><?= painelEsc($rotulo) ?></span>
                            <?php if ($contagem > 0): ?>
                                <span class="navegacao__contagem" aria-hidden="true"><?= (int) $contagem ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <p class="navegacao__titulo" id="grupo-gestao">Gestão</p>
            <ul class="navegacao__lista" aria-labelledby="grupo-gestao">
                <?php if (Sessao::eAdministrador()): ?>
                    <li>
                        <a class="navegacao__ligacao" href="utilizadores.php"<?= $ativo === 'utilizadores' ? ' aria-current="page"' : '' ?>>
                            <svg aria-hidden="true"><use href="#icone-utilizadores"/></svg>
                            <span>Utilizadores</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>

        <div class="sidebar__rodape">
            <!-- Leva à consulta pública: o único ecrã que o cliente vê, e
                 a forma de confirmar o que ele está a ver neste momento. -->
            <a class="destaque" href="../frontoffice/consulta.php">
                <span class="destaque__icone" aria-hidden="true">
                    <svg><use href="#icone-visao-geral"/></svg>
                </span>
                <span class="destaque__titulo">Consulta por PIN</span>
                <span class="destaque__texto">Abre o ecrã do cliente para confirmar o que ele vê.</span>
            </a>

            <p class="estado-sistema<?= $erroDados !== '' ? ' estado-sistema--erro' : '' ?>">
                <?= $erroDados !== '' ? 'Sem ligação à base de dados' : 'Ligado à base de dados' ?>
            </p>
        </div>
    </aside>

    <div class="cortina" id="cortina" aria-hidden="true"></div>

    <!-- =============================================================
         CONTEÚDO
         ============================================================= -->
    <div class="conteudo">

        <header class="topbar">
            <div class="topbar__interior">
                <div class="topbar__esquerda">
                    <button class="botao-icone botao-menu" type="button" id="abrir-nav"
                            aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menu">
                        <svg aria-hidden="true"><use href="#icone-menu"/></svg>
                    </button>

                    <ol class="migalhas" aria-label="Localização">
                        <?php $ultima = count($migalhas) - 1; ?>
                        <?php foreach ($migalhas as $indice => $migalha): ?>
                            <?php [$rotulo, $ligacao] = [$migalha[0] ?? '', $migalha[1] ?? null]; ?>
                            <?php if ($indice === $ultima): ?>
                                <li aria-current="page"><?= painelEsc($rotulo) ?></li>
                            <?php else: ?>
                                <li><a href="<?= painelEsc((string) $ligacao) ?>"><?= painelEsc($rotulo) ?></a></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                </div>

                <div class="topbar__direita">
                    <button class="tema-botao" type="button" data-alternar-tema
                            aria-pressed="false" aria-label="Tema escuro (tinta)">
                        <svg class="tema-botao__sol" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="3.6"/>
                            <path d="M12 3v2.2M12 18.8V21M3 12h2.2M18.8 12H21M5.6 5.6l1.6 1.6M16.8 16.8l1.6 1.6M18.4 5.6l-1.6 1.6M7.2 16.8l-1.6 1.6"/>
                        </svg>
                        <svg class="tema-botao__lua" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20 14.2A8.2 8.2 0 0 1 9.8 4a8.4 8.4 0 1 0 10.2 10.2Z"/>
                        </svg>
                        <span class="tema-botao__texto">Tema</span>
                    </button>

                    <a class="perfil" href="conta.php"<?= $ativo === 'conta' ? ' aria-current="page"' : '' ?> aria-label="A minha conta: <?= painelEsc($nome) ?>">
                        <span class="perfil__avatar" aria-hidden="true"><?= painelEsc(Sessao::iniciais($nome)) ?></span>
                        <span class="perfil__texto">
                            <span class="perfil__nome"><?= painelEsc($nome) ?></span>
                            <span class="perfil__cargo"><?= painelEsc($cargo) ?></span>
                        </span>
                    </a>

                    <!-- Sair é um POST com token: um link podia ser
                         disparado por outro site. -->
                    <form class="formulario-sair" method="post" action="logout.php">
                        <input type="hidden" name="csrf" value="<?= painelEsc(Sessao::csrf()) ?>">
                        <button class="botao botao--fantasma botao-sair" type="submit">
                            <svg aria-hidden="true"><use href="#icone-sair"/></svg>
                            <span>Sair</span>
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <!-- A gaveta do menu é uma só, servida a todas as páginas; o tema
             também, para o botão da barra de topo funcionar em qualquer
             ecrã do painel. -->
        <script src="../assets/js/menu-painel.js" defer></script>
        <script src="../assets/js/tema.js" defer></script>
<?php
}

// Cabeçalhos de segurança da área autenticada. Ficam neste ficheiro porque é
// o único que todas as páginas do painel carregam: assim não há como uma
// página nova se esquecer deles.
if (class_exists('Sessao')) {
    Sessao::cabecalhosSeguranca();
}
