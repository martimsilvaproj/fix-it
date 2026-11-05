<?php
declare(strict_types=1);

/**
 * perguntas.php — dúvidas que travam o cliente antes de trazer o equipamento.
 *
 * Cada resposta é verificável no sistema ou no balcão. Sem preços, sem
 * prazos, sem garantias: nenhum desses factos existe (ver PRODUCT.md).
 * Usa <details>, por isso funciona sem JavaScript.
 */

require_once __DIR__ . '/front-comum.php';

$perguntas = [
    [
        'pergunta' => 'O que é o PIN e onde o encontro?',
        'resposta' => 'É um código de seis algarismos que a oficina emite quando o '
            . 'equipamento é recebido. Está no talão de entrada. Só ele identifica '
            . 'o pedido na consulta pública.',
    ],
    [
        'pergunta' => 'Perdi o talão. Consigo consultar o estado?',
        'resposta' => 'A consulta pública só aceita o PIN. Sem ele, traz ao balcão o '
            . 'número do pedido ou o próprio equipamento, para a oficina confirmar '
            . 'de quem é.',
    ],
    [
        'pergunta' => 'A consulta mostra os meus dados?',
        'resposta' => 'Não mostra o teu nome. Mostra o equipamento, o número do '
            . 'pedido, a avaria tal como foi descrita, o orçamento e as datas de '
            . 'cada fase. Quem tem o PIN vê o pedido, por isso guarda o talão.',
    ],
    [
        'pergunta' => 'Quanto custa a reparação?',
        'resposta' => 'Não publicamos preços: o valor depende do que o equipamento '
            . 'tem. O orçamento é comunicado depois do diagnóstico e antes de '
            . 'qualquer reparação.',
    ],
    [
        'pergunta' => 'Quanto tempo demora?',
        'resposta' => 'Não prometemos prazos. O estado muda no percurso à medida que '
            . 'o trabalho avança, e vês a data de cada fase na consulta.',
    ],
    [
        'pergunta' => 'Que equipamentos reparam?',
        'resposta' => 'Informática e telemóveis: portáteis, desktops, telemóveis e '
            . 'tablets. Se o teu equipamento não for um destes, diz-nos no pedido de '
            . 'orçamento e confirmamos se é possível.',
    ],
    [
        'pergunta' => 'Posso enviar o equipamento por transportadora?',
        'resposta' => 'Não. O equipamento é entregue e levantado no balcão — é assim '
            . 'que a receção e a entrega ficam registadas contigo presente.',
    ],
    [
        'pergunta' => 'Posso aceitar ou recusar o orçamento pelo site?',
        'resposta' => 'Sim, quando o pedido está <strong>Em diagnóstico</strong> e o '
            . 'valor já está definido. A consulta do PIN mostra o orçamento e dois '
            . 'botões: aceitar põe o pedido em reparação, recusar deixa-o '
            . '<strong>Pronto</strong> para levantares o equipamento como está. '
            . 'Também podes decidir ao balcão, como sempre.',
    ],
    [
        'pergunta' => 'E se o orçamento for alto?',
        'resposta' => 'Dizes que não avanças — na consulta do PIN ou ao balcão — e nada '
            . 'é reparado. O equipamento fica no estado em que estiver, a aguardar '
            . 'que o levantes.',
    ],
    [
        'pergunta' => 'Os meus dados ficam guardados?',
        'resposta' => 'O pedido de orçamento guarda o nome, o contacto, o equipamento '
            . 'e a descrição da avaria, para a oficina te poder responder. A consulta '
            . 'por PIN não recolhe o teu nome. Os detalhes estão na página de '
            . 'privacidade.',
    ],
    [
        'pergunta' => 'Como sei que está pronto?',
        'resposta' => 'O pedido passa a <strong>Pronto</strong> e vês essa mudança, com '
            . 'a data, na consulta do PIN.',
    ],
];

pagina_inicio(
    'Perguntas',
    'PIN perdido, orçamento, prazos, dados pessoais: as respostas às dúvidas mais comuns antes de trazer o equipamento.',
    'perguntas'
);
?>

<section class="bloco" style="margin-top:34px" aria-labelledby="titulo-perguntas">
    <h1 class="bloco__titulo" id="titulo-perguntas">Perguntas</h1>
    <p class="bloco__lede">
        O que perguntam antes de trazer o equipamento. Se faltar alguma,
        escreve-a no pedido de orçamento.
    </p>

    <div class="site-filtro" hidden>
        <label class="site-filtro__rotulo" for="filtro-perguntas">Procurar nas perguntas</label>
        <input class="site-filtro__campo" type="search" id="filtro-perguntas"
               placeholder="pin, orçamento, dados…" autocomplete="off"
               aria-describedby="filtro-estado">
        <p class="site-filtro__estado" id="filtro-estado" role="status"></p>
    </div>

    <div class="perguntas">
        <?php foreach ($perguntas as $item): ?>
            <details class="pergunta">
                <summary class="pergunta__titulo"><?= h($item['pergunta']) ?></summary>
                <div class="pergunta__resposta"><?= $item['resposta'] ?></div>
            </details>
        <?php endforeach; ?>
    </div>
</section>

<section class="fecho">
    <p class="fecho__texto">Já tens o equipamento contigo? Descreve a avaria.</p>
    <a class="botao botao--primario botao--grande" href="orcamento.php">Pedir orçamento</a>
</section>

<script>
/* Filtro das perguntas: só esconde o que não corresponde. Sem script, o
   campo não existe e a lista fica completa. */
(function () {
    "use strict";

    var caixa = document.querySelector(".site-filtro");
    var campo = document.getElementById("filtro-perguntas");
    var estado = document.getElementById("filtro-estado");
    var lista = document.querySelector(".perguntas");

    if (!caixa || !campo || !estado || !lista) {
        return;
    }

    var itens = Array.prototype.slice.call(lista.querySelectorAll(".pergunta"));

    if (itens.length === 0) {
        return;
    }

    function semAcentos(texto) {
        return texto.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();
    }

    caixa.hidden = false;

    function filtrar() {
        var termo = semAcentos(campo.value.trim());
        var visiveis = 0;

        itens.forEach(function (item) {
            var corresponde = termo === "" || semAcentos(item.textContent).indexOf(termo) !== -1;

            item.hidden = !corresponde;

            if (corresponde) {
                visiveis += 1;
            }
        });

        if (termo === "") {
            estado.textContent = "";
        } else if (visiveis === 0) {
            estado.textContent = "Nenhuma pergunta corresponde. Tenta outra palavra ou escreve-nos no pedido de orçamento.";
        } else {
            estado.textContent = visiveis + " de " + itens.length + " perguntas";
        }
    }

    campo.addEventListener("input", filtrar);
})();
</script>

<?php
pagina_fim();
