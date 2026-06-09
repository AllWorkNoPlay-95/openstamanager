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

// Endpoint custom MNCS: registra in Prima Nota l'incasso di una fattura di vendita.
// Risolve (metodo di pagamento + sede) -> conto contropartita dalla tabella `mncs_incassi_conti`
// e crea i movimenti contabili (Dare conto cliente / Avere conto contropartita), distribuendo
// l'importo sulle scadenze aperte (dalla più vecchia). Aggiorna scadenzario e stato fattura.
include_once __DIR__.'/../../../core.php';

use Modules\Fatture\Fattura;
use Modules\PrimaNota\Mastrino;
use Modules\PrimaNota\Movimento;
use Modules\Scadenzario\Scadenza;

$id_fattura = filter('id_record');
$id_module = filter('id_module');
$back = base_path_osm().'/editor.php?id_module='.$id_module.'&id_record='.$id_fattura;

$fattura = Fattura::find($id_fattura);

// Validazione documento
if (empty($fattura) || $fattura->direzione != 'entrata') {
    flash()->error(tr('Documento non valido per la registrazione di un incasso.'));
    redirect_url($back);

    return;
}

$id_pagamento = post('id_pagamento');
$id_sede = (int) post('id_sede');
$importo = round(floatval(post('importo')), 2);

// Conto cliente (lato Dare)
$id_conto_cliente = $fattura->anagrafica->id_conto_cliente;
if (empty($id_conto_cliente)) {
    flash()->error(tr('Conto cliente non configurato per questa anagrafica.'));
    redirect_url($back);

    return;
}

// Conto contropartita (lato Avere): risolto dalla mappa (metodo + sede)
$mappa = $dbo->fetchOne('SELECT `id_conto` FROM `mncs_incassi_conti` WHERE `id_pagamento` = '.prepare($id_pagamento).' AND `id_sede` = '.prepare($id_sede));
$id_conto_contropartita = $mappa['id_conto'] ?? null;
if (empty($id_conto_contropartita)) {
    flash()->error(tr('Nessun conto configurato per questo metodo di pagamento e sede. Configura la mappatura in Strumenti > Tabelle > Incassi conti.'));
    redirect_url($back);

    return;
}

// Residuo da incassare
$residuo = round(floatval($dbo->fetchOne('SELECT SUM(ABS(`da_pagare`) - ABS(`pagato`)) AS residuo FROM `co_scadenzario` WHERE `id_documento` = '.prepare($id_fattura))['residuo']), 2);

if ($importo <= 0) {
    flash()->error(tr('Inserire un importo maggiore di zero.'));
    redirect_url($back);

    return;
}

if ($importo > $residuo + 0.001) {
    flash()->error(tr('Importo superiore al residuo da incassare (_RES_).', ['_RES_' => moneyFormat($residuo)]));
    redirect_url($back);

    return;
}

// Scadenze aperte, dalla più vecchia
$scadenze = $dbo->fetchArray('SELECT `id`, (ABS(`da_pagare`) - ABS(`pagato`)) AS residuo FROM `co_scadenzario` WHERE `id_documento` = '.prepare($id_fattura).' AND ABS(`da_pagare`) > ABS(`pagato`) ORDER BY `scadenza` ASC, `id` ASC');

// Causale
$numero = !empty($fattura->numero_esterno) ? $fattura->numero_esterno : $fattura->numero;
$descrizione = tr('Inc. fattura num. _NUM_ del _DATE_ (_NAME_)', [
    '_NUM_' => $numero,
    '_DATE_' => dateFormat($fattura->data),
    '_NAME_' => $fattura->anagrafica->ragione_sociale,
]);

// Registrazione contabile (un unico mastrino per l'incasso)
$mastrino = Mastrino::build($descrizione, date('Y-m-d'), false, true, $fattura->id_anagrafica);

$rimanente = $importo;
foreach ($scadenze as $scadenza_row) {
    if ($rimanente <= 0.001) {
        break;
    }

    $scadenza = Scadenza::find($scadenza_row['id']);
    $quota = min($rimanente, round(floatval($scadenza_row['residuo']), 2));
    if ($quota <= 0) {
        continue;
    }

    // Dare sul conto cliente
    $movimento_cliente = Movimento::build($mastrino, $id_conto_cliente, $fattura, $scadenza);
    $movimento_cliente->totale = $quota;
    $movimento_cliente->save();

    // Avere sul conto contropartita (cassa/banca)
    $movimento_contropartita = Movimento::build($mastrino, $id_conto_contropartita, $fattura, $scadenza);
    $movimento_contropartita->totale = -$quota;
    $movimento_contropartita->save();

    $rimanente -= $quota;
}

// Aggiorna scadenzario e stato della fattura
$mastrino->aggiornaScadenzario();

flash()->info(tr('Incasso di _IMP_ registrato in prima nota.', ['_IMP_' => moneyFormat($importo - $rimanente)]));
redirect_url($back);
