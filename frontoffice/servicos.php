<?php
declare(strict_types=1);

/**
 * servicos.php — o que se repara e o que acontece a cada equipamento.
 *
 * A oferta é a de `dados-oficina.php` e nada mais: informática e telemóveis.
 * Não há catálogo de marcas, nem lista de avarias predefinidas, nem preços.
 */

require_once __DIR__ . '/front-comum.php';

pagina_inicio(
    'O que reparamos',
    'Portáteis, desktops, telemóveis e tablets. O mesmo percurso de cinco fases para todos, com orçamento antes da reparação.',
    'servicos'
);
?>

<section class="bloco bloco--primeiro" aria-labelledby="titulo-servicos">
    <h1 class="bloco__titulo" id="titulo-servicos">O que reparamos</h1>
    <p class="bloco__lede">
        Informática e telemóveis. Não trabalhamos com outros equipamentos —
        se o teu não estiver nesta lista, diz-nos no pedido e confirmamos.
    </p>

    <div class="tipos" data-tipos>
        <div class="tipos__lista" role="tablist" aria-label="Tipo de equipamento" hidden>
            <?php foreach (oferta_tipos() as $indice => $linha): ?>
                <?php $slug = slug_tipo($linha['tipo']); ?>
                <button class="tipos__botao" type="button" role="tab"
                        id="aba-<?= h($slug) ?>" aria-controls="<?= h($slug) ?>"
                        aria-selected="<?= $indice === 0 ? 'true' : 'false' ?>"
                        tabindex="<?= $indice === 0 ? '0' : '-1' ?>"><?= h($linha['tipo']) ?></button>
            <?php endforeach; ?>
        </div>

        <?php foreach (oferta_tipos() as $indice => $linha): ?>
            <?php $slug = slug_tipo($linha['tipo']); ?>
            <section class="tipos__painel" id="<?= h($slug) ?>" role="tabpanel"
                     aria-labelledby="aba-<?= h($slug) ?>">
                <div class="tipos__arte" aria-hidden="true">
                    <?= icone_equipamento($linha['tipo']) ?>
                </div>
                <div class="tipos__texto">
                    <h3 class="tipos__nome"><?= h($linha['tipo']) ?></h3>
                    <p class="tipos__nota"><?= h($linha['nota']) ?></p>
                    <p class="tipos__mais">
                        <a class="ligacao-texto" href="como-funciona.php">Ver o percurso fase a fase</a>
                    </p>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</section>

<section class="bloco" aria-labelledby="titulo-acontece">
    <h2 class="bloco__titulo" id="titulo-acontece">O que acontece ao equipamento</h2>

    <div class="prosa">
        <p>
            Todo o equipamento faz o mesmo percurso, seja um portátil ou um
            telemóvel: <strong>Recebido</strong>, <strong>Em diagnóstico</strong>,
            <strong>Em reparação</strong>, <strong>Pronto</strong> e
            <strong>Entregue</strong>.
        </p>
        <p>
            No balcão descrevemos a avaria contigo, registamos o estado em que o
            equipamento chegou e emitimos o talão com o PIN. Em diagnóstico,
            apuramos o que está mal e dizemos quanto custa resolver. Só depois
            de o valor estar aceite é que a reparação começa — e podes aceitá-lo
            na consulta do PIN, sem teres de voltar ao balcão.
        </p>
        <p>
            Quando fica pronto, o estado muda para <strong>Pronto</strong> no teu
            percurso. Levantas no balcão e o pedido fecha em
            <strong>Entregue</strong>.
        </p>
    </div>

    <p class="bloco__lede bloco__lede--meio">
        <a class="ligacao-texto" href="como-funciona.php">Ver o percurso fase a fase</a>
    </p>
</section>

<section class="bloco" aria-labelledby="titulo-trazer">
    <h2 class="bloco__titulo" id="titulo-trazer">Antes de trazeres</h2>

    <div class="prosa">
        <ul>
            <li>Traz o equipamento ao balcão — é assim que o pedido é registado.</li>
            <li>Descreve a avaria com as palavras que usares: o que falhou e desde quando.</li>
            <li>Guarda o talão. É o PIN que abre a consulta do estado.</li>
        </ul>
        <p><?= h((string) $oficina['entrega']) ?></p>
    </div>
</section>

<section class="fecho">
    <p class="fecho__texto">Diz-nos o que está avariado e recebes o orçamento antes da reparação.</p>
    <a class="botao botao--primario botao--grande" href="orcamento.php">Pedir orçamento</a>
</section>

<script>
/* Abas por tipo de equipamento. Sem este script os quatro paineis ficam
   empilhados e legiveis: as abas só existem quando há JavaScript. */
(function () {
    "use strict";

    var caixa = document.querySelector("[data-tipos]");

    if (!caixa) {
        return;
    }

    var lista = caixa.querySelector(".tipos__lista");
    var abas = Array.prototype.slice.call(caixa.querySelectorAll("[role=\"tab\"]"));
    var paineis = Array.prototype.slice.call(caixa.querySelectorAll("[role=\"tabpanel\"]"));

    if (!lista || abas.length === 0 || abas.length !== paineis.length) {
        return;
    }

    function activar(indice, comFoco) {
        abas.forEach(function (aba, i) {
            var activa = i === indice;

            aba.setAttribute("aria-selected", activa ? "true" : "false");
            aba.tabIndex = activa ? 0 : -1;
            paineis[i].hidden = !activa;
        });

        if (comFoco) {
            abas[indice].focus();
        }
    }

    abas.forEach(function (aba, indice) {
        aba.addEventListener("click", function () {
            activar(indice, false);
        });
    });

    lista.addEventListener("keydown", function (evento) {
        var actual = abas.indexOf(document.activeElement);

        if (actual === -1) {
            return;
        }

        var alvo = null;

        if (evento.key === "ArrowRight") {
            alvo = (actual + 1) % abas.length;
        } else if (evento.key === "ArrowLeft") {
            alvo = (actual - 1 + abas.length) % abas.length;
        } else if (evento.key === "Home") {
            alvo = 0;
        } else if (evento.key === "End") {
            alvo = abas.length - 1;
        }

        if (alvo !== null) {
            evento.preventDefault();
            activar(alvo, true);
        }
    });

    // O menu do site liga a servicos.php#telemoveis: abrir a aba certa.
    var inicial = 0;

    paineis.forEach(function (painel, indice) {
        if (window.location.hash === "#" + painel.id) {
            inicial = indice;
        }
    });

    lista.hidden = false;
    activar(inicial, false);
})();
</script>

<?php
pagina_fim();
