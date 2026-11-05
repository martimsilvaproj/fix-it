/**
 * entrada.js — o botão «Mostrar» da palavra-passe.
 *
 * Três coisas pequenas, todas de quem está a entrar:
 *   • o botão «Mostrar» troca o tipo do campo entre `password` e `text`
 *     sem perder o que já está escrito, dizendo ao leitor de ecrã o que
 *     ele faz (`aria-pressed`) e voltando a pôr o cursor no fim;
 *   • o aviso de Caps Lock, que só aparece enquanto a tecla está ligada e
 *     o campo da palavra-passe tem o foco;
 *   • o cursor começa no email no ecrã grande — no telemóvel não, que
 *     abrir o teclado por cima da faixa da marca escondia a página.
 *
 * Sem JavaScript o campo continua a funcionar: fica escondido, como
 * sempre esteve.
 */
(function () {
    'use strict';

    var botao = document.querySelector('[data-alternar-palavra-passe]');
    var campo = document.getElementById('palavra_passe');

    if (!botao || !campo) {
        return;
    }

    botao.addEventListener('click', function () {
        var visivel = campo.type === 'text';

        campo.type = visivel ? 'password' : 'text';
        botao.setAttribute('aria-pressed', visivel ? 'false' : 'true');
        botao.textContent = visivel ? 'Mostrar' : 'Ocultar';

        campo.focus({ preventScroll: true });

        if (!visivel) {
            try {
                campo.setSelectionRange(campo.value.length, campo.value.length);
            } catch (erro) {
                /* Alguns browsers não deixam mexer na seleção: fica o foco. */
            }
        }
    });

    var avisoCaps = document.getElementById('aviso-caps');

    if (avisoCaps) {
        var verificarCaps = function (evento) {
            var ligado = typeof evento.getModifierState === 'function'
                && evento.getModifierState('CapsLock');

            avisoCaps.hidden = !ligado;
        };

        campo.addEventListener('keydown', verificarCaps);
        campo.addEventListener('keyup', verificarCaps);
        campo.addEventListener('blur', function () {
            avisoCaps.hidden = true;
        });
    }

    var email = document.getElementById('email');

    if (email && window.matchMedia && window.matchMedia('(min-width: 900px)').matches) {
        email.focus({ preventScroll: true });
    }
}());
