<?php
declare(strict_types=1);

/**
 * front-comum.php — moldura partilhada do site público.
 *
 * Cada página do front office começa assim:
 *
 *     require_once __DIR__ . '/front-comum.php';
 *     pagina_inicio('Serviços', 'Descrição para a meta…', 'servicos');
 *     … conteúdo …
 *     pagina_fim();
 *
 * O que vive aqui: <head>, marca, navegação, rodapé, sprite de ícones e as
 * funções de apoio. O que não vive aqui: conteúdo de página, consultas à
 * base de dados e regras de negócio.
 *
 * O mundo (ver PRODUCT.md): marca do parafuso em cruz, família azul que já
 * existe em assets/css/painel.css, tipografia de sistema, voz sóbria em
 * pt-PT na segunda pessoa. Sem CDN, sem bibliotecas, sem fontes externas.
 */

require_once __DIR__ . '/../nucleo/conexao.php';
require_once __DIR__ . '/dados-oficina.php';

/** Escapa texto para HTML. */
function h(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Valor factual da oficina, ou null quando está por preencher.
 * Nunca devolve uma string vazia: quem chama decide omitir o bloco.
 */
function oficina(string $chave): ?string
{
    global $oficina;

    $valor = $oficina[$chave] ?? null;

    return is_string($valor) && trim($valor) !== '' ? $valor : null;
}

/** Oferta confirmada (tipo + nota), para o menu e para as abas. */
function oferta_tipos(): array
{
    global $oficina;

    return is_array($oficina['oferta'] ?? null) ? $oficina['oferta'] : [];
}

/** Identificador de âncora a partir do nome do tipo ("Telemóveis" -> telemoveis). */
function slug_tipo(string $tipo): string
{
    // strtolower é ASCII; os acentos são resolvidos no mapa. Sem mbstring:
    // o mundo do projecto é PHP puro e nem todas as instalações a têm.
    $mapa = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c',
    ];

    $limpo = strtr(strtolower($tipo), $mapa);
    $limpo = preg_replace('/[^a-z0-9]+/', '-', $limpo) ?? '';

    return trim($limpo, '-');
}

/**
 * Desenho de linha do equipamento, para as abas de servicos.php.
 *
 * Grafismo próprio, desenhado em SVG no documento: não há fotografia nem
 * ficheiro externo para carregar. É decorativo — o nome do tipo está sempre
 * em texto ao lado.
 */
function icone_equipamento(string $tipo): string
{
    $desenhos = [
        'portateis' => '<rect x="12" y="14" width="40" height="26" rx="2"/>'
            . '<path d="M7 46h50l-3-6H10l-3 6Z"/>'
            . '<path d="M27 42h10"/>',
        'desktops' => '<rect x="8" y="11" width="48" height="32" rx="2"/>'
            . '<path d="M32 43v9M22 52h20"/>'
            . '<path d="M16 19h32"/>',
        'telemoveis' => '<rect x="21" y="7" width="22" height="50" rx="4"/>'
            . '<path d="M28 13h8"/>'
            . '<circle cx="32" cy="50" r="1.8"/>',
        'tablets' => '<rect x="15" y="8" width="34" height="48" rx="3"/>'
            . '<path d="M28 49h8"/>'
            . '<circle cx="32" cy="49" r="1.6"/>',
    ];

    $desenho = $desenhos[slug_tipo($tipo)]
        ?? '<rect x="12" y="12" width="40" height="40" rx="2"/>';

    return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.8"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $desenho . '</svg>';
}

/** Ficheiro actual, para o aria-current da navegação. */
function pagina_actual(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}

/** Endereço do site a partir do pedido actual (sem domínio inventado). */
function site_base(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

    return $host === '' ? '' : 'http://' . $host . '/frontoffice/';
}

/** Navegação principal: ficheiro => [chave, rótulo]. */
function navegacao_principal(): array
{
    return [
        'servicos.php'      => ['servicos', 'O que reparamos'],
        'como-funciona.php' => ['como-funciona', 'Como funciona'],
        'perguntas.php'     => ['perguntas', 'Perguntas'],
        'contacto.php'      => ['contacto', 'Contacto'],
    ];
}

/**
 * Abre o documento: <head>, cabeçalho e <main>.
 *
 * @param array<int, string> $folhas Folhas extra em assets/css/.
 */
