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

// Impostazioni correnti
$codice_ditta = setting('GECOM codice ditta');
$conto_ricavo = setting('GECOM conto ricavo');
$mappa_iva = json_decode((string) setting('GECOM mappa IVA'), true);
$mappa_conti = json_decode((string) setting('GECOM mappa conti'), true);
$mappa_documenti = json_decode((string) setting('GECOM mappa documenti'), true);

// Tipi documento di vendita abilitati, con indicazione di mappatura
$tipi = $dbo->fetchArray('SELECT `co_tipi_documento`.`id`, `co_tipi_documento_lang`.`title` FROM `co_tipi_documento`
    LEFT JOIN `co_tipi_documento_lang` ON `co_tipi_documento_lang`.`id_record` = `co_tipi_documento`.`id` AND `co_tipi_documento_lang`.`id_lang` = 1
    WHERE `co_tipi_documento`.`dir` = \'entrata\' AND `co_tipi_documento`.`enabled` = 1
    ORDER BY `co_tipi_documento`.`id`');

// Stati documento selezionabili (default: documenti emessi/pagati)
$stati = $dbo->fetchArray('SELECT `co_stati_documento`.`id`, `co_stati_documento_lang`.`title` FROM `co_stati_documento`
    LEFT JOIN `co_stati_documento_lang` ON `co_stati_documento_lang`.`id_record` = `co_stati_documento`.`id` AND `co_stati_documento_lang`.`id_lang` = 1
    ORDER BY `co_stati_documento`.`id`');
$stati_default = ['Emessa', 'Parzialmente pagato', 'Pagato'];

$values_stati = [];
$value_stati_default = [];
foreach ($stati as $stato) {
    $values_stati[] = ['id' => $stato['id'], 'descrizione' => $stato['title']];
    if (in_array($stato['title'], $stati_default)) {
        $value_stati_default[] = $stato['id'];
    }
}

echo '
<div class="card card-primary">
    <div class="card-header">
        <h3 class="card-title">'.tr('Export GECOM (tracciato TRAF TeamSystem)').'</h3>
    </div>
    <div class="card-body">
        <p>'.tr('Esporta le fatture di vendita e le note di credito del periodo in un file TRAF importabile in GECOM Multi.').'</p>

        <form action="'.base_path_osm().'/actions.php" method="get" target="_blank">
            <input type="hidden" name="id_module" value="'.$id_module.'">
            <input type="hidden" name="op" value="mncs-export-gecom">

            <div class="row">
                <div class="col-md-3">
                    {[ "type": "date", "label": "'.tr('Data inizio').'", "required": "1", "name": "date_start", "value": "'.$_SESSION['period_start'].'" ]}
                </div>
                <div class="col-md-3">
                    {[ "type": "date", "label": "'.tr('Data fine').'", "required": "1", "name": "date_end", "value": "'.$_SESSION['period_end'].'" ]}
                </div>
                <div class="col-md-6">
                    {[ "type": "select", "label": "'.tr('Stati documento').'", "name": "stati[]", "multiple": "1", "required": "1", "values": '.json_encode($values_stati).', "value": "'.implode(',', $value_stati_default).'" ]}
                </div>
            </div>

            <div class="row">
                <div class="col-md-12 text-right">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-download"></i> '.tr('Esporta file TRAF').'
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>';

// Riepilogo configurazione: aiuta a compilare le mappe (sezione Impostazioni > Export GECOM)
echo '
<div class="card card-info collapsable">
    <div class="card-header">
        <h3 class="card-title">'.tr('Configurazione').'</h3>
    </div>
    <div class="card-body">
        <p>'.tr('Le mappature si modificano in _LINK_, sezione "Export GECOM".', [
            '_LINK_' => Modules::link('Impostazioni', null, tr('Impostazioni')),
        ]).'</p>
        <ul>
            <li>'.tr('Codice ditta').': <strong>'.$codice_ditta.'</strong></li>
            <li>'.tr('Conto ricavo di fallback').': <strong>'.$conto_ricavo.'</strong></li>
            <li>'.tr('Aliquote IVA mappate').': <strong>'.count((array) $mappa_iva).'</strong></li>
            <li>'.tr('Conti mappati').': <strong>'.count((array) $mappa_conti).'</strong></li>
        </ul>

        <h5>'.tr('Tipi documento di vendita abilitati').'</h5>
        <table class="table table-sm table-striped" style="max-width: 720px;">
            <thead>
                <tr><th>'.tr('ID').'</th><th>'.tr('Tipo documento').'</th><th>'.tr('Export').'</th></tr>
            </thead>
            <tbody>';

foreach ($tipi as $tipo) {
    $mappato = isset($mappa_documenti[$tipo['id']]);
    $config = $mappato ? $mappa_documenti[$tipo['id']] : null;

    echo '
                <tr>
                    <td>'.$tipo['id'].'</td>
                    <td>'.$tipo['title'].'</td>
                    <td>'.($mappato
                        ? '<span class="badge badge-success">'.tr('Incluso').'</span> '.tr('causale').' '.($config['causale'] ?? '?').', '.tr('sezionale').' '.($config['sezionale'] ?? '?')
                        : '<span class="badge badge-secondary">'.tr('Escluso').'</span>').'</td>
                </tr>';
}

echo '
            </tbody>
        </table>
        <p class="text-muted">'.tr('Solo i tipi documento presenti nella "GECOM mappa documenti" vengono esportati.').'</p>
    </div>
</div>';
