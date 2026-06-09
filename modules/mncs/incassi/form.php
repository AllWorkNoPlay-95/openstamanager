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

// Corpo del modale "Registra incasso" aperto dal Dettaglio fattura di vendita.
// Mini-form: metodo di pagamento + sede + importo. Il salvataggio è gestito da registra.php.
include_once __DIR__.'/../../../core.php';

use Modules\Fatture\Fattura;

$fattura = Fattura::find($id_record);

// Residuo da incassare (somma delle scadenze ancora aperte)
$residuo = $dbo->fetchOne('SELECT SUM(ABS(`da_pagare`) - ABS(`pagato`)) AS residuo FROM `co_scadenzario` WHERE `id_documento` = '.prepare($id_record))['residuo'];
$residuo = round(floatval($residuo), 2);

$action = base_path_osm().'/modules/mncs/incassi/registra.php?id_module='.$id_module.'&id_record='.$id_record;

echo '
<form action="'.$action.'" method="post" id="add-form">
	<input type="hidden" name="id_module" value="'.$id_module.'">
	<input type="hidden" name="id_record" value="'.$id_record.'">

	<div class="row">
		<div class="col-md-6">
			{[ "type": "select", "label": "'.tr('Metodo di pagamento').'", "name": "id_pagamento", "required": 1, "ajax-source": "pagamenti", "value": "'.$fattura->id_pagamento.'" ]}
		</div>

		<div class="col-md-6">
			{[ "type": "select", "label": "'.tr('Sede').'", "name": "id_sede", "required": 1, "ajax-source": "sedi_azienda", "value": "'.intval($fattura->id_sede_partenza).'" ]}
		</div>
	</div>

	<div class="row">
		<div class="col-md-6">
			{[ "type": "number", "label": "'.tr('Importo').'", "name": "importo", "required": 1, "decimals": 2, "value": "'.numberFormat($residuo, 2).'" ]}
		</div>
	</div>

	<div class="alert alert-info">
		<i class="fa fa-info-circle"></i> '.tr("L'importo verrà registrato in Prima Nota e distribuito sulle scadenze aperte della fattura (dalla più vecchia).").'
	</div>

	<!-- PULSANTI -->
	<div class="modal-footer">
		<div class="col-md-12 text-right">
			<button type="submit" class="btn btn-success">
				<i class="fa fa-euro"></i> '.tr('Registra incasso').'
			</button>
		</div>
	</div>
</form>';
