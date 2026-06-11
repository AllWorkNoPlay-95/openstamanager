/*
 * mncs [CUSTOM]: nasconde la colonna "Costo unitario" nelle Fatture di vendita.
 *
 * Scelte:
 * - Agisce SOLO sul modulo Fatture di vendita: gate sull'<h1> dentro
 *   .content-header (le Fatture di acquisto non hanno nemmeno questa colonna).
 * - Indice colonna individuato a RUNTIME dal testo dell'header: resta corretto
 *   anche se un merge upstream cambia l'ordine delle colonne.
 * - Nasconde via CSS (display:none) header + celle del solo tbody#righe, così
 *   la regola sopravvive ai re-render ajax delle righe e non tocca le righe
 *   dei totali (che usano colspan e stanno fuori da tbody#righe).
 */
(function () {
    var header = document.querySelector('.content-header h1');
    if (!header || header.textContent.indexOf('Fatture di vendita') === -1) {
        return;
    }

    var LABEL = 'Costo unitario';
    var STYLE_ID = 'mncs-hide-costo-unitario';

    function applica() {
        // Già applicato: niente da fare.
        if (document.getElementById(STYLE_ID)) {
            return true;
        }

        var table = document.querySelector('.row-list table');
        if (!table) {
            return false; // tabella non ancora nel DOM (caricamento ajax)
        }

        var ths = table.querySelectorAll('thead > tr > th');
        var indice = -1;
        for (var i = 0; i < ths.length; i++) {
            if (ths[i].textContent.trim().indexOf(LABEL) !== -1) {
                indice = i + 1; // nth-child è 1-based
                break;
            }
        }
        if (indice === -1) {
            return true; // colonna assente: nulla da nascondere, smetti di osservare
        }

        var css =
            '.row-list table thead > tr > th:nth-child(' + indice + '),' +
            '.row-list table tbody#righe > tr > td:nth-child(' + indice + ')' +
            '{ display: none !important; }';

        var style = document.createElement('style');
        style.id = STYLE_ID;
        style.appendChild(document.createTextNode(css));
        document.head.appendChild(style);
        return true;
    }

    // Le righe documento vengono caricate/ri-renderizzate via ajax: prova
    // subito e, se la tabella non c'è ancora, osserva il DOM finché compare.
    if (!applica()) {
        var observer = new MutationObserver(function () {
            if (applica()) {
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }
})();
