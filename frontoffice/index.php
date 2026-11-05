<?php
declare(strict_types=1);

/**
 * index.php — entrada do site público.
 *
 * A primeira dobra é o percurso de uma reparação: o PIN logo abaixo da
 * promessa, as cinco fases em linha e o pedido de orçamento à saída. A tese
 * é a do produto — a oficina mostra o trabalho em vez de o prometer.
 *
 * A régua que liga as fases é decorativa: o conteúdo aparece sem ela, e
 * com movimento reduzido fica estática. Nunca há texto escondido à espera
 * de JavaScript.
 */

require_once __DIR__ . '/front-comum.php';

$fases = [
    [
        'nome'    => 'Recebido',
        'oficina' => 'Descrevemos a avaria contigo, fotografamos o estado e emitimos o talão.',
        'cliente' => 'Ficas com o talão e o PIN.',
    ],
    [
        'nome'    => 'Em diagnóstico',
        'oficina' => 'Abrimos, testamos e apuramos o que está mal e quanto custa resolver.',
        'cliente' => 'Esperas pelo orçamento.',
    ],
    [
        'nome'    => 'Em reparação',
        'oficina' => 'Só avançamos depois de o orçamento estar aceite.',
        'cliente' => 'Aprovas ou recusas o valor.',
    ],
    [
        'nome'    => 'Pronto',
        'oficina' => 'Testamos outra vez e registamos que está pronto a levantar.',
        'cliente' => 'Vens buscar o equipamento.',
    ],
    [
        'nome'    => 'Entregue',
        'oficina' => 'Entregamos, recebemos o pagamento e fechamos o pedido.',
        'cliente' => 'Levas o equipamento e a fatura.',
    ],
];

$temContactos = oficina('morada') !== null
    || oficina('telefone') !== null
    || oficina('email') !== null
    || oficina('horario') !== null;

pagina_inicio(
    'Reparações com o percurso à vista',
    'Reparamos portáteis, desktops, telemóveis e tablets. Cada pedido percorre cinco fases e tem um PIN para acompanhar, do balcão à entrega.',
    ''
);
?>

<section class="faixa" aria-labelledby="titulo-entrada">

    <p class="faixa__sobre">Oficina de informática e telemóveis</p>

    <h1 class="faixa__titulo" id="titulo-entrada">
        O teu equipamento, à vista, do balcão à entrega.
    </h1>

    <p class="faixa__lede">
        Reparamos portáteis, desktops, telemóveis e tablets. Cada pedido faz
        cinco fases e tem um PIN — vês em que ponto está sem telefonar.
    </p>

    <div class="faixa__acesso">
        <form class="faixa__pin" method="post" action="consulta.php">
            <label class="faixa__pin-rotulo" for="pin-entrada">Já tens PIN?</label>

            <div class="faixa__pin-linha">
                <input class="faixa__pin-campo" type="text" id="pin-entrada" name="pin"
                       inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                       aria-describedby="pin-entrada-ajuda" required>

                <button class="botao" type="submit">Ver estado</button>
            </div>

            <p class="faixa__pin-ajuda" id="pin-entrada-ajuda">
                Os 6 dígitos do talão de entrada.
            </p>
        </form>
    </div>

    <figure class="figura figura--entrada faixa__figura">
        <img class="figura__imagem" src="../assets/img/talao.svg"
             width="480" height="620" decoding="async"
             alt="Talão de entrada: o equipamento, o PIN de seis algarismos e as cinco fases do percurso, numeradas de 01 a 05.">
        <figcaption class="figura__legenda">
            Exemplo de talão: o PIN e o percurso, no mesmo papel
        </figcaption>
    </figure>

    <ol class="percurso" aria-label="Percurso de uma reparação">
        <span class="percurso__regua" aria-hidden="true"></span>

        <?php foreach ($fases as $indice => $fase): ?>
            <li class="percurso__passo">
                <span class="percurso__marca" aria-hidden="true"><?= sprintf('%02d', $indice + 1) ?></span>
                <span class="percurso__texto">
                    <span class="percurso__nome"><?= h($fase['nome']) ?></span>
                    <span class="percurso__desc"><?= h($fase['oficina']) ?></span>
                    <span class="percurso__cliente"><?= h($fase['cliente']) ?></span>
                </span>
            </li>
        <?php endforeach; ?>
    </ol>

    <div class="faixa__acoes">
        <a class="botao botao--primario botao--grande" href="orcamento.php">
            Pedir orçamento
            <svg width="16" height="16" aria-hidden="true"><use href="#icone-seta"/></svg>
        </a>
    </div>
