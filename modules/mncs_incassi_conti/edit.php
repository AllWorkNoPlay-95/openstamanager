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

$query_conti = "query=SELECT `co_piano_dei_conti3`.`id`, CONCAT(`co_piano_dei_conti2`.`numero`, '.', `co_piano_dei_conti3`.`numero`, ' ', `co_piano_dei_conti3`.`descrizione`) AS descrizione FROM `co_piano_dei_conti3` INNER JOIN `co_piano_dei_conti2` ON `co_piano_dei_conti3`.`id_piano_dei_conti2` = `co_piano_dei_conti2`.`id` ORDER BY `co_piano_dei_conti2`.`numero`, `co_piano_dei_conti3`.`numero`";

?><form action="" method="post" id="edit-form">
	<input type="hidden" name="op" value="update">
	<input type="hidden" name="backto" value="record-edit">
	<input type="hidden" name="id_record" value="<?php echo $id_record; ?>">

	<div class="row">
		<div class="col-md-4">
			{[ "type": "select", "label": "<?php echo tr('Metodo di pagamento'); ?>", "name": "id_pagamento", "required": 1, "ajax-source": "pagamenti", "value": "$id_pagamento$" ]}
		</div>

		<div class="col-md-4">
			{[ "type": "select", "label": "<?php echo tr('Sede'); ?>", "name": "id_sede", "required": 1, "ajax-source": "sedi_azienda", "value": "$id_sede$" ]}
		</div>

		<div class="col-md-4">
			{[ "type": "select", "label": "<?php echo tr('Conto contropartita (cassa/banca)'); ?>", "name": "id_conto", "required": 1, "value": "$id_conto$", "values": "<?php echo $query_conti; ?>" ]}
		</div>
	</div>
</form>

<a class="btn btn-danger ask" data-backto="record-list">
	<i class="fa fa-trash"></i> <?php echo tr('Elimina'); ?>
</a>
