/* =====================================================================
   A gaveta do menu.
   Estava copiada em seis páginas, com 152 linhas cada: uma correcção
   exigia seis edições e a página que ficasse de fora perdia o menu.
   Agora vive aqui e o layout-painel.php serve-a a todas.
   ===================================================================== */
/* =====================================================================
   Menu lateral em ecrãs pequenos.
   Em desktop a sidebar é fixa e o botão não existe (CSS esconde-o).
   ===================================================================== */
(function () {
    "use strict";

    var corpo   = document.body;
    var sidebar = document.getElementById("sidebar");
    var abrir   = document.getElementById("abrir-nav");
    var fechar  = document.getElementById("fechar-nav");
    var cortina = document.getElementById("cortina");
    var ecraGrande = window.matchMedia("(min-width: 1024px)");

    function estaAberto() {
        return corpo.classList.contains("nav-aberto");
    }

    function abrirMenu() {
        corpo.classList.add("nav-aberto");
        sidebar.removeAttribute("inert");
        abrir.setAttribute("aria-expanded", "true");

        var primeiroItem = sidebar.querySelector(".navegacao__ligacao");
        if (primeiroItem) {
            primeiroItem.focus({ preventScroll: true });
        }
    }

    function fecharMenu(devolverFoco) {
        corpo.classList.remove("nav-aberto");
        abrir.setAttribute("aria-expanded", "false");

        if (!ecraGrande.matches) {
            sidebar.setAttribute("inert", "");
        }

        if (devolverFoco) {
            abrir.focus({ preventScroll: true });
        }
    }

    /* Quanto tempo a gaveta leva a sair. Lido do CSS em vez de repetido
       aqui: com movimento reduzido a transição é quase nula, e a espera
       também tem de ser. */
    function duracaoDaSaida() {
        var valores = String(window.getComputedStyle(sidebar).transitionDuration || "0s").split(",");
        var maior = 0;

        valores.forEach(function (valor) {
            var numero = parseFloat(valor) || 0;

            if (valor.indexOf("ms") > -1) {
                numero = numero / 1000;
            }

            maior = Math.max(maior, numero);
        });

        return maior * 1000;
    }

    /* Tocar numa ligação com a gaveta aberta: a gaveta fecha primeiro, com a
       animação que já tem, e só depois a página nova entra. Sem esta espera o
       toque trocava de documento no mesmo fotograma e o menu desaparecia de
       repente — a travessia entre páginas não pinta a gaveta no telemóvel,
       por isso não havia nada para ver a sair. */
    function navegarDepoisDeFechar(destino) {
        /* O destino já está escolhido: a gaveta sai mais depressa do que no
           fecho normal. Sem isto, o toque esperava a travessia inteira da
           gaveta antes de a página nova começar a carregar, e a navegação
           parecia lenta. A classe só vale neste caminho — fechar a gaveta
           sem sair da página mantém a animação de sempre. */
        corpo.classList.add("nav-a-navegar");

        var seguiu = false;

        function seguir() {
            if (seguiu) {
                return;
            }

            seguiu = true;
            sidebar.removeEventListener("transitionend", aoTerminar);
            window.location.href = destino;
        }

        /* A transição do `transform` é a da gaveta a sair; a da cortina é
           mais curta e chegaria primeiro. */
        function aoTerminar(evento) {
            if (evento.target === sidebar && evento.propertyName === "transform") {
                seguir();
            }
        }

        sidebar.addEventListener("transitionend", aoTerminar);
        fecharMenu(false);

        /* Rede de segurança: se o `transitionend` não vier — gaveta já fora do
           ecrã, transição saltada —, a página nova entra mesmo assim. */
        window.setTimeout(seguir, duracaoDaSaida() + 80);
    }

    /* Mantém o estado coerente ao rodar o ecrã ou redimensionar. */
    function sincronizar() {
        if (ecraGrande.matches) {
            corpo.classList.remove("nav-aberto");
            sidebar.removeAttribute("inert");
            abrir.setAttribute("aria-expanded", "false");
        } else if (!estaAberto()) {
            sidebar.setAttribute("inert", "");
        }
    }

    abrir.addEventListener("click", abrirMenu);
    fechar.addEventListener("click", function () { fecharMenu(true); });
    cortina.addEventListener("click", function () { fecharMenu(true); });

    /* A gaveta não desaparece debaixo do dedo: a ligação espera que o menu
       saia. Ctrl/⌘, botão do meio, outra aba e descarregamentos seguem sem
       espera — são do browser, não desta página. */
    sidebar.addEventListener("click", function (evento) {
        var ligacao = evento.target.closest ? evento.target.closest("a[href]") : null;

        if (!ligacao || !estaAberto() || ecraGrande.matches ||
                evento.defaultPrevented || evento.button !== 0 ||
                evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey ||
                (ligacao.target && ligacao.target !== "_self") ||
                ligacao.hasAttribute("download")) {
            return;
        }

        evento.preventDefault();
        navegarDepoisDeFechar(ligacao.href);
    });

    document.addEventListener("keydown", function (evento) {
        if (evento.key === "Escape" && estaAberto()) {
            fecharMenu(true);
        }
    });

    if (typeof ecraGrande.addEventListener === "function") {
        ecraGrande.addEventListener("change", sincronizar);
    } else {
        ecraGrande.addListener(sincronizar);
    }

    sincronizar();
})();

