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
use Modules\Fatture\Stato;
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
$importo = round(floatval(post('importo')), 2);

// Sede presa dal corpo fattura (non più dal form): coerente con il documento.
$id_sede = (int) $fattura->id_sede_partenza;

$is_bozza = in_array($fattura->stato->getTranslation('title'), ['Bozza', 'Annullata', 'Non valida']);

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

// Emissione automatica: se la fattura è ancora in bozza, viene prima emessa (genera
// scadenzario + prima nota) e poi incassata, in un'unica azione dal Dettaglio fattura.
// Eseguita solo dopo aver validato i conti, per non emettere se l'incasso non può procedere.
$emessa_ora = false;
if ($is_bozza) {
    if (round(floatval($fattura->netto), 2) <= 0) {
        flash()->error(tr('La fattura non ha importi da incassare: aggiungi le righe prima di registrare un incasso.'));
        redirect_url($back);

        return;
    }

    // Il metodo scelto guida la generazione dello scadenzario in fase di emissione.
    $fattura->id_pagamento = $id_pagamento;
    $fattura->stato()->associate(Stato::where('name', 'Emessa')->first());
    $fattura->save();
    $emessa_ora = true;
}

// Residuo da incassare (dopo l'eventuale emissione lo scadenzario è popolato)
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

// Abbuono automatico sotto soglia (impostazioni globali, Impostazioni > Fatturazione)
$id_conto_abbuono = setting('Conto abbuono');
$soglia_abbuono = round(floatval(str_replace(',', '.', (string) setting('Soglia abbuono'))), 2);
$differenza = round($residuo - $importo, 2);
$abbuona = $differenza > 0 && $differenza <= $soglia_abbuono && !empty($id_conto_abbuono);

// Scadenze aperte, dalla più vecchia
$scadenze = $dbo->fetchArray('SELECT `id`, (ABS(`da_pagare`) - ABS(`pagato`)) AS residuo FROM `co_scadenzario` WHERE `id_documento` = '.prepare($id_fattura).' AND ABS(`da_pagare`) > ABS(`pagato`) ORDER BY `scadenza` ASC, `id` ASC');

// Causale
$numero = !empty($fattura->numero_esterno) ? $fattura->numero_esterno : $fattura->numero;
$descrizione = tr('Inc. fattura num. _NUM_ del _DATE_ (_NAME_)', [
    '_NUM_' => $numero,
    '_DATE_' => dateFormat($fattura->data),
    '_NAME_' => $fattura->anagrafica->ragione_sociale,
]);

// Registrazione contabile (un unico mastrino per l'incasso).
// Convenzione coerente con la Prima Nota manuale: Dare cassa/conto abbuono, Avere conto cliente.
$mastrino = Mastrino::build($descrizione, date('Y-m-d'), false, true, $fattura->id_anagrafica);

$rimanente = $importo;
$abbuonato = 0;
foreach ($scadenze as $scadenza_row) {
    $residuo_scad = round(floatval($scadenza_row['residuo']), 2);
    if ($residuo_scad <= 0) {
        continue;
    }

    $scadenza = Scadenza::find($scadenza_row['id']);

    // Quota effettivamente incassata su questa scadenza
    $quota = min(max($rimanente, 0), $residuo_scad);
    if ($quota > 0) {
        // Avere conto cliente
        $movimento_cliente = Movimento::build($mastrino, $id_conto_cliente, $fattura, $scadenza);
        $movimento_cliente->totale = -$quota;
        $movimento_cliente->save();

        // Dare conto contropartita (cassa/banca)
        $movimento_cassa = Movimento::build($mastrino, $id_conto_contropartita, $fattura, $scadenza);
        $movimento_cassa->totale = $quota;
        $movimento_cassa->save();

        $rimanente -= $quota;
    }

    // Abbuono del residuo non coperto su questa scadenza (la chiude)
    if ($abbuona) {
        $quota_abbuono = round($residuo_scad - $quota, 2);
        if ($quota_abbuono > 0) {
            // Avere conto cliente
            $movimento_cliente_ab = Movimento::build($mastrino, $id_conto_cliente, $fattura, $scadenza);
            $movimento_cliente_ab->totale = -$quota_abbuono;
            $movimento_cliente_ab->save();

            // Dare conto abbuono
            $movimento_abbuono = Movimento::build($mastrino, $id_conto_abbuono, $fattura, $scadenza);
            $movimento_abbuono->totale = $quota_abbuono;
            $movimento_abbuono->save();

            $abbuonato += $quota_abbuono;
        }
    }
}

// Aggiorna scadenzario e stato della fattura
$mastrino->aggiornaScadenzario();

$messaggio = $emessa_ora ? tr('Fattura emessa.').' ' : '';
$messaggio .= tr('Incasso di _IMP_ registrato in prima nota.', ['_IMP_' => moneyFormat($importo - $rimanente)]);
if ($abbuonato > 0) {
    $messaggio .= ' '.tr('Abbuonata una differenza di _AB_.', ['_AB_' => moneyFormat($abbuonato)]);
}
flash()->info($messaggio);

// Avviso se la differenza era abbuonabile ma manca il conto configurato
if ($differenza > 0 && $differenza <= $soglia_abbuono && empty($id_conto_abbuono)) {
    flash()->warning(tr('Differenza di _D_ non abbuonata: configura il "Conto abbuono" in Impostazioni > Fatturazione.', ['_D_' => moneyFormat($differenza)]));
}

redirect_url($back);
