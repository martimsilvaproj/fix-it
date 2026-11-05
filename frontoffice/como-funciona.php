<?php
declare(strict_types=1);

/**
 * como-funciona.php — o percurso do lado do cliente.
 *
 * Explica as cinco fases, o que é o talão e o PIN, e o que acontece quando
 * o orçamento não compensa. Sem prometer prazos nem valores: nenhum dos
 * dois existe (ver PRODUCT.md).
 */

require_once __DIR__ . '/front-comum.php';

pagina_inicio(
    'Como funciona',
    'O que acontece ao teu equipamento entre o balcão e a entrega, e o que é o PIN do talão.',
    'como-funciona'
);
?>

<section class="bloco" style="margin-top:34px" aria-labelledby="titulo-percurso">
    <h1 class="bloco__titulo" id="titulo-percurso">Do talão à entrega</h1>
    <p class="bloco__lede">
        Cinco fases, sempre pela mesma ordem. Podes acompanhar cada mudança
        com o PIN que recebes na receção.
    </p>
</section>

<section class="bloco" aria-labelledby="titulo-fases">
    <h2 class="bloco__titulo" id="titulo-fases">As cinco fases</h2>

    <div class="prosa">
        <h3>Recebido</h3>
        <p>
            Descreves a avaria no balcão. Registamos a descrição, o estado em que
            o equipamento chegou e a data. Recebes o talão com um PIN de seis
            algarismos.
        </p>

        <h3>Em diagnóstico</h3>
        <p>
            Abrimos e testamos. O resultado do diagnóstico é o que define o
            orçamento — dizemos o que está mal e quanto custa resolver.
        </p>

        <h3>Em reparação</h3>
        <p>
            A reparação só começa depois de aceitares o orçamento. Enquanto o
            valor não estiver aceite, o equipamento não é mexido.
        </p>
        <p>
            Quando o orçamento estiver definido, aceitas ou recusas na consulta
            do PIN — ou ao balcão, como sempre. Aceitar põe o pedido nesta fase;
            recusar deixa-o pronto a levantar, sem ser reparado.
        </p>

        <h3>Pronto</h3>
        <p>
            Testamos outra vez e marcamos o pedido como pronto a levantar. O
            estado muda no teu percurso, com a data.
        </p>

        <h3>Entregue</h3>
        <p>
            Entregamos o equipamento no balcão, recebemos o pagamento e fechamos
            o pedido. A data de conclusão fica registada.
        </p>
    </div>
</section>

<section class="bloco" aria-labelledby="titulo-pin">
    <h2 class="bloco__titulo" id="titulo-pin">O talão e o PIN</h2>

    <div class="prosa">
        <p>
            O PIN tem seis algarismos e é único: só ele identifica o teu pedido
            na consulta pública. Escreve-o tal como está no talão, sem espaços.
        </p>
        <p>
            A consulta mostra o estado atual, o percurso com as datas de cada
            fase, o equipamento, a avaria tal como foi descrita, o orçamento e a
            data de entrada.
        </p>
        <p>
            <strong>Não mostra o teu nome.</strong> Quem tem o PIN vê o pedido,
            mas não vê quem é o dono — por isso guarda o talão como guardarias um
            documento.
        </p>
        <p>
            Se o PIN for substituído, o anterior deixa de abrir a consulta no
            momento. Nesse caso a oficina entrega-te o novo.
        </p>
    </div>
</section>

<section class="bloco" aria-labelledby="titulo-recusar">
    <h2 class="bloco__titulo" id="titulo-recusar">Se o orçamento não compensar</h2>

    <div class="prosa">
        <p>
            Dizes que não avanças e ficamos por ali: nada é reparado sem a tua
            aceitação. Podes recusar na consulta do PIN ou ao balcão — o pedido
            passa a <strong>Pronto</strong> e o equipamento fica a aguardar que
            o levantes, no estado em que estiver.
        </p>
        <p>
            Se quiseres uma segunda opinião, podes pedir um orçamento pelo site
            antes de trazer o equipamento.
        </p>
    </div>
</section>

<section class="fecho">
    <p class="fecho__texto">Já deixaste o equipamento? O PIN do talão mostra-te onde está.</p>
    <a class="botao botao--primario botao--grande" href="consulta.php">Consultar estado</a>
</section>

<?php
pagina_fim();