</section>

<section class="bloco" aria-labelledby="titulo-oferta">
    <h2 class="bloco__titulo" id="titulo-oferta">O que reparamos</h2>
    <p class="bloco__lede">
        Informática e telemóveis. O mesmo percurso para todos os equipamentos,
        do primeiro teste à entrega.
    </p>

    <ul class="oferta">
        <?php foreach ($oficina['oferta'] as $linha): ?>
            <li class="oferta__linha">
                <span class="oferta__tipo"><?= h($linha['tipo']) ?></span>
                <span class="oferta__nota"><?= h($linha['nota']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="bloco__lede" style="margin-top:22px">
        <a class="ligacao-texto" href="servicos.php">Ver o que acontece a cada tipo de equipamento</a>
    </p>
</section>

<section class="bloco" aria-labelledby="titulo-empresas">
    <h2 class="bloco__titulo" id="titulo-empresas">Escritórios e pequenas empresas</h2>
    <p class="bloco__lede">
        Cada equipamento tem o seu pedido e o seu PIN, mesmo quando entram
        vários ao mesmo tempo. A fatura sai com o pedido.
    </p>
    <p class="bloco__lede" style="margin-top:14px">
        <a class="ligacao-texto" href="orcamento.php">Pedir orçamento para a empresa</a>
    </p>
</section>

<section class="bloco" aria-labelledby="titulo-onde">
    <h2 class="bloco__titulo" id="titulo-onde">
        <?= $temContactos ? 'Onde estamos' : 'Trazer o equipamento' ?>
    </h2>

    <?php if ($temContactos): ?>
        <address class="prosa" style="font-style:normal">
            <?php if (oficina('morada') !== null): ?>
                <p><?= h(oficina('morada')) ?></p>
            <?php endif; ?>
            <?php if (oficina('horario') !== null): ?>
                <p><?= h(oficina('horario')) ?></p>
            <?php endif; ?>
            <?php if (oficina('telefone') !== null): ?>
                <p><a class="ligacao-texto" href="tel:<?= h(preg_replace('/\s+/', '', (string) oficina('telefone'))) ?>"><?= h(oficina('telefone')) ?></a></p>
            <?php endif; ?>
            <?php if (oficina('email') !== null): ?>
                <p><a class="ligacao-texto" href="mailto:<?= h(oficina('email')) ?>"><?= h(oficina('email')) ?></a></p>
            <?php endif; ?>
        </address>
        <p class="bloco__lede"><?= h((string) $oficina['entrega']) ?></p>
    <?php else: ?>
        <p class="bloco__lede">
            <?= h((string) $oficina['entrega']) ?> Descreve a avaria no pedido de
            orçamento e a oficina responde pelo contacto que deixares.
        </p>
        <p class="bloco__lede" style="margin-top:14px">
            <a class="ligacao-texto" href="contacto.php">Como falar com a oficina</a>
        </p>
    <?php endif; ?>
</section>

<section class="fecho" aria-labelledby="titulo-fecho">
    <p class="fecho__texto" id="titulo-fecho">
        Diz-nos o que está avariado. O orçamento chega antes de mexermos em nada.
    </p>
    <a class="botao botao--primario botao--grande" href="orcamento.php">Pedir orçamento</a>
</section>

<script>
/* A régua do percurso preenche-se à medida que a banda atravessa o ecrã.
   É decoração: se este script não correr, a linha fica neutra e as cinco
   fases continuam legíveis. */
(function () {
    "use strict";

    var banda = document.querySelector(".percurso");

    if (!banda) {
        return;
    }

    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        banda.style.setProperty("--progresso", "100%");
        return;
    }

    var agendado = false;

    function atualizar() {
        agendado = false;

        var caixa = banda.getBoundingClientRect();
        var altura = window.innerHeight || 0;

        if (caixa.height <= 0) {
            return;
        }

        var progresso = (altura * 0.82 - caixa.top) / caixa.height;
        progresso = Math.min(1, Math.max(0, progresso));

        banda.style.setProperty("--progresso", (progresso * 100).toFixed(1) + "%");
    }

    function pedir() {
        if (agendado) {
            return;
        }

        agendado = true;
        window.requestAnimationFrame(atualizar);
    }

    window.addEventListener("scroll", pedir, { passive: true });
    window.addEventListener("resize", pedir);
    atualizar();
})();
</script>

<?php
pagina_fim();
