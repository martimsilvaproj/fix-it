<?php
declare(strict_types=1);

/**
 * privacidade.php — o que o site faz com os dados de quem o usa.
 *
 * Descreve apenas o que o código faz: os campos do formulário, a consulta
 * por PIN, o registo da consulta e os cookies de sessão. Sem serviços de
 * analytics, sem cookies de terceiros, sem transferências para fora.
 */

require_once __DIR__ . '/front-comum.php';

pagina_inicio(
    'Privacidade',
    'Que dados o site da FixIt recolhe, para quê, e como pedir informação, correção ou eliminação.',
    'privacidade'
);
?>

<section class="bloco bloco--primeiro" aria-labelledby="titulo-privacidade">
    <h1 class="bloco__titulo" id="titulo-privacidade">Privacidade</h1>
    <p class="bloco__lede">
        Esta página descreve o que este site faz com os dados de quem o usa.
        Não descreve o que a oficina guarda em papel, no balcão.
    </p>
</section>

<div class="prosa">
    <h2>Pedido de orçamento</h2>
    <p>
        O formulário pede quatro coisas: <strong>nome</strong>,
        <strong>contacto</strong> (telefone ou email), <strong>equipamento</strong>
        e a <strong>descrição da avaria</strong>. O campo de marca e modelo é
        opcional.
    </p>
    <p>
        Usamos estes dados para responder ao pedido — para perceber o que se
        passa com o equipamento e dizer quanto custa resolver. A base legal é a
        diligência pré-contratual a pedido do titular: pediste um orçamento e
        precisamos dos dados para o dar.
    </p>
    <p>
        Não usamos estes dados para publicidade, não os vendemos e não os
        passamos a terceiros.
    </p>

    <h2>Consulta por PIN</h2>
    <p>
        A consulta pública pede apenas o PIN do talão. Não pede nome, email nem
        telefone, e a página de resultado <strong>não mostra o nome do cliente</strong>.
    </p>
    <p>
        Cada consulta bem-sucedida fica registada com a data, para o pedido ter
        histórico. Esse registo não guarda o endereço de rede em claro: o limite
        de tentativas usa um resumo do endereço, não o endereço.
    </p>
    <p>
        Se o pedido estiver à espera de decisão, a consulta do PIN também te
        deixa <strong>aceitar ou recusar o orçamento</strong>. Fica registado
        apenas isso: a decisão, a data e o valor que estava à frente — para a
        oficina saber por quanto é que disseste sim, ou não. Não é preciso
        criar conta nem dar mais dados.
    </p>

    <h2>Cookies</h2>
    <p>
        O site usa um cookie de sessão para o formulário e para a consulta, e
        outro para a área da oficina. São cookies técnicos, necessários ao
        funcionamento — não há cookies de publicidade, de análise ou de redes
        sociais.
    </p>

    <h2>Durante quanto tempo</h2>
    <p>
        Os pedidos de orçamento ficam guardados enquanto forem necessários para
        responder e, quando houver reparação, enquanto a faturação exigir. As
        consultas ficam associadas ao pedido, para o histórico que o cliente vê,
        e as decisões sobre o orçamento ficam com o pedido pelo mesmo motivo.
    </p>

    <h2>Os teus direitos</h2>
    <p>
        Podes pedir informação sobre os dados que existem sobre ti, pedir a sua
        correção ou pedir a eliminação. Podes fazê-lo no balcão da oficina. Se
        entenderes que os teus dados não estão a ser tratados como devem, podes
        reclamar junto da Comissão Nacional de Proteção de Dados.
    </p>

    <h2>O que este site não faz</h2>
    <ul>
        <li>Não usa serviços de análise de tráfego nem píxeis de publicidade.</li>
        <li>Não carrega tipos de letra, scripts ou imagens de servidores de terceiros.</li>
        <li>Não envia os dados do formulário por email: ficam na base da oficina.</li>
    </ul>
</div>

<?php
pagina_fim();
