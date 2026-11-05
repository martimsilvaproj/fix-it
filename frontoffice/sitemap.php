<?php
declare(strict_types=1);

/**
 * sitemap.php — mapa do site público.
 *
 * Dinâmico e não um `sitemap.xml` fixo: os endereços são construídos a
 * partir do pedido actual, porque escrever um domínio à mão seria
 * inventar um facto que o projecto não tem. Depois de publicar, é este
 * endereço que entra no robots.txt.
 *
 * A consulta por PIN e o formulário de orçamento ficam de fora: são
 * páginas pessoais e pós-envio, não conteúdo.
 */

require_once __DIR__ . '/front-comum.php';

header('Content-Type: application/xml; charset=utf-8');

$base = site_base();

if ($base === '') {
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><error>Host desconhecido.</error>';
    exit;
}

$paginas = [
    ['index.php', '1.0'],
    ['servicos.php', '0.9'],
    ['como-funciona.php', '0.8'],
    ['orcamento.php', '0.8'],
    ['perguntas.php', '0.7'],
    ['contacto.php', '0.7'],
    ['privacidade.php', '0.3'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($paginas as [$ficheiro, $prioridade]): ?>
    <url>
        <loc><?= h($base . $ficheiro) ?></loc>
        <changefreq>monthly</changefreq>
        <priority><?= h($prioridade) ?></priority>
    </url>
<?php endforeach; ?>
</urlset>