function pagina_inicio(string $titulo, string $descricao, string $chave = '', array $folhas = []): void
{
    // Cabeçalhos do site público. Têm de sair antes do primeiro byte de HTML,
    // e é aqui porque é por aqui que todas as páginas passam.
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header(
            "Content-Security-Policy: default-src 'self'; base-uri 'none'; "
            . "form-action 'self'; frame-ancestors 'none'; object-src 'none'; "
            . "img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
            . "script-src 'self' 'unsafe-inline'"
        );
    }

    $nome   = oficina('nome') ?? 'FixIt';
    $actual = pagina_actual();
    $base   = site_base();
    $canon  = $base !== '' ? $base . $actual : '';
    ?><!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($titulo) ?> — <?= h($nome) ?></title>
    <meta name="description" content="<?= h($descricao) ?>">
    <meta name="theme-color" content="#0C2038">
    <?php if ($canon !== ''): ?>
    <link rel="canonical" href="<?= h($canon) ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <meta property="og:locale" content="pt_PT">
    <meta property="og:title" content="<?= h($titulo) ?> — <?= h($nome) ?>">
    <meta property="og:description" content="<?= h($descricao) ?>">
    <meta property="og:site_name" content="<?= h($nome) ?>">
    <?php if ($canon !== ''): ?>
    <meta property="og:url" content="<?= h($canon) ?>">
    <?php endif; ?>

    <link rel="stylesheet" href="../assets/css/painel.css">
    <link rel="stylesheet" href="../assets/css/front.css">
    <?php foreach ($folhas as $folha): ?>
    <link rel="stylesheet" href="../assets/css/<?= h($folha) ?>">
    <?php endforeach; ?>
</head>
<body class="site-body">

<a class="saltar-conteudo" href="#conteudo">Saltar para o conteúdo</a>

<svg class="sprite" width="0" height="0" aria-hidden="true" focusable="false">
    <defs>
        <g id="icone-seta" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4.5 12h14M13 6.5l5.5 5.5L13 17.5"/>
        </g>
        <g id="icone-visto" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="m5 12.5 4.5 4.5L19 7"/>
        </g>
        <g id="icone-alerta" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 4.5 21 20H3L12 4.5Z"/><path d="M12 10v4"/><path d="M12 17h.01"/>
        </g>
        <g id="icone-relogio" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="8.25"/><path d="M12 7.5V12l3 1.9"/>
        </g>
    </defs>
</svg>

<header class="site-topo">
    <div class="site-topo__interior">
        <a class="marca marca--clara site-marca" href="index.php">
            <span class="marca__simbolo" aria-hidden="true">
                <!-- Marca: cabeça de parafuso em cruz. -->
                <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round">
                    <circle cx="12" cy="12" r="8.25"/>
                    <path d="M12 7.5v9M7.5 12h9"/>
                </svg>
            </span>
            <span class="marca__texto">
                <span class="marca__nome"><?= h($nome) ?></span>
                <span class="marca__descricao"><?= h(oficina('descritor') ?? '') ?></span>
            </span>
        </a>

        <button class="site-menu-botao" type="button" id="site-menu-botao"
                aria-expanded="false" aria-controls="site-nav">
            Menu
        </button>

        <nav class="site-nav" id="site-nav" aria-label="Navegação principal">
            <div class="site-nav__grupo" data-submenu>
                <button class="site-nav__ligacao site-nav__botao-menu" type="button"
                        id="botao-servicos" aria-expanded="false"
                        aria-controls="submenu-servicos" data-submenu-botao>
                    O que reparamos
                    <svg class="site-nav__seta" width="9" height="9" viewBox="0 0 10 10"
                         fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M2 4l3 3 3-3"/>
                    </svg>
                </button>

                <ul class="site-nav__submenu" id="submenu-servicos" hidden>
                    <?php foreach (oferta_tipos() as $linha): ?>
                        <li>
                            <a href="servicos.php#<?= h(slug_tipo($linha['tipo'])) ?>">
                                <?= h($linha['tipo']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li class="site-nav__submenu-fim">
                        <a href="servicos.php">Ver o que reparamos</a>
                    </li>
                </ul>
            </div>

            <?php foreach (navegacao_principal() as $ficheiro => $item): ?>
                <?php if ($item[0] === 'servicos') { continue; } ?>
                <a class="site-nav__ligacao"
                   href="<?= h($ficheiro) ?>"
                   <?= $chave === $item[0] ? 'aria-current="page"' : '' ?>><?= h($item[1]) ?></a>
            <?php endforeach; ?>

            <a class="site-nav__ligacao site-nav__ligacao--estado" href="consulta.php">
                Já tenho PIN
            </a>
            <a class="botao botao--primario site-nav__acao" href="orcamento.php">
                Pedir orçamento
            </a>
        </nav>
    </div>
</header>

<div class="fita" aria-hidden="true">
    <p class="fita__texto">
        Portáteis · Desktops · Telemóveis · Tablets · Talão com PIN
    </p>
</div>

<main class="site-main" id="conteudo" tabindex="-1">
    <?php
}

/**
 * Fecha o documento: rodapé, script do menu e fim do HTML.
 */
