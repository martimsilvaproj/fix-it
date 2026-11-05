/**
 * grafico-receita.js — a receita dos últimos 12 meses.
 *
 * Lê os números de `#dados-receita` (JSON escrito pelo PHP) e desenha um
 * gráfico de área com a linha do ano anterior por trás. As três vistas —
 * receita, pedidos e clientes — são o mesmo desenho com outra série: não
 * há gráficos escondidos, há um só que troca de dados.
 *
 * Decisões:
 *   • O gradiente nasce do token --azul-600 lido do CSS, e não de um
 *     hexadecimal escrito aqui: quando o tema muda, a área muda com ele.
 *   • A variação percentual vem calculada do PHP (`variacoes`), não é
 *     recalculada aqui — a mesma conta que os testes cobrem é a que a
 *     dica mostra.
 *   • A tabela em baixo do gráfico tem os mesmos valores: é o caminho de
 *     quem não vê o canvas, e o que fica quando o CDN do Chart.js falha.
 *   • O eixo dos valores encolhe à medida que os números crescem: passa a
 *     abreviado («4 mil»), desce o corpo da letra e deixa menos marcas,
 *     para a escala nunca comer a área do desenho. O valor exato não se
 *     perde — fica na dica, no resumo e na tabela.
 *   • Sem Chart.js (rede em baixo), o canvas esconde-se e aparece o aviso;
 *     a tabela continua lá.
 */
