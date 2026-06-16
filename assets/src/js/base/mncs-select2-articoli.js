/*
 * MNCS — Indicatore Elasticsearch nel footer del dropdown ricerca articoli (select2).
 *
 * Solo listener globali, nessun override del core (select.js). La risposta AJAX degli articoli
 * include in ogni riga un marker nascosto `<i class="mncs-art-es" data-es="1|0">` che indica se la
 * ricerca è stata servita da Elasticsearch. Qui:
 *  - si aggiunge la classe di scoping per allargare il dropdown articoli;
 *  - si inietta il footer SOLO quando ES è attivo (data-es="1"); se non disponibile niente footer.
 */
(function () {
    'use strict';

    var ARTICLE_SOURCES = ['articoli'];
    var observer = null;

    function isArticleSelect(target) {
        return ARTICLE_SOURCES.indexOf(window.jQuery(target).data('source')) !== -1;
    }

    function footerHtml() {
        return '<span class="mncs-es-left"><i class="fa fa-bolt text-success"></i> Ricerca avanzata ElasticSearch attiva</span>'
            + '<span class="mncs-es-right small text-muted">Powered by K-Odin</span>';
    }

    function disconnectObserver() {
        if (observer) {
            observer.disconnect();
            observer = null;
        }
    }

    window.jQuery(function ($) {
        $(document).on('select2:open', function (e) {
            if (!isArticleSelect(e.target)) {
                return;
            }

            // Il dropdown aperto è già nel DOM: lo individuiamo come unico .select2-dropdown.
            setTimeout(function () {
                var $dd = $('.select2-dropdown');
                if (!$dd.length) {
                    return;
                }
                $dd.addClass('mncs-art-dd');

                // Footer mostrato SOLO quando una ricerca è servita da ES (data-es="1").
                // Nessun marker (0 risultati) => nessuna modifica (niente flash).
                function refresh() {
                    var $marker = $dd.find('.mncs-art-es[data-es]').first();
                    if (!$marker.length) {
                        return;
                    }
                    var active = $marker.attr('data-es') === '1';
                    var $footer = $dd.find('.mncs-es-footer');
                    if (active) {
                        if (!$footer.length) {
                            $footer = $('<div class="mncs-es-footer"></div>').appendTo($dd);
                        }
                        $footer.html(footerHtml());
                    } else {
                        $footer.remove();
                    }
                }

                refresh();

                var results = $dd.find('.select2-results').get(0);
                disconnectObserver();
                if (results && window.MutationObserver) {
                    observer = new MutationObserver(refresh);
                    observer.observe(results, { childList: true, subtree: true });
                }
            }, 0);
        });

        $(document).on('select2:close', function (e) {
            if (!isArticleSelect(e.target)) {
                return;
            }
            disconnectObserver();
        });
    });
})();
