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

echo '
<div class="box box-warning">
    <div class="box-header with-border">
        <h3 class="box-title"><i class="fa fa-eraser"></i> '.tr('Azzeramento giacenze').'</h3>
    </div>
    <div class="box-body">
        <div class="alert alert-warning">
            <p>'.tr('Scarica un CSV che porta a <b>0</b> le giacenze di tutti gli articoli che hanno stock in OpenSTAManager (sedi Feroleto e Rende). Caricalo poi in <b>Strumenti &rarr; Importa &rarr; "Giacenze magazzino"</b> per fare piazza pulita prima di un nuovo inventario.').'</p>
            <p class="no-margin"><i class="fa fa-info-circle"></i> '.tr('Lo storico resta intatto: l\'importazione non cancella nulla, registra un movimento di scarico che porta la giacenza a 0. Un articolo gia\' a 0 non genera alcun movimento.').'</p>
        </div>

        <button id="mncs-download-azzeramento" type="button" class="btn btn-warning btn-lg">
            <i class="fa fa-download"></i> '.tr('Scarica CSV azzeramento giacenze').'
        </button>
    </div>
</div>

<script>
$("#mncs-download-azzeramento").click(function() {
    var btn = $(this);
    btn.prop("disabled", true);
    $.ajax({
        url: globals.rootdir + "/actions.php",
        type: "post",
        data: {
            op: "genera-csv",
            id_module: globals.id_module,
        },
        success: function(data) {
            if (data) {
                window.location = data;
            }
            btn.prop("disabled", false);
        },
        error: function() {
            btn.prop("disabled", false);
        }
    });
});
</script>';
