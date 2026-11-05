<?php
declare(strict_types=1);

/**
 * contacto.php — como falar com a oficina.
 *
 * Com morada, telefone, email e horário por preencher, a página não inventa
 * nenhum: apresenta o pedido de orçamento como o caminho e explica que o
 * equipamento é entregue no balcão. Quando os campos forem preenchidos em
 * `dados-oficina.php`, os blocos aparecem sozinhos.
 */

require_once __DIR__ . '/front-comum.php';

$temMorada   = oficina('morada') !== null;
$temTelefone = oficina('telefone') !== null;
$temEmail    = oficina('email') !== null;
$temHorario  = oficina('horario') !== null;

pagina_inicio(
    'Contacto',
    'Como falar com a FixIt: pelo pedido de orçamento no site ou no balcão, com o equipamento.',
    'contacto'
);
?>

<section class="bloco bloco--primeiro" aria-labelledby="titulo-contacto">
    <h1 class="bloco__titulo" id="titulo-contacto">Falar com a oficina</h1>
    <p class="bloco__lede">
        A reparação começa sempre no balcão, com o equipamento à frente. Para
        saber se vale a pena trazer, descreve a avaria no pedido de orçamento.
    </p>
</section>

<?php if ($temMorada || $temTelefone || $temEmail || $temHorario): ?>
    <section class="bloco" aria-labelledby="titulo-onde">
        <h2 class="bloco__titulo" id="titulo-onde">Onde e quando</h2>

        <div class="prosa">
            <?php if ($temMorada): ?>
                <p><?= h(oficina('morada')) ?></p>
            <?php endif; ?>
            <?php if ($temHorario): ?>
                <p><?= h(oficina('horario')) ?></p>
            <?php endif; ?>
            <?php if ($temTelefone): ?>
                <p>
                    Telefone:
                    <a class="ligacao-texto" href="tel:<?= h(preg_replace('/\s+/', '', (string) oficina('telefone'))) ?>"><?= h(oficina('telefone')) ?></a>
                </p>
            <?php endif; ?>
            <?php if ($temEmail): ?>
                <p>
                    Email:
                    <a class="ligacao-texto" href="mailto:<?= h(oficina('email')) ?>"><?= h(oficina('email')) ?></a>
                </p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<section class="bloco" aria-labelledby="titulo-como">
    <h2 class="bloco__titulo" id="titulo-como">Como chegar até nós</h2>

    <div class="prosa">
        <p>
            <strong>Antes de trazer o equipamento:</strong> descreve a avaria no
            pedido de orçamento e deixa um contacto. A oficina fala contigo por
            esse contacto.
        </p>
        <p>
            <strong>Para deixar o equipamento:</strong> vem ao balcão. A receção
            é feita contigo, com a descrição da avaria e o registo do estado — é
            nessa altura que recebes o talão com o PIN.
        </p>
        <p>
            <strong>Para saber como está:</strong> usa o PIN do talão na consulta
            pública. Não é preciso telefonar.
        </p>
        <p><?= h((string) $oficina['entrega']) ?></p>
    </div>
</section>

<section class="fecho">
    <p class="fecho__texto">Descreve a avaria e deixa um contacto. Respondemos por aí.</p>
    <a class="botao botao--primario botao--grande" href="orcamento.php">Pedir orçamento</a>
</section>

<?php
pagina_fim();
