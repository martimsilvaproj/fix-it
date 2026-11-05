/**
 * entrada.js — o botão «Mostrar» da palavra-passe.
 *
 * Um só trabalho: trocar o tipo do campo entre `password` e `text` sem
 * perder o que já está escrito, dizendo ao leitor de ecrã o que o botão
 * faz (`aria-pressed`) e voltando a pôr o cursor no fim do valor.
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
}());
