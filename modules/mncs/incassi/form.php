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
// Mini-form: metodo di pagamento + importo, con esito dinamico (abbuono / fattura aperta).
// La sede è quella del corpo fattura (id_sede_partenza): mostrata come label, non modificabile.
// Il salvataggio contabile è gestito da registra.php.
include_once __DIR__.'/../../../core.php';

use Modules\Fatture\Fattura;

$fattura = Fattura::find($id_record);
$numero = !empty($fattura->numero_esterno) ? $fattura->numero_esterno : $fattura->numero;

// Sede del corpo fattura (label informativa): 0 = sede legale dell'azienda
$id_sede = intval($fattura->id_sede_partenza);
$sede_nome = $id_sede > 0
    ? ($dbo->fetchOne('SELECT `nome_sede` FROM `an_sedi` WHERE `id` = '.prepare($id_sede))['nome_sede'] ?? tr('Sede legale'))
    : tr('Sede legale');

// In bozza non esiste ancora lo scadenzario: la fattura verrà emessa al volo al salvataggio
// (vedi registra.php), quindi l'importo incassabile è il netto a pagare del documento.
$is_bozza = in_array($fattura->stato->getTranslation('title'), ['Bozza', 'Annullata', 'Non valida']);

// Residuo da incassare (somma delle scadenze ancora aperte, oppure netto a pagare se in bozza)
$residuo = $is_bozza
    ? round(floatval($fattura->netto), 2)
    : round(floatval($dbo->fetchOne('SELECT SUM(ABS(`da_pagare`) - ABS(`pagato`)) AS residuo FROM `co_scadenzario` WHERE `id_documento` = '.prepare($id_record))['residuo']), 2);

$soglia_abbuono = round(floatval(str_replace(',', '.', (string) setting('Soglia abbuono'))), 2);
$conto_abbuono_attivo = !empty(setting('Conto abbuono')) && $soglia_abbuono > 0;

$action = base_path_osm().'/modules/mncs/incassi/registra.php?id_module='.$id_module.'&id_record='.$id_record;

?>
<form action="<?php echo $action; ?>" method="post" id="add-form">
	<input type="hidden" name="id_module" value="<?php echo $id_module; ?>">
	<input type="hidden" name="id_record" value="<?php echo $id_record; ?>">

	<div class="callout callout-info" style="margin-bottom: 18px;">
		<div style="display: flex; justify-content: space-between; align-items: center; gap: 16px;">
			<div>
				<i class="fa fa-file-text-o"></i> <?php echo tr('Fattura'); ?> <b><?php echo htmlspecialchars((string) $numero, ENT_QUOTES); ?></b>
				<div class="text-muted" style="font-size: 0.9em;"><?php echo $fattura->anagrafica->ragione_sociale; ?></div>
				<div class="text-muted" style="font-size: 0.85em;"><i class="fa fa-map-marker"></i> <?php echo tr('Sede'); ?>: <?php echo htmlspecialchars((string) $sede_nome, ENT_QUOTES); ?></div>
			</div>
			<div class="text-right">
				<div class="text-muted" style="font-size: 0.85em;"><?php echo $is_bozza ? tr('Netto a pagare') : tr('Residuo da incassare'); ?></div>
				<div style="font-size: 1.5em; font-weight: 600;"><?php echo moneyFormat($residuo); ?></div>
			</div>
		</div>
	</div>

	<?php if ($is_bozza) { ?>
	<div class="alert alert-warning" style="margin-bottom: 12px;">
		<i class="fa fa-bolt"></i> <?php echo tr('La fattura è in bozza: verrà prima emessa e poi incassata.'); ?>
	</div>
	<?php } ?>

	<div class="row">
		<div class="col-md-8">
			{[ "type": "select", "label": "<?php echo tr('Metodo di pagamento'); ?>", "name": "id_pagamento", "required": 1, "ajax-source": "pagamenti", "value": "<?php echo $fattura->id_pagamento; ?>" ]}
		</div>

		<div class="col-md-4">
			{[ "type": "number", "label": "<?php echo tr('Importo incassato'); ?>", "name": "importo", "required": 1, "decimals": 2, "value": "<?php echo numberFormat($residuo, 2); ?>" ]}
		</div>
	</div>

	<div id="mncs-esito" class="alert alert-info" style="margin-top: 6px;"></div>

	<div class="modal-footer">
		<div class="col-md-12 text-right">
			<button type="submit" class="btn btn-success btn-lg">
				<i class="fa fa-check"></i> <?php echo tr('Registra incasso e Salva'); ?>
			</button>
		</div>
	</div>
</form>

<script>
(function () {
    var residuo = <?php echo json_encode($residuo); ?>;
    var soglia = <?php echo json_encode($soglia_abbuono); ?>;
    var abbuonoAttivo = <?php echo $conto_abbuono_attivo ? 'true' : 'false'; ?>;

    function eur(v) {
        return Number(v).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    window.mncsAggiornaEsito = function () {
        var $box = $('#modals #mncs-esito').last();
        if (!$box.length) { $box = $('#mncs-esito'); }
        if (!$box.length) { return; }

        var raw;
        try { raw = input('importo').get(); } catch (e) { raw = $("#modals input[name='importo']").val(); }
        var importo = parseFloat(String(raw).replace(',', '.')) || 0;
        var diff = Math.round((residuo - importo) * 100) / 100;

        var cls, icon, txt;
        if (importo <= 0) {
            cls = 'alert-warning'; icon = 'fa-exclamation-triangle';
            txt = "Inserisci un importo da incassare.";
        } else if (diff < -0.001) {
            cls = 'alert-danger'; icon = 'fa-times-circle';
            txt = "L'importo supera il residuo di <b>" + eur(-diff) + "</b>.";
        } else if (diff <= 0.001) {
            cls = 'alert-success'; icon = 'fa-check-circle';
            txt = "Incasso a saldo: la fattura verrà segnata come <b>Pagata</b>.";
        } else if (abbuonoAttivo && diff <= soglia + 0.001) {
            cls = 'alert-success'; icon = 'fa-gift';
            txt = "Verrà fatto un abbuono di <b>" + eur(diff) + "</b>: la fattura verrà segnata come <b>Pagata</b>.";
        } else {
            cls = 'alert-warning'; icon = 'fa-hourglass-half';
            txt = "La fattura resterà <b>aperta</b> (Parzialmente pagata). Residuo: <b>" + eur(diff) + "</b>.";
        }

        $box.attr('class', 'alert ' + cls).css('margin-top', '6px').html('<i class="fa ' + icon + '"></i> ' + txt);
    };

    $(document).off('keyup.mncsab change.mncsab blur.mncsab', "#modals input[name='importo']");
    $(document).on('keyup.mncsab change.mncsab blur.mncsab', "#modals input[name='importo']", window.mncsAggiornaEsito);
    setTimeout(window.mncsAggiornaEsito, 350);
})();
</script>
