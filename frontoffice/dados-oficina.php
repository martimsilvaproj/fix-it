<?php
declare(strict_types=1);

/**
 * dados-oficina.php — a única fonte de verdade factual do site público.
 *
 * Regra: se um valor não é conhecido, fica `null`. As páginas que o usam
 * omitem o bloco em vez de mostrarem um vazio ou um «a confirmar». Nada
 * desta lista volta a ser escrito à mão no HTML.
 *
 * O que aqui NÃO entra: preços, prazos de reparação, garantias, anos de
 * existência, número de reparações, avaliações. Esses factos não existem
 * e o site não os promete (ver PRODUCT.md).
 */

$oficina = [
    'nome'      => 'FixIt',
    'descritor' => 'Assistência técnica',

    // Oferta confirmada. Cada entrada descreve o processo, não uma
    // capacidade que não esteja verificada no balcão.
    'oferta' => [
        [
            'tipo' => 'Portáteis',
            'nota' => 'Trazes o equipamento ao balcão, descrevemos a avaria contigo e o orçamento é comunicado antes de qualquer reparação.',
        ],
        [
            'tipo' => 'Desktops',
            'nota' => 'O mesmo percurso: receção com fotografias do estado, diagnóstico e orçamento antes de mexer.',
        ],
        [
            'tipo' => 'Telemóveis',
            'nota' => 'Smartphones entram com o PIN do talão, como qualquer outro equipamento.',
        ],
        [
            'tipo' => 'Tablets',
            'nota' => 'Recebidos no balcão e acompanhados pelas mesmas cinco fases.',
        ],
    ],

    // A oficina é local: o cliente traz e levanta o equipamento.
    'entrega' => 'O equipamento é entregue e levantado no balcão. Não há recolha nem envio.',

    // --- Por preencher. null = o bloco desaparece da página. -------------
    'morada'   => null,
    'telefone' => null,
    'email'    => null,
    'horario'  => null,
    'mapa'     => null,

    // --- Ausentes por decisão. Não mostrar, não sugerir. -----------------
    'precos'   => null,
    'prazos'   => null,
    'garantia' => null,
];
