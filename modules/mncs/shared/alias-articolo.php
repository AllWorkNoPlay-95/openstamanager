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

// Helper custom MNCS: individuazione articolo dal codice fornitore tramite il campo "Alias".
//
// L'Alias (campo personalizzato `mncs_alias`, vedi modules/mncs/update/1_6.sql) ha formato
// `PPPP-<codice fornitore>`: i primi 5 caratteri (prefisso + "-") si saltano e il resto è il
// codice articolo del fornitore. Il prefisso non viene confrontato. Il nostro mg_articoli.codice
// (9 cifre casuali) non va mai confrontato col codice fornitore.
// Usato dall'import FE (plugins/importFE/generate.php e actions.php 'riferimenti-automatici').

if (!function_exists('mncs_articolo_da_alias')) {
    /**
     * Ritorna l'id dell'articolo il cui Alias (senza i primi 5 caratteri) coincide col codice.
     *
     * @param string|null $codice codice articolo del fornitore (CodiceValore della FE)
     *
     * @return int|null id articolo, null se nessun match o se il match è ambiguo (più articoli)
     */
    function mncs_articolo_da_alias($codice): ?int
    {
        $codice = trim((string) $codice);
        if ($codice === '') {
            return null;
        }

        $risultati = database()->fetchArray('SELECT `zz_field_record`.`id_record` AS id
            FROM `zz_field_record`
                INNER JOIN `zz_fields` ON `zz_fields`.`id` = `zz_field_record`.`id_field`
                INNER JOIN `mg_articoli` ON `mg_articoli`.`id` = `zz_field_record`.`id_record`
            WHERE `zz_fields`.`html_name` = '.prepare('mncs_alias').'
                AND SUBSTRING(`zz_field_record`.`value`, 6) = '.prepare($codice).'
                AND `mg_articoli`.`deleted_at` IS NULL
            LIMIT 2');

        // Match ambiguo: nessuna scelta automatica, l'articolo si seleziona a mano.
        return count($risultati) === 1 ? (int) $risultati[0]['id'] : null;
    }
}