(function () {
    'use strict';

    var dadosEl = document.getElementById('dados-receita');
    var canvas = document.getElementById('grafico-receita');
    var aviso = document.getElementById('receita-falha');
    var totalEl = document.getElementById('receita-total');
    var notaEl = document.getElementById('receita-nota');

    if (!dadosEl || !canvas) {
        return;
    }

    var dados;

    try {
        dados = JSON.parse(dadosEl.textContent);
    } catch (erro) {
        return;
    }

    /** Mostra a falha e esconde o canvas: a tabela fica como fonte de verdade. */
    function falhar() {
        canvas.style.display = 'none';

        if (aviso) {
            aviso.hidden = false;
        }
    }

    if (dados.erro || typeof Chart === 'undefined') {
        falhar();

        return;
    }

    var VISTAS = {
        receita:  { rotulo: 'Receita',  unidade: 'receita'  },
        pedidos:  { rotulo: 'Pedidos',  unidade: 'pedidos'  },
        clientes: { rotulo: 'Clientes', unidade: 'clientes' }
    };

    var vista = 'receita';
    var grafico = null;

    /** Valor de um token de CSS, com reserva para browsers antigos. */
    function token(nome, reserva) {
        var valor = getComputedStyle(document.documentElement)
            .getPropertyValue(nome)
            .trim();

        return valor !== '' ? valor : reserva;
    }

    /**
     * A cor da barra segue a vista: azul na receita, laranja nos pedidos,
     * verde nos clientes. Vem dos tokens do tema, para a folha ser a única
     * a decidir cor — aqui não há um hexadecimal escrito à mão.
     */
    function corDaVista(qual) {
        var mapa = {
            receita:  ['--saas-azul', '#0d6efd'],
            pedidos:  ['--saas-laranja', '#f97316'],
            clientes: ['--saas-verde', '#10b981']
        };
        var par = mapa[qual] || mapa.receita;

        return token(par[0], par[1]);
    }

    /** #1A5FA0 + alfa → rgba(26, 95, 160, alfa). */
    function paraRgba(cor, alfa) {
        var hex = cor.replace('#', '');

        if (hex.length === 3) {
            hex = hex.split('').map(function (c) { return c + c; }).join('');
        }

        var numero = parseInt(hex, 16);

        if (isNaN(numero)) {
            return 'rgba(26, 95, 160, ' + alfa + ')';
        }

        return 'rgba(' + ((numero >> 16) & 255) + ', '
            + ((numero >> 8) & 255) + ', '
            + (numero & 255) + ', ' + alfa + ')';
    }

    var moeda = new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 2
    });

    var moedaEixo = new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 0
    });

    var contagem = new Intl.NumberFormat('pt-PT');

    var milhar = new Intl.NumberFormat('pt-PT', { maximumFractionDigits: 1 });

    /**
     * Um número grande, curto: «12,4 mil» e «1,2 M».
     *
     * O eixo tem de caber ao lado da área, e um «12 480» ocupa o dobro de
     * um «12 mil». A abreviação é só da escala: o valor exato vive na
     * dica, no resumo e na tabela.
     */
    function abreviar(valor) {
        var absoluto = Math.abs(valor);

        if (absoluto >= 1000000) {
            return milhar.format(valor / 1000000) + ' M';
        }

        if (absoluto >= 1000) {
            return milhar.format(valor / 1000) + ' mil';
        }

        return null;
    }

    /** O rótulo de uma marca do eixo, conforme a vista. */
    function rotuloEixo(valor) {
        var curto = abreviar(valor);

        if (curto !== null) {
            return vista === 'receita' ? curto + ' €' : curto;
        }

        return vista === 'receita' ? moedaEixo.format(valor) : contagem.format(valor);
    }

    /** Formata um valor conforme a vista. */
    function formatar(qual, valor) {
        if (qual === 'receita') {
            return moeda.format(valor);
        }

        return contagem.format(valor);
    }

    /** «+12,4%» ou «sem comparação». */
    function formatarVariacao(valor) {
        if (valor === null || typeof valor === 'undefined') {
            return '';
        }

        var sinal = valor > 0 ? '+' : '';

        return sinal + valor.toFixed(1).replace('.', ',') + '%';
    }

    /** As cores do tema atual: tudo o que o desenho precisa. */
    function paleta() {
        return {
            azul: token('--azul-600', '#1A5FA0'),
            suave: token('--tinta-400', '#7A8B9D'),
            texto: token('--tinta-500', '#5A6C80'),
            regua: token('--linha', '#E4E9F0'),
            traco: token('--traco', '#101C28'),
            tinta: token('--tinta-900', '#101C28'),
            fundo: token('--banda', '#101C28'),
            fundoTexto: token('--banda-texto', '#FFFFFF'),
            fundoSuave: token('--banda-suave', 'rgba(255,255,255,.75)'),
            mono: token('--fonte-mono', 'monospace')
        };
    }

    /** O gradiente da área, do azul da vista para transparente. */
    function gradiente(cor) {
        var altura = grafico ? grafico.height : (canvas.parentNode.clientHeight || 320);
        var ctx = canvas.getContext('2d');
        var g = ctx.createLinearGradient(0, 0, 0, altura);

        g.addColorStop(0, paraRgba(cor, 0.34));
        g.addColorStop(0.55, paraRgba(cor, 0.12));
        g.addColorStop(1, paraRgba(cor, 0));

        return g;
    }

    /** Atualiza o resumo grande por cima do gráfico. */
    function resumo(qual) {
        if (!totalEl || !notaEl) {
            return;
        }

        if (qual === 'receita') {
            totalEl.textContent = moeda.format(dados.totais.receita);
            notaEl.textContent = 'em 12 meses · ' + contagem.format(dados.totais.pedidos)
                + (dados.totais.pedidos === 1 ? ' pedido entregue' : ' pedidos entregues');

            return;
        }

        if (qual === 'pedidos') {
            totalEl.textContent = contagem.format(dados.totais.pedidos);
            notaEl.textContent = 'pedidos entregues em 12 meses · '
                + moeda.format(dados.totais.receita) + ' de receita';

            return;
        }

        totalEl.textContent = contagem.format(dados.totais.clientes);
        notaEl.textContent = 'clientes novos em 12 meses';
    }

    /** Aplica as cores do tema ao gráfico inteiro. */
    function pintar() {
        var cores = paleta();
        var ano = grafico.data.datasets[0];
        var anterior = grafico.data.datasets[1];

        ano.borderColor = corDaVista(vista);
        ano.backgroundColor = corDaVista(vista);
        ano.hoverBackgroundColor = corDaVista(vista);
        ano.pointHoverBackgroundColor = corDaVista(vista);
        ano.pointHoverBorderColor = cores.fundo;

        anterior.borderColor = cores.suave;
        anterior.pointHoverBorderColor = cores.suave;

        grafico.options.scales.x.ticks.color = cores.texto;
        grafico.options.scales.x.border.color = cores.traco;
        grafico.options.scales.y.ticks.color = cores.texto;
        grafico.options.scales.y.grid.color = cores.regua;
        grafico.options.scales.y.border.color = cores.traco;

        grafico.options.plugins.tooltip.backgroundColor = cores.fundo;
        grafico.options.plugins.tooltip.titleColor = cores.fundoTexto;
        grafico.options.plugins.tooltip.bodyColor = cores.fundoSuave;
        grafico.options.plugins.tooltip.footerColor = cores.fundoTexto;
        grafico.options.plugins.tooltip.borderColor = cores.traco;

        grafico.update('none');
    }

    /**
     * O eixo dos valores encolhe à medida que os números crescem.
     *
     * Quanto mais alto o pico, mais algarismos leva cada marca e mais
     * largura a escala rouba à área do gráfico. Aqui ela responde: os
     * números grandes passam a abreviados, o corpo da letra desce e ficam
     * menos marcas — o desenho inteiro continua visível. O limite é a
     * moldura: num ecrã estreito a escala aperta mais cedo, porque há
     * menos largura para dividir.
     */
    function ajustarEixo() {
        var series = grafico.data.datasets;
        var maximo = 0;

        for (var d = 0; d < series.length; d++) {
            for (var i = 0; i < series[d].data.length; i++) {
                var valor = Number(series[d].data[i]);

                if (!isNaN(valor) && valor > maximo) {
                    maximo = valor;
                }
            }
        }

        var algarismos = String(Math.round(maximo)).length;
        var comprimento = rotuloEixo(maximo).length;
        var largura = canvas.parentNode ? canvas.parentNode.clientWidth : 0;
        var tamanho = 11;
        var limite = 5;

        if (algarismos >= 5 || comprimento >= 6 || largura < 460) {
            tamanho = 10;
            limite = 4;
        }

        if (algarismos >= 7 || comprimento >= 9 || largura < 360) {
            tamanho = 9;
            limite = 3;
        }

        var eixo = grafico.options.scales.y.ticks;

        eixo.font.size = tamanho;
        eixo.maxTicksLimit = limite;
        eixo.padding = tamanho >= 11 ? 8 : 4;
    }

    var coresIniciais = paleta();

    grafico = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: dados.meses,
            datasets: [
                {
                    label: 'Este ano',
                    data: dados.atual.receita.slice(),

                    // Barra de 12 px de raio em cima e assente no eixo:
                    // `borderSkipped: false` impede o Chart.js de cortar
                    // os cantos de baixo. A espessura tem tecto para doze
                    // barras não virarem uma parede.
                    backgroundColor: corDaVista('receita'),
                    borderRadius: 12,
                    borderSkipped: false,
                    maxBarThickness: 26,
                    hoverBackgroundColor: corDaVista('receita')
                },
                {
                    // O ano anterior fica linha: uma barra ao lado da outra
                    // duplicava a largura de cada mês e escondia o mês em
                    // curso. Tracejado por cima das barras, lê-se como
                    // referência e não como mais um valor do mesmo ano.
                    type: 'line',
                    label: 'Ano anterior',
                    data: dados.anterior.receita.slice(),
                    borderColor: coresIniciais.suave,
                    borderWidth: 2,
                    borderDash: [6, 5],
                    fill: false,
                    tension: 0.35,
                    pointRadius: 0,
                    pointHoverRadius: 3,
                    pointHoverBackgroundColor: coresIniciais.suave,
                    pointHoverBorderColor: coresIniciais.fundo
                }
            ]
        },
        options: {
            responsive: true,

            // A proporção é da moldura, não do canvas: `.receita__tela` tem
            // `aspect-ratio` em CSS e muda no breakpoint dos 720 px. Assim a
            // área acompanha qualquer largura na mesma pintura, sem esperar
            // pelo ciclo interno do Chart.js (que aplicava a proporção
            // anterior e deixava o gráfico um passo atrás).
            maintainAspectRatio: false,

            interaction: {
                mode: 'index',
                intersect: false
            },

            scales: {
                x: {
                    grid: { display: false },
                    border: { color: coresIniciais.traco },
                    ticks: {
                        color: coresIniciais.texto,
                        font: { family: coresIniciais.mono, size: 11 },
                        maxRotation: 0,
                        autoSkipPadding: 12
                    }
                },
                y: {
                    beginAtZero: true,
                    border: { display: false },
                    grid: { color: coresIniciais.regua },
                    ticks: {
                        color: coresIniciais.texto,
                        font: { family: coresIniciais.mono, size: 11 },
                        maxTicksLimit: 5,

                        // Contagens não têm meios pedidos: sem isto o eixo
                        // dos pedidos e dos clientes escrevia «0,5».
                        precision: 0,

                        // O mesmo callback nas três vistas: a vista decide
                        // a unidade, a grandeza decide a abreviação. O corpo
                        // da letra e o número de marcas vêm do
                        // `ajustarEixo`, chamado a cada troca de vista.
                        callback: rotuloEixo
                    }
                }
            },

            plugins: {
                legend: { display: false },

                tooltip: {
                    backgroundColor: coresIniciais.fundo,
                    titleColor: coresIniciais.fundoTexto,
                    bodyColor: coresIniciais.fundoSuave,
                    footerColor: coresIniciais.fundoTexto,
                    borderColor: coresIniciais.traco,
                    borderWidth: 2,
                    cornerRadius: 0,
                    padding: 12,
                    displayColors: false,
                    titleFont: { family: coresIniciais.mono, size: 12, weight: '700' },
                    bodyFont: { size: 13 },
                    footerFont: { family: coresIniciais.mono, size: 11, weight: '600' },
                    callbacks: {
                        title: function (itens) {
                            return dados.longos[itens[0].dataIndex] || '';
                        },

                        // O valor exato da série que se está a ver.
                        label: function (contexto) {
                            return contexto.dataset.label + ': '
                                + formatar(vista, contexto.parsed.y);
                        },

                        // A variação em relação ao mês anterior — a conta
                        // vem feita do PHP, com o sinal e uma casa decimal.
                        footer: function (itens) {
                            if (!itens.length) {
                                return '';
                            }

                            var indice = itens[0].dataIndex;
                            var variacao = dados.variacoes[vista][indice];

                            if (variacao === null || typeof variacao === 'undefined') {
                                return 'Sem mês anterior para comparar';
                            }

                            return formatarVariacao(variacao) + ' vs mês anterior';
                        }
                    }
                }
            }
        }
    });

    ajustarEixo();

    /** Troca a vista: o mesmo gráfico, outra série. */
    function escolher(qual) {
        if (!VISTAS[qual]) {
            return;
        }

        vista = qual;

        grafico.data.datasets[0].data = dados.atual[qual].slice();
        grafico.data.datasets[1].data = dados.anterior[qual].slice();
        grafico.data.datasets[0].backgroundColor = corDaVista(qual);
        grafico.data.datasets[0].borderColor = corDaVista(qual);
        grafico.data.datasets[0].hoverBackgroundColor = corDaVista(qual);
        ajustarEixo();
        grafico.update();
        resumo(qual);

        var botoes = document.querySelectorAll('.receita__vista');

        for (var i = 0; i < botoes.length; i++) {
            botoes[i].setAttribute(
                'aria-pressed',
                botoes[i].getAttribute('data-vista') === qual ? 'true' : 'false'
            );
        }
    }

    var botoesVista = document.querySelectorAll('.receita__vista');

    for (var i = 0; i < botoesVista.length; i++) {
        botoesVista[i].addEventListener('click', function () {
            escolher(this.getAttribute('data-vista'));
        });
    }

    // O tema troca-se no <html>; o gráfico acompanha as cores sem ser
    // redesenhado de raiz (o gradiente é refeito, a posição não se perde).
    if (window.MutationObserver) {
        new MutationObserver(function () {
            pintar();
        }).observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-tema']
        });
    }

    // A proporção vive no CSS (`aspect-ratio` em `.receita__tela`), mas o
    // gradiente é medido em pixels do canvas: depois de uma mudança de
    // largura tem de ser refeito, ou a área fica curta em cima e comprida
    // em baixo. Só isto se recalcula no resize — a geometria não.
    var temporizador = null;

    window.addEventListener('resize', function () {
        window.clearTimeout(temporizador);

        temporizador = window.setTimeout(function () {
            ajustarEixo();
            grafico.data.datasets[0].backgroundColor = corDaVista(vista);
            grafico.update('none');
        }, 180);
    });

    resumo('receita');
}());
