<?php

/*
 * OpenSTAManager: il software gestionale open source per l'assistenza tecnica e la fatturazione
 * Copyright (C) DevCode s.r.l.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

include_once __DIR__.'/../../core.php';

require_once __DIR__.'/TrafBuilder.php';

use Modules\MncsExportGecom\TrafBuilder;

switch (filter('op')) {
    case 'mncs-export-gecom':
        $date_start = get('date_start');
        $date_end = get('date_end');
        $stati = array_filter((array) get('stati'), 'is_numeric');

        $redirect = base_path_osm().'/controller.php?id_module='.$id_module;

        if (empty($date_start) || empty($date_end) || strtotime($date_start) > strtotime($date_end) || empty($stati)) {
            flash()->error(tr('Periodo o stati non validi.'));
            redirect_url($redirect);
            exit;
        }

        // Impostazioni e mappe
        $codice_ditta = setting('GECOM codice ditta');
        $conto_ricavo_default = setting('GECOM conto ricavo');
        $mappa_iva = (array) json_decode((string) setting('GECOM mappa IVA'), true);
        $mappa_conti = (array) json_decode((string) setting('GECOM mappa conti'), true);
        $mappa_documenti = (array) json_decode((string) setting('GECOM mappa documenti'), true);

        if (empty($mappa_documenti)) {
            flash()->error(tr('Nessun tipo documento mappato: compilare "GECOM mappa documenti" nelle Impostazioni.'));
            redirect_url($redirect);
            exit;
        }
        if (!preg_match('/^\d{7}$/', (string) $conto_ricavo_default)) {
            flash()->error(tr('"GECOM conto ricavo" non valido: atteso un conto a 7 cifre.'));
            redirect_url($redirect);
            exit;
        }

        $id_tipi = array_filter(array_keys($mappa_documenti), 'is_numeric');

        // Documenti del periodo (solo tipi mappati e stati selezionati)
        $documenti = $dbo->fetchArray('SELECT `co_documenti`.`id`, `co_documenti`.`data`, `co_documenti`.`numero`, `co_documenti`.`numero_esterno`,
                `co_documenti`.`id_tipo_documento`, `co_documenti`.`ref_documento`,
                `an_anagrafiche`.`ragione_sociale`, `an_anagrafiche`.`indirizzo`, `an_anagrafiche`.`cap`, `an_anagrafiche`.`citta`,
                `an_anagrafiche`.`provincia`, `an_anagrafiche`.`codice_fiscale`, `an_anagrafiche`.`p_iva`, `an_anagrafiche`.`telefono`, `an_anagrafiche`.`tipo` AS tipo_anagrafica
            FROM `co_documenti`
                INNER JOIN `an_anagrafiche` ON `an_anagrafiche`.`id` = `co_documenti`.`id_anagrafica`
            WHERE `co_documenti`.`id_tipo_documento` IN ('.implode(',', $id_tipi).')
                AND `co_documenti`.`id_stato` IN ('.implode(',', $stati).')
                AND `co_documenti`.`data` >= '.prepare($date_start).' AND `co_documenti`.`data` <= '.prepare($date_end).'
            ORDER BY `co_documenti`.`data`, `co_documenti`.`numero_esterno`');

        if (empty($documenti)) {
            flash()->warning(tr('Nessun documento nel periodo selezionato per i tipi mappati.'));
            redirect_url($redirect);
            exit;
        }

        $errori = [];
        $docs_traf = [];

        foreach ($documenti as $documento) {
            $config = $mappa_documenti[$documento['id_tipo_documento']];
            $numero_descrittivo = !empty($documento['numero_esterno']) ? $documento['numero_esterno'] : (string) $documento['numero'];
            $etichetta = $numero_descrittivo.' del '.date('d/m/Y', strtotime($documento['data']));

            // Numero documento: parte numerica del numero (es. "123/FE" -> 123)
            if (!preg_match('/^(\d{1,6})/', $numero_descrittivo, $m)) {
                $errori[] = tr('Documento _DOC_: numero "_NUM_" non interpretabile.', ['_DOC_' => $etichetta, '_NUM_' => $numero_descrittivo]);
                continue;
            }
            $numero = (int) $m[1];

            // Castelletto IVA: aggregato per aliquota
            $righe_iva = $dbo->fetchArray('SELECT `id_iva`, ROUND(SUM(`subtotale` - `sconto`), 2) AS imponibile, ROUND(SUM(`iva`), 2) AS imposta
                FROM `co_righe_documenti` WHERE `id_documento` ='.prepare($documento['id']).' GROUP BY `id_iva`');

            $castelletto = [];
            $totale = 0;
            foreach ($righe_iva as $gruppo) {
                if (!isset($mappa_iva[$gruppo['id_iva']])) {
                    $errori[] = tr('Documento _DOC_: aliquota IVA id _IVA_ non presente nella mappa IVA.', ['_DOC_' => $etichetta, '_IVA_' => $gruppo['id_iva']]);
                    continue 2;
                }
                $imponibile = (int) round($gruppo['imponibile'] * 100);
                $imposta = (int) round($gruppo['imposta'] * 100);
                if ($imponibile < 0 || $imposta < 0) {
                    $errori[] = tr('Documento _DOC_: gruppo IVA con importo negativo, non supportato dal tracciato.', ['_DOC_' => $etichetta]);
                    continue 2;
                }
                $castelletto[] = [
                    'imponibile' => $imponibile,
                    'codice_iva' => (string) $mappa_iva[$gruppo['id_iva']],
                    'imposta' => $imposta,
                ];
                $totale += $imponibile + $imposta;
            }
            if (empty($castelletto)) {
                $errori[] = tr('Documento _DOC_: nessuna riga con IVA.', ['_DOC_' => $etichetta]);
                continue;
            }

            // Contropartite ricavo: aggregato per conto OSM, mappato sui conti GECOM
            $righe_conto = $dbo->fetchArray('SELECT `id_conto`, ROUND(SUM(`subtotale` - `sconto`), 2) AS importo
                FROM `co_righe_documenti` WHERE `id_documento` ='.prepare($documento['id']).' GROUP BY `id_conto`');

            $per_conto = [];
            foreach ($righe_conto as $gruppo) {
                $conto = $mappa_conti[$gruppo['id_conto']] ?? $conto_ricavo_default;
                $per_conto[$conto] = ($per_conto[$conto] ?? 0) + (int) round($gruppo['importo'] * 100);
            }

            // Allinea la somma delle contropartite alla somma degli imponibili del castelletto
            // (le due aggregazioni arrotondano gruppi diversi delle stesse righe)
            $imponibile_castelletto = array_sum(array_column($castelletto, 'imponibile'));
            $delta = $imponibile_castelletto - array_sum($per_conto);
            if ($delta != 0 && !empty($per_conto)) {
                arsort($per_conto);
                $per_conto[array_key_first($per_conto)] += $delta;
            }

            $contropartite = [];
            foreach ($per_conto as $conto => $importo) {
                $contropartite[] = ['conto' => (string) $conto, 'importo' => $importo];
            }

            // Nota di credito: estremi della fattura originaria (record tipo 5)
            $riferimento = null;
            if (!empty($config['nota_credito']) && !empty($documento['ref_documento'])) {
                $originale = $dbo->fetchOne('SELECT `numero`, `numero_esterno`, `data` FROM `co_documenti` WHERE `id` = '.prepare($documento['ref_documento']));
                if ($originale) {
                    $numero_originale = !empty($originale['numero_esterno']) ? $originale['numero_esterno'] : (string) $originale['numero'];
                    if (preg_match('/^(\d{1,6})/', $numero_originale, $m)) {
                        $riferimento = ['numero' => (int) $m[1], 'data' => $originale['data']];
                    }
                }
            }

            $persona_fisica = $documento['tipo_anagrafica'] == 'Privato'
                || preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/i', (string) $documento['codice_fiscale']);

            $docs_traf[] = [
                'ragione_sociale' => $documento['ragione_sociale'],
                'indirizzo' => $documento['indirizzo'],
                'cap' => $documento['cap'],
                'citta' => $documento['citta'],
                'provincia' => $documento['provincia'],
                'codice_fiscale' => $documento['codice_fiscale'],
                'partita_iva' => $documento['p_iva'],
                'persona_fisica' => $persona_fisica,
                'telefono' => $documento['telefono'],
                'causale' => (string) $config['causale'],
                'causale_descrizione' => (string) $config['descrizione'],
                'sezionale' => (string) $config['sezionale'],
                'data' => $documento['data'],
                'numero' => $numero,
                'anno' => (int) date('Y', strtotime($documento['data'])),
                'numero_descrittivo' => $numero_descrittivo,
                'castelletto' => $castelletto,
                'contropartite' => $contropartite,
                'totale' => $totale,
                'riferimento' => $riferimento,
            ];
        }

        if (!empty($errori)) {
            flash()->error(tr('Export annullato, correggere i seguenti documenti:').'<br>'.implode('<br>', array_slice($errori, 0, 20)));
            redirect_url($redirect);
            exit;
        }

        try {
            $builder = new TrafBuilder((string) $codice_ditta, $date_end);
            $contenuto = $builder->build($docs_traf);
        } catch (UnexpectedValueException $e) {
            flash()->error(tr('Errore nella generazione del file TRAF: _MSG_', ['_MSG_' => $e->getMessage()]));
            redirect_url($redirect);
            exit;
        }

        // Download in-memory (stesso pattern dell'export XML LIPE)
        $filename = 'TRAF';
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.strlen($contenuto));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        echo $contenuto;
        exit;
}