function pagina_fim(): void
{
    $nome = oficina('nome') ?? 'FixIt';
    ?>
</main>

<footer class="site-rodape">
    <div class="site-rodape__interior">
        <div class="site-rodape__marca">
            <a class="marca marca--clara site-marca" href="index.php">
                <span class="marca__simbolo" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke-linecap="round">
                        <circle cx="12" cy="12" r="8.25"/>
                        <path d="M12 7.5v9M7.5 12h9"/>
                    </svg>
                </span>
                <span class="marca__texto">
                    <span class="marca__nome"><?= h($nome) ?></span>
                    <span class="marca__descricao"><?= h(oficina('descritor') ?? '') ?></span>
                </span>
            </a>
            <p class="site-rodape__nota">
                Reparações de informática e telemóveis, acompanhadas por um PIN.
            </p>
        </div>

        <nav class="site-rodape__coluna" aria-label="Navegação do rodapé">
            <?php foreach (navegacao_principal() as $ficheiro => $item): ?>
                <a href="<?= h($ficheiro) ?>"><?= h($item[1]) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="site-rodape__coluna">
            <a href="orcamento.php">Pedir orçamento</a>
            <a href="consulta.php">Consultar estado</a>
            <a href="privacidade.php">Privacidade</a>
        </div>

        <?php if (oficina('morada') !== null || oficina('telefone') !== null || oficina('horario') !== null): ?>
            <address class="site-rodape__coluna site-rodape__contactos">
                <?php if (oficina('morada') !== null): ?>
                    <span><?= h(oficina('morada')) ?></span>
                <?php endif; ?>
                <?php if (oficina('telefone') !== null): ?>
                    <a href="tel:<?= h(preg_replace('/\s+/', '', (string) oficina('telefone'))) ?>"><?= h(oficina('telefone')) ?></a>
                <?php endif; ?>
                <?php if (oficina('email') !== null): ?>
                    <a href="mailto:<?= h(oficina('email')) ?>"><?= h(oficina('email')) ?></a>
                <?php endif; ?>
                <?php if (oficina('horario') !== null): ?>
                    <span><?= h(oficina('horario')) ?></span>
                <?php endif; ?>
            </address>
        <?php endif; ?>
    </div>

    <div class="site-rodape__base">
        <p>© <?= date('Y') ?> <?= h($nome) ?>. Os pedidos são registados no balcão, com talão e PIN.</p>
    </div>
</footer>

<script>
/* Menu do telemóvel: só isto. Sem bibliotecas, sem transições que
   escondam conteúdo. */
(function () {
    "use strict";

    var botao = document.getElementById("site-menu-botao");
    var nav = document.getElementById("site-nav");

    if (!botao || !nav) {
        return;
    }

    function fechar() {
        nav.classList.remove("site-nav--aberto");
        botao.setAttribute("aria-expanded", "false");
    }

    botao.addEventListener("click", function () {
        var aberto = nav.classList.toggle("site-nav--aberto");
        botao.setAttribute("aria-expanded", aberto ? "true" : "false");
    });

    nav.addEventListener("click", function (evento) {
        if (evento.target.closest("a")) {
            fechar();
        }
    });

    document.addEventListener("keydown", function (evento) {
        if (evento.key === "Escape") {
            fechar();
        }
    });
})();

/* Submenu «O que reparamos»: abre por clique, fecha ao clicar fora ou com
   Escape. Sem este script, as páginas de serviço continuam acessiveis pelo
   menu principal e pelo rodapé. */
(function () {
    "use strict";

    var grupos = document.querySelectorAll("[data-submenu]");

    if (!grupos.length) {
        return;
    }

    function fechar(grupo) {
        var botao = grupo.querySelector("[data-submenu-botao]");
        var lista = grupo.querySelector(".site-nav__submenu");

        if (!botao || !lista) {
            return;
        }

        botao.setAttribute("aria-expanded", "false");
        lista.hidden = true;
    }

    Array.prototype.forEach.call(grupos, function (grupo) {
        var botao = grupo.querySelector("[data-submenu-botao]");
        var lista = grupo.querySelector(".site-nav__submenu");

        if (!botao || !lista) {
            return;
        }

        botao.addEventListener("click", function () {
            var aberto = botao.getAttribute("aria-expanded") === "true";

            Array.prototype.forEach.call(grupos, fechar);

            if (!aberto) {
                botao.setAttribute("aria-expanded", "true");
                lista.hidden = false;
            }
        });

        grupo.addEventListener("keydown", function (evento) {
            if (evento.key === "Escape") {
                fechar(grupo);
                botao.focus();
            }
        });
    });

    document.addEventListener("click", function (evento) {
        Array.prototype.forEach.call(grupos, function (grupo) {
            if (!grupo.contains(evento.target)) {
                fechar(grupo);
            }
        });
    });
})();
</script>
</body>
</html>
    <?php
}
