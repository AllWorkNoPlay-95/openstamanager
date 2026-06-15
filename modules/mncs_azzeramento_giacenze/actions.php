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

switch (filter('op')) {
    case 'genera-csv':
        // Cartella di destinazione (creata se assente: l'utente web deve potervi scrivere).
        $dir_rel = 'files/mncs_azzeramento_giacenze';
        $abs_dir = base_dir().'/'.$dir_rel;
        if (!is_dir($abs_dir)) {
            mkdir($abs_dir, 0777, true);
        }
        $file_rel = $dir_rel.'/azzeramento-giacenze.csv';
        $filepath = base_dir().'/'.$file_rel;

        // Articoli con giacenza != 0 nelle sedi fisiche (1 = Feroleto, 0 = Rende/Sede legale).
        // Il CSV li porta a 0 tramite l'importer "Giacenze magazzino" (0 = azzera).
        $rows = database()->fetchArray(
            'SELECT `a`.`codice` AS `codice`
             FROM `mg_articoli` `a`
             INNER JOIN `mg_movimenti` `m` ON `m`.`id_articolo` = `a`.`id`
             WHERE `m`.`id_sede` IN (0, 1)
               AND `a`.`deleted_at` IS NULL
               AND `a`.`codice` IS NOT NULL AND `a`.`codice` <> \'\'
             GROUP BY `a`.`id`, `a`.`codice`
             HAVING SUM(CASE WHEN `m`.`id_sede` = 1 THEN `m`.`qta` ELSE 0 END) <> 0
                 OR SUM(CASE WHEN `m`.`id_sede` = 0 THEN `m`.`qta` ELSE 0 END) <> 0
             ORDER BY `a`.`codice`'
        );

        $file = fopen($filepath, 'w');
        fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($file, ['Codice', 'Qta Feroleto', 'Qta Rende'], ';', escape: '\\');
        foreach ($rows as $row) {
            fputcsv($file, [$row['codice'], '0', '0'], ';', escape: '\\');
        }
        fclose($file);

        echo base_path_osm().'/'.$file_rel;
        exit;
}
