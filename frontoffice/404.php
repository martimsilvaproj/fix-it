<?php
declare(strict_types=1);

/**
 * 404.php — endereço que não existe.
 *
 * Diz o que aconteceu e dá três saídas úteis, na voz da casa. Sem culpa, sem
 * piada e sem «página não encontrada» em inglês.
 */

http_response_code(404);

require_once __DIR__ . '/front-comum.php';

pagina_inicio(
    'Página não encontrada',
    'Este endereço não existe no site da FixIt. Aqui ficam os caminhos que funcionam.',
    ''
);
?>

<section class="bloco" style="margin-top:34px" aria-labelledby="titulo-404">
    <h1 class="bloco__titulo" id="titulo-404">Esta página não existe</h1>
    <p class="bloco__lede">
        O endereço pode ter mudado ou estar mal escrito. Nada se perdeu: os
        pedidos continuam na oficina e a consulta por PIN funciona como sempre.
    </p>

    <div class="prosa">
        <ul>
            <li><a class="ligacao-texto" href="index.php">Voltar à entrada do site</a></li>
            <li><a class="ligacao-texto" href="consulta.php">Consultar o estado com o PIN do talão</a></li>
            <li><a class="ligacao-texto" href="orcamento.php">Pedir orçamento</a></li>
        </ul>
    </div>
</section>

<?php
pagina_fim();
