/**
 * tema.js — a luz da oficina.
 *
 * O tema não é uma preferência da conta: é deste browser. Quem abre o
 * painel no computador da receção pode trabalhar em «tinta» sem mudar o
 * ecrã de quem está ao lado, e um portátil deixa de ser escuro no dia
 * seguinte por causa de uma escolha feita uma vez.
 *
 * O atributo `data-tema` no <html> é escrito antes do primeiro desenho por
 * um script no <head> (ver layout-painel.php e login.php): sem isso, a
 * página aparecia clara e só depois escurecia.
 *
 * Este ficheiro só trata do botão: manter o `aria-pressed` verdadeiro, o
 * rótulo acessível e a memória. O desenho vive no CSS, no bloco 18.
 */
(function () {
    'use strict';

    var CHAVE = 'fixit-tema';
    var TINTA = 'tinta';
    var PAPEL = 'papel';
    var raiz = document.documentElement;
    var botoes = document.querySelectorAll('[data-alternar-tema]');

    /** O tema escuro está ligado? */
    function eTinta() {
        return raiz.getAttribute('data-tema') === TINTA;
    }

    /** Aplica o tema ao documento, à barra do browser e aos botões. */
    function pintar(tinta) {
        raiz.setAttribute('data-tema', tinta ? TINTA : PAPEL);

        // A barra do sistema acompanha a cor do papel.
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) {
            meta.setAttribute('content', tinta ? '#0B1622' : '#0C2038');
        }

        for (var i = 0; i < botoes.length; i++) {
            botoes[i].setAttribute('aria-pressed', tinta ? 'true' : 'false');
            botoes[i].setAttribute(
                'aria-label',
                tinta ? 'Tema claro (papel)' : 'Tema escuro (tinta)'
            );
        }
    }

    // O botão pode ser desenhado antes deste ficheiro correr (defer): o
    // estado dos atributos põe-se já, para o ícone não aparecer trocado.
    pintar(eTinta());

    for (var j = 0; j < botoes.length; j++) {
        botoes[j].addEventListener('click', function () {
            var tinta = !eTinta();
            pintar(tinta);

            // Sem armazenamento (modo privado antigo, cookies desligados) a
            // escolha vale só para esta página — e ninguém fica sem botão.
            try {
                window.localStorage.setItem(CHAVE, tinta ? TINTA : PAPEL);
            } catch (erro) {
                /* nada a fazer: o tema já está aplicado */
            }
        });
    }
}());
