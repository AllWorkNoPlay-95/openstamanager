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

// [MNCS] Override CUSTOM di modules/fatture/row-list.php.
// Copia integrale del core con tre differenze marcate [MNCS]:
//   1) include riga sottostante: __DIR__.'/../init.php' (il file e' fetchato via
//      AJAX come entry point standalone da custom/, quindi __DIR__ e' .../custom).
//   2) colonna "Costo unitario" NON renderizzata nelle Fatture di vendita
//      ($dir == 'entrata'): rimossi <th>/<td> visibili e colspan a '7'; il valore
//      costo_unitario e' preservato via campo hidden (vedi blocchi [MNCS] sotto).
//   3) colonna "Listino / Ultimo prezzo" (solo vendita, prima di "Prezzo unitario"):
//      scaglioni del listino assegnato al cliente + ultimo prezzo pagato dal cliente
//      per l'articolo; evidenziazione riga success/warning se la qta rientra in uno
//      scaglione (prezzo applicato / non applicato).
// CAVEAT MERGE UPSTREAM: questo file maschera il core e NON riceve i suoi bugfix.
// Ad ogni merge riallineare il corpo copiato mantenendo solo i blocchi [MNCS].

use Modules\Interventi\Intervento;

include_once __DIR__.'/../init.php'; // [MNCS] era __DIR__.'/init.php' nel core

use Models\Plugin;

$block_edit = !empty($note_accredito) || in_array($record['stato'], ['Emessa', 'Pagato', 'Parzialmente pagato']) || !$abilita_genera;
$order_row_desc = $_SESSION['module_'.$id_module]['order_row_desc'];
$righe = $order_row_desc ? $fattura->getRighe()->sortByDesc('created_at') : $fattura->getRighe();
$colspan = ($dir == 'entrata' ? '8' : '7'); // [MNCS] in vendita: colonna costo rimossa, +1 per "Listino / Ultimo prezzo"

// [MNCS] Colonna "Listino / Ultimo prezzo" (solo Fatture di vendita): prefetch batch
// degli scaglioni del listino assegnato al cliente. Filtri di validita' come
// getPrezzoConsigliato (lib/common.php), ma NULL-safe sulle date: i listini k-odin
// (modules/mncs/update/1_5.sql) e le righe del sync hanno date NULL = nessun vincolo.
$mncs_prezzi_ivati = setting('Utilizza prezzi di vendita comprensivi di IVA');
$mncs_scaglioni = [];     // id_articolo => [['minimo','massimo','prezzo_unitario'], ...]
$mncs_aux = [];           // id_articolo => [label AUXn => [tier, ...]]
$mncs_ultimi_prezzi = []; // memo id_articolo => row|null (riempita lazy nel loop)
if ($dir == 'entrata') {
    $mncs_ids_articoli = [];
    foreach ($righe as $mncs_r) {
        if ($mncs_r->isArticolo() && !empty($mncs_r->id_articolo)) {
            $mncs_ids_articoli[$mncs_r->id_articolo] = $mncs_r->id_articolo;
        }
    }
    if (!empty($mncs_ids_articoli)) {
        $mncs_rows = $dbo->fetchArray('SELECT `mg_listini_articoli`.`id_articolo`, `mg_listini_articoli`.`minimo`, `mg_listini_articoli`.`massimo`,
                '.($mncs_prezzi_ivati ? '`mg_listini_articoli`.`prezzo_unitario_ivato`' : '`mg_listini_articoli`.`prezzo_unitario`').' AS `prezzo_unitario`
            FROM `mg_listini`
                INNER JOIN `mg_listini_articoli` ON `mg_listini`.`id` = `mg_listini_articoli`.`id_listino`
                INNER JOIN `an_anagrafiche` ON `mg_listini`.`id` = `an_anagrafiche`.`id_listino`
            WHERE `mg_listini`.`attivo` = 1
                AND (`mg_listini`.`data_attivazione` IS NULL OR `mg_listini`.`data_attivazione` <= NOW())
                AND (`mg_listini_articoli`.`data_scadenza` >= NOW() OR (`mg_listini_articoli`.`data_scadenza` IS NULL AND (`mg_listini`.`data_scadenza_predefinita` IS NULL OR `mg_listini`.`data_scadenza_predefinita` >= NOW())))
                AND `mg_listini_articoli`.`dir` = \'entrata\'
                AND `an_anagrafiche`.`id` = '.prepare($fattura->id_anagrafica).'
                AND `mg_listini_articoli`.`id_articolo` IN ('.implode(',', array_map('prepare', $mncs_ids_articoli)).')
            ORDER BY `mg_listini_articoli`.`minimo` ASC');
        foreach ($mncs_rows as $mncs_r) {
            $mncs_scaglioni[$mncs_r['id_articolo']][] = $mncs_r;
        }

        // Prezzi dei listini ausiliari AUX1-AUX4 (indipendenti dal listino del cliente).
        $mncs_rows = $dbo->fetchArray('SELECT `mg_listini_articoli`.`id_articolo`, `mg_listini`.`nome`, `mg_listini_articoli`.`minimo`, `mg_listini_articoli`.`massimo`,
                '.($mncs_prezzi_ivati ? '`mg_listini_articoli`.`prezzo_unitario_ivato`' : '`mg_listini_articoli`.`prezzo_unitario`').' AS `prezzo_unitario`
            FROM `mg_listini`
                INNER JOIN `mg_listini_articoli` ON `mg_listini`.`id` = `mg_listini_articoli`.`id_listino`
            WHERE `mg_listini`.`attivo` = 1
                AND `mg_listini`.`nome` LIKE \'%[AUX%]\'
                AND (`mg_listini`.`data_attivazione` IS NULL OR `mg_listini`.`data_attivazione` <= NOW())
                AND (`mg_listini_articoli`.`data_scadenza` >= NOW() OR (`mg_listini_articoli`.`data_scadenza` IS NULL AND (`mg_listini`.`data_scadenza_predefinita` IS NULL OR `mg_listini`.`data_scadenza_predefinita` >= NOW())))
                AND `mg_listini_articoli`.`dir` = \'entrata\'
                AND `mg_listini_articoli`.`id_articolo` IN ('.implode(',', array_map('prepare', $mncs_ids_articoli)).')
            ORDER BY `mg_listini`.`nome` ASC, `mg_listini_articoli`.`minimo` ASC');
        foreach ($mncs_rows as $mncs_r) {
            if (preg_match('/\[(AUX\d+)\]/', $mncs_r['nome'], $mncs_m)) {
                $mncs_aux[$mncs_r['id_articolo']][$mncs_m[1]][] = $mncs_r;
            }
        }
    }
}

echo '
<div class="table-responsive row-list">
    <table class="table table-striped table-hover table-sm table-bordered">
        <thead>
            <tr>
                <th width="5" class="text-center">';
if (sizeof($righe) > 0) {
    echo '
                    <input id="check_all" type="checkbox"/>';
}
echo '
                </th>
                <th width="35" class="text-center" >'.tr('#').'</th>
                <th class="text-left" style="width:30%;">'.tr('Descrizione').'</th>
                <th class="text-center" width="120">'.tr('Q.tà').'</th>';
// [MNCS] colonna "Costo unitario" nascosta nelle Fatture di vendita: <th> non emesso.
// Al suo posto, solo in vendita, la colonna "Listino / Ultimo prezzo".
if ($dir == 'entrata') {
    echo '
                <th class="text-center" width="170">'.tr('Listini').'</th>';
}
echo '
                <th class="text-center" width="180">'.tr('Prezzo unitario').'</th>
                <th class="text-center" width="140">'.tr('Sconto unitario').'</th>
                <th class="text-center" width="120">'.tr('Iva unitaria').'</th>
                <th class="text-center" width="120">'.tr('Importo').'</th>
                <th width="120"></th>
            </tr>
        </thead>
        <tbody class="sortable" id="righe">';

// Righe documento
$num = 0;
foreach ($righe as $riga) {
    $show_notifica = [];
    ++$num;
    $extra = '';
    $mancanti = 0;
    $delete = 'delete_riga';

    $row_disable = in_array($riga->id, [$fattura->rigaBollo->id, $fattura->id_riga_spese_incasso]);

    // Individuazione dei seriali
    if ($riga->isArticolo() && !empty($riga->abilita_serial)) {
        $serials = $riga->serials;
        $mancanti = abs($riga->qta) - count($serials);

        if ($mancanti > 0) {
            $extra = 'class="warning"';
        } else {
            $mancanti = 0;
        }
    }

    // Imposto sfondo rosso alle righe con quantità a 0
    if ($riga->qta == 0) {
        $extra = 'class="danger"';
    }

    // [MNCS] Match scaglione listino e ultimo prezzo pagato (solo vendita, solo articoli).
    $mncs_scaglioni_riga = [];
    $mncs_tier_match = null; // indice dello scaglione che contiene la qta
    $mncs_tier_class = '';   // 'success' | 'warning' | ''
    $mncs_ultimo = null;
    if ($dir == 'entrata' && $riga->isArticolo() && !empty($riga->id_articolo)) {
        $mncs_scaglioni_riga = $mncs_scaglioni[$riga->id_articolo] ?? [];
        $mncs_qta = abs($riga->qta);
        foreach ($mncs_scaglioni_riga as $mncs_i => $mncs_tier) {
            $mncs_massimo = $mncs_tier['massimo'] === null ? INF : floatval($mncs_tier['massimo']);
            if ($mncs_qta >= floatval($mncs_tier['minimo']) && $mncs_qta <= $mncs_massimo) {
                $mncs_tier_match = $mncs_i;
                $mncs_tier_class = abs(floatval($mncs_tier['prezzo_unitario']) - floatval($riga->prezzo_unitario_corrente)) < 0.005 ? 'success' : 'warning';
                break;
            }
        }

        // Ultimo prezzo pagato dal cliente per l'articolo: ultima riga di fattura di
        // vendita esclusa la corrente. Memoizzato per articolo; LIMIT 1 per evitare
        // groupwise-max/derived table correlate (problematiche su MariaDB).
        if (!array_key_exists($riga->id_articolo, $mncs_ultimi_prezzi)) {
            $mncs_ultimi_prezzi[$riga->id_articolo] = $dbo->fetchOne('SELECT
                    '.($mncs_prezzi_ivati ? '`co_righe_documenti`.`prezzo_unitario_ivato`' : '`co_righe_documenti`.`prezzo_unitario`').' AS `prezzo_unitario`,
                    `co_documenti`.`data`,
                    IFNULL(NULLIF(`co_documenti`.`numero_esterno`, \'\'), NULLIF(`co_documenti`.`numero`, \'\')) AS `numero`
                FROM `co_righe_documenti`
                    INNER JOIN `co_documenti` ON `co_documenti`.`id` = `co_righe_documenti`.`id_documento`
                    INNER JOIN `co_tipi_documento` ON `co_tipi_documento`.`id` = `co_documenti`.`id_tipo_documento`
                WHERE `co_tipi_documento`.`dir` = \'entrata\'
                    AND `co_documenti`.`id_anagrafica` = '.prepare($fattura->id_anagrafica).'
                    AND `co_righe_documenti`.`id_articolo` = '.prepare($riga->id_articolo).'
                    AND `co_documenti`.`id` != '.prepare($fattura->id).'
                ORDER BY `co_documenti`.`data` DESC, `co_documenti`.`id` DESC, `co_righe_documenti`.`id` DESC
                LIMIT 1') ?: null;
        }
        $mncs_ultimo = $mncs_ultimi_prezzi[$riga->id_articolo];

        // Precedenza classi: danger (qta=0) e warning (seriali mancanti) esistenti vincono.
        if ($extra === '' && $mncs_tier_class !== '') {
            $extra = 'class="'.$mncs_tier_class.'"';
        }
    }

    $extra_riga = '';
    if (!$riga->isDescrizione()) {
        // Informazioni su CIG, CUP, ...
        if ($riga->hasOriginalComponent()) {
            $documento_originale = $riga->getOriginalComponent()->getDocument();

            $num_item = $documento_originale['num_item'];
            $codice_cig = $documento_originale['codice_cig'];
            $codice_commessa = $documento_originale['codice_commessa'];
            $codice_cup = $documento_originale['codice_cup'];
            $id_documento_fe = $documento_originale['id_documento_fe'];
        }

        // [MNCS] Rimossa la descrizione del conto merci dal <small> di riga (era _DESCRIZIONE_CONTO_).
        $extra_riga = replace('_ID_DOCUMENTO__NUMERO_RIGA__CODICE_COMMESSA__CODICE_CIG__CODICE_CUP__RITENUTA_ACCONTO__RITENUTA_CONTRIBUTI__RIVALSA_', [
            '_RIVALSA_' => $riga->rivalsa_inps ? '<br>'.tr('Cassa previdenziale').': '.moneyFormat(abs($riga->rivalsa_inps)) : null,
            '_RITENUTA_ACCONTO_' => $riga->ritenuta_acconto ? '<br>Ritenuta acconto: '.moneyFormat(abs($riga->ritenuta_acconto)) : null,
            '_RITENUTA_CONTRIBUTI_' => $riga->ritenuta_contributi ? '<br>Ritenuta previdenziale: '.moneyFormat(abs($riga->ritenuta_contributi)) : null,
            '_ID_DOCUMENTO_' => $id_documento_fe ? ' - DOC: '.$id_documento_fe : null,
            '_NUMERO_RIGA_' => $num_item ? ', NRI: '.$num_item : null,
            '_CODICE_COMMESSA_' => $codice_commessa ? ', COM: '.$codice_commessa : null,
            '_CODICE_CIG_' => $codice_cig ? ', CIG: '.$codice_cig : null,
            '_CODICE_CUP_' => $codice_cup ? ', CUP: '.$codice_cup : null,
        ]);
    }

    echo '
        <tr data-id="'.$riga->id.'" data-type="'.$riga::class.'" '.$extra.'>
            <td class="text-center">
                <input class="check" type="checkbox"/>
            </td>

            <td class="text-center">
                '.$num.'
            </td>

            <td>';

    // Informazioni aggiuntive sulla destra
    echo '
                <small class="pull-right text-right text-muted">
                    '.$extra_riga;

    // Aggiunta dei riferimenti ai documenti
    if ($riga->hasOriginalComponent()) {
        echo '
                    <br>'.reference($riga->getOriginalComponent()->getDocument(), tr('Origine'));
    }
    // Fix per righe da altre componenti degli Interventi
    elseif (!empty($riga->id_intervento)) {
        echo '
                    <br>'.reference(Intervento::find($riga->id_intervento), tr('Origine'));
    }

    echo '
                </small>';

    if ($riga->isArticolo()) {
        echo Modules::link('Articoli', $riga->id_articolo, $riga->codice.' - '.$riga->descrizione);
    } else {
        echo nl2br((string) $riga->descrizione);
    }

    if ($riga->isArticolo() && !empty($riga->articolo->deleted_at)) {
        echo '
        <br><b><small class="text-danger">'.tr('Articolo eliminato', []).'</small></b>';
    }

    if ($riga->isArticolo() && empty($riga->articolo->codice)) {
        echo '
        <br><b><small class="text-danger">'.tr('_DATO_ articolo mancante', [
            '_DATO_' => 'Codice',
        ]).'</small></b>';
    }

    if ($riga->isArticolo() && !empty($riga->abilita_serial)) {
        if (!empty($mancanti)) {
            echo '
                <br><b><small class="text-danger">'.tr('_NUM_ serial mancanti', [
                '_NUM_' => $mancanti,
            ]).'</small></b>';
        }
        if (!empty($serials)) {
            echo '
                <br>'.tr('SN').': '.implode(', ', $serials);
        }
    }

    if ($riga->isArticolo() && !empty($riga->barcode)) {
        echo '
                <br><small><i class="fa fa-barcode"></i> '.$riga->barcode.'</small>';
    }

    if (!empty($riga->note)) {
        if (strlen((string) $riga->note) > 50) {
            $prima_parte = substr((string) $riga->note, 0, (strpos((string) $riga->note, ' ', 50) < 60) && (!str_starts_with((string) $riga->note, ' ')) ? strpos((string) $riga->note, ' ', 50) : 50);
            $seconda_parte = substr((string) $riga->note, (strpos((string) $riga->note, ' ', 50) < 60) && (!str_starts_with((string) $riga->note, ' ')) ? strpos((string) $riga->note, ' ', 50) : 50);
            $stringa_modificata = '<span class="text-xs">'.$prima_parte.'</small>
                <span id="read-more-target-'.$riga->id.'" class="read-more-target"><span class="text-xs">'.$seconda_parte.'</small></span><a href="#read-more-target-'.$riga->id.'" class="read-more-trigger">...</a>';
        } else {
            $stringa_modificata = '<span class="text-xs">'.$riga->note.'</small>';
        }

        echo '
        <div class="block-item-text">
            <input type="checkbox" hidden class="read-more-state" id="read-more">
                <div class="read-more-wrap">
                    '.nl2br($stringa_modificata).'
                </div>
            </div>
        ';
    }

    if (!empty($riga->data_inizio_competenza) || !empty($riga->data_fine_competenza)) {
        $has_alert = $riga->hasDifferentOriginalDateCompetenza();
        echo '
                <br><span class="text-xs text-muted">'.tr('Competenza (_START_ - _END_)', [
            '_START_' => Translator::dateToLocale($riga->data_inizio_competenza),
            '_END_' => Translator::dateToLocale($riga->data_fine_competenza),
        ]).'
                    '.($has_alert ? '<i class="fa fa-warning text-danger"></i>' : '').'
                </span>';
    }
    echo '
            </td>';

    if ($riga->isDescrizione()) {
        // [MNCS] Rimossa la cella vuota della colonna "Costo unitario"; in vendita
        // serve invece la cella vuota extra per "Listino / Ultimo prezzo", per
        // mantenere lo stesso numero di celle dell'header.
        echo ($dir == 'entrata' ? '
            <td></td>' : '').'
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>';
    } else {
        // [MNCS] Colonna "Costo unitario" nascosta nelle Fatture di vendita: il valore
        // costo_unitario viene preservato con un campo hidden dentro la cella Q.tà.
        // Senza, aggiornaInline() riposterebbe costo vuoto e actions.php (riga ~1459,
        // "costo_unitario = post('costo') ?: 0") azzererebbe il dato ad ogni modifica inline.
        $mncs_hidden_costo = ($dir == 'entrata' && !$riga->isSconto())
            ? '<input type="hidden" name="costo_'.$riga->id.'" value="'.$riga->costo_unitario.'">'
            : '';

        // Quantità e unità di misura
        echo '
            <td>'.$mncs_hidden_costo.'
                {[ "type": "number", "name": "qta_'.$riga->id.'", "value": "'.$riga->qta.'", "min-value": "0", "onchange": "aggiornaInline($(this).closest(\'tr\').data(\'id\'))", "disabled": "'.($riga->isSconto() ? 1 : 0).'", "disabled": "'.($block_edit || $riga->isSconto() || $row_disable).'", "decimals": "qta" ]}
            </td>';

        // [MNCS] Cella "Listino / Ultimo prezzo" (solo vendita): scaglioni del listino
        // cliente (badge sullo scaglione corrispondente alla qta) + ultimo prezzo pagato.
        // Cella vuota per righe non-articolo (sconto, bollo, spese incasso).
        if ($dir == 'entrata') {
            echo '
            <td class="text-right">';
            if ($riga->isArticolo()) {
                if (!empty($mncs_scaglioni_riga)) {
                    // Uno scaglione per riga, cliccabile: al click applica quel prezzo alla riga.
                    // Range a sinistra, prezzo in grassetto a destra. Tutti gli scaglioni sono badge
                    // "fissi" (stessa forma): quello che contiene la qta ha il colore success/warning
                    // (hover nativo a.badge-*:hover), gli altri sono trasparenti e l'hover
                    // sovrascrive solo lo sfondo (grigio chiaro) via JS.
                    $mncs_disabilitato = $block_edit || $row_disable;
                    foreach ($mncs_scaglioni_riga as $mncs_i => $mncs_tier) {
                        $mncs_infinito = $mncs_tier['massimo'] === null || floatval($mncs_tier['massimo']) >= 999999999;
                        $mncs_range = numberFormat($mncs_tier['minimo'], 0).' - '.($mncs_infinito ? '&infin;' : numberFormat($mncs_tier['massimo'], 0));
                        $mncs_match = $mncs_i === $mncs_tier_match;
                        $mncs_classe_riga = $mncs_match
                            ? 'badge badge-'.($mncs_tier_class == 'success' ? 'success' : 'warning')
                            : 'badge text-muted';
                        $mncs_hover = (!$mncs_match && !$mncs_disabilitato)
                            ? ' onmouseenter="$(this).css(\'background-color\', \'#e9ecef\')" onmouseleave="$(this).css(\'background-color\', \'\')"'
                            : '';
                        echo '
                <a role="button" class="text-nowrap '.$mncs_classe_riga.'" style="display: flex; justify-content: space-between; align-items: baseline; font-size: 100%; width: 100%; padding: 0 2px;'.($mncs_disabilitato ? ' pointer-events: none;' : '').'" title="'.tr('Applica questo prezzo').'"'.$mncs_hover.($mncs_disabilitato ? '' : ' onclick="input(\'prezzo_'.$riga->id.'\').set('.floatval($mncs_tier['prezzo_unitario']).'); aggiornaInline('.$riga->id.');"').'><span>'.$mncs_range.'</span><b>'.moneyFormat($mncs_tier['prezzo_unitario'], 2).'</b></a>';
                    }
                } else {
                    echo '
                <div class="text-muted">-</div>';
                }
                // Prezzi listini ausiliari AUX1-AUX4 su una sola riga, con etichetta; gli AUX
                // a prezzo 0 sono omessi; con piu' di 2 AUX presenti l'etichetta diventa "An".
                // A parita' di AUX si usa lo scaglione che contiene la qta, altrimenti il primo.
                if (!empty($mncs_aux[$riga->id_articolo])) {
                    $mncs_aux_presenti = [];
                    foreach ($mncs_aux[$riga->id_articolo] as $mncs_label => $mncs_tiers) {
                        $mncs_prezzo_aux = $mncs_tiers[0]['prezzo_unitario'];
                        foreach ($mncs_tiers as $mncs_tier) {
                            $mncs_massimo = $mncs_tier['massimo'] === null ? INF : floatval($mncs_tier['massimo']);
                            if (abs($riga->qta) >= floatval($mncs_tier['minimo']) && abs($riga->qta) <= $mncs_massimo) {
                                $mncs_prezzo_aux = $mncs_tier['prezzo_unitario'];
                                break;
                            }
                        }
                        if (floatval($mncs_prezzo_aux) != 0) {
                            $mncs_aux_presenti[$mncs_label] = $mncs_prezzo_aux;
                        }
                    }
                    if (!empty($mncs_aux_presenti)) {
                        // Ogni AUX e' un bottone: al click imposta il prezzo unitario della riga.
                        $mncs_aux_parts = [];
                        foreach ($mncs_aux_presenti as $mncs_label => $mncs_prezzo_aux) {
                            $mncs_label_breve = count($mncs_aux_presenti) > 2 ? str_replace('AUX', 'A', $mncs_label) : $mncs_label;
                            $mncs_aux_parts[] = '<button type="button" class="btn btn-outline-secondary btn-xs" title="'.$mncs_label.': '.moneyFormat($mncs_prezzo_aux, 2).'"'.($block_edit || $row_disable ? ' disabled' : '').' onclick="input(\'prezzo_'.$riga->id.'\').set('.floatval($mncs_prezzo_aux).'); aggiornaInline('.$riga->id.');">'.$mncs_label_breve.' <b>'.moneyFormat($mncs_prezzo_aux, 2).'</b></button>';
                        }
                        echo '
                <div class="text-nowrap" style="display: flex; justify-content: space-between; margin-top: 3px;">'.implode('', $mncs_aux_parts).'</div>';
                    }
                }
            }
            echo '
            </td>';
        }

        if ($riga->isArticolo()) {
            $id_anagrafica = $fattura->id_anagrafica;
            $show_notifica = getPrezzoConsigliato($id_anagrafica, $dir, $riga->id_articolo, $riga, $fattura->id_sede_destinazione);
        }

        // [MNCS] Colonna "Costo unitario" rimossa dalla griglia (Fatture di vendita):
        // nessun <td> visibile. Il valore e' preservato dal campo hidden nella cella Q.tà.

        // Prezzi unitari
        if ($riga->isSconto()) {
            echo '
            <td></td>';
        } else {
            echo '
            <td class="text-center">
                '.($show_notifica['show_notifica_prezzo'] ? '<i class="fa fa-info-circle notifica-prezzi"></i>' : '').'
                {[ "type": "number", "name": "prezzo_'.$riga->id.'", "value": "'.$riga->prezzo_unitario_corrente.'", "onchange": "aggiornaInline($(this).closest(\'tr\').data(\'id\'))", "icon-before": "'.(abs($riga->provvigione_unitaria) > 0 ? '<span class=\'tip text-info\' title=\''.provvigioneInfo($riga).'\'><small><i class=\'fa fa-handshake-o\'></i></small></span>' : '').'", "icon-after": "'.currency().'", "disabled": "'.($block_edit || $row_disable).'" ]}';

            // [MNCS] Ultimo prezzo pagato dal cliente per l'articolo, sotto l'input del prezzo.
            if ($dir == 'entrata' && !empty($mncs_ultimo)) {
                echo '
                <div class="text-muted text-nowrap" style="display: flex; justify-content: space-between;"'.($mncs_ultimo['numero'] !== null ? ' title="'.tr('Fattura n. _NUM_', ['_NUM_' => $mncs_ultimo['numero']]).'"' : '').'><span>'.tr('Ult.').' '.date('d/m/y', strtotime($mncs_ultimo['data'])).'</span><b>'.moneyFormat($mncs_ultimo['prezzo_unitario'], 2).'</b></div>';
            }

            // Prezzo inferiore al minimo consigliato
            if ($riga->isArticolo()) {
                echo $riga->articolo->minimo_vendita > $riga->prezzo_unitario_corrente ? '<small><i class="fa fa-info-circle text-danger"></i> '.tr('Consigliato: ').numberFormat($riga->articolo->minimo_vendita, 2).'</small>' : '';
            }
            echo '</td>';
        }

        // Sconto unitario
        $tipo_sconto = '';
        if ($riga['sconto'] == 0) {
            $tipo_sconto = (setting('Tipo di sconto predefinito') == '%' ? 'PRC' : 'UNT');
        }
        echo '
            <td>
                '.($show_notifica['show_notifica_sconto'] ? '<i class="fa fa-info-circle notifica-prezzi"></i>' : '').'
                {[ "type": "number", "name": "sconto_'.$riga->id.'", "value": "'.($riga->sconto_percentuale ?: $riga->sconto_unitario_corrente).'", "onchange": "aggiornaInline($(this).closest(\'tr\').data(\'id\'))", "icon-after": "'.($riga->isSconto() ? currency() : 'choice|untprc|'.($tipo_sconto ?: $riga->tipo_sconto)).'", "disabled": "'.($block_edit || $riga->sconto_percentuale_combinato).'" ]}
                    <small class="badge badge-info '.($riga->tipo_sconto == 'PRC+' ? '' : 'hidden').'">Sconto combinato: '.$riga->sconto_percentuale_combinato.'</small>
            </td>';

        // Iva
        // Controllo aliquota esente senza codice natura o con codice natura obsoleto (N2, N3, N6 senza sottocodice)
        $codici_natura_obsoleti = ['N2', 'N3', 'N6'];
        $iva_esente_senza_natura = $riga->aliquota && $riga->aliquota->esente && empty($riga->aliquota->codice_natura_fe);
        $iva_natura_obsoleta = $riga->aliquota && $riga->aliquota->esente && in_array($riga->aliquota->codice_natura_fe, $codici_natura_obsoleti);
        $iva_errore = $iva_esente_senza_natura || $iva_natura_obsoleta;
        $iva_class = ($riga->aliquota->deleted_at || $iva_errore) ? 'text-danger' : 'text-muted';

        if ($iva_esente_senza_natura) {
            $iva_tooltip = ' title="'.tr('Attenzione: aliquota esente senza codice natura IVA. Correggere prima di emettere fattura elettronica.').'" style="cursor: help;"';
        } elseif ($iva_natura_obsoleta) {
            $iva_tooltip = ' title="'.tr('Attenzione: il codice natura "_NATURA_" non è più valido dal 1° gennaio 2021. Utilizzare un sottocodice specifico.', ['_NATURA_' => $riga->aliquota->codice_natura_fe]).'" style="cursor: help;"';
        } else {
            $iva_tooltip = '';
        }

        echo '
            <td class="text-right">
                '.moneyFormat($riga->iva_unitaria_scontata).'
                <br><small class="'.$iva_class.'"'.$iva_tooltip.'>'.($iva_errore ? '<i class="fa fa-exclamation-triangle"></i> ' : '').($riga->aliquota ? $riga->aliquota->getTranslation('title') : '').' ('.$riga->aliquota->esigibilita.') '.(($riga->aliquota->esente) ? ' ('.$riga->aliquota->codice_natura_fe.')' : null).'</small>
            </td>';

        // Importo
        echo '
            <td class="text-right">
                '.moneyFormat($riga->importo).'
            </td>';
    }

    // Possibilità di rimuovere una riga solo se la fattura non è pagata
    echo '
            <td class="text-center">

                <div class="input-group-btn">';
    if (hasArticoliFiglio($riga->id_articolo)) {
        echo '
                    <a class="btn btn-xs btn-info" title="'.tr('Distinta base').'" onclick="viewDistinta('.$riga->id_articolo.')">
                        <i class="fa fa-eye"></i>
                    </a>';
    }

    if ($riga->isArticolo() && !empty($riga->abilita_serial)) {
        echo '
                    <a class="btn btn-primary btn-xs" title="'.tr('Modifica seriali della riga').'" onclick="modificaSeriali(this)">
                        <i class="fa fa-barcode"></i>
                    </a>';
    }

    if ($record['stato'] != 'Pagato' && $record['stato'] != 'Emessa') {
        if (!$row_disable) {
            echo '
                    <a class="btn btn-xs btn-info" title="'.tr('Aggiungi informazioni FE per questa riga').'" onclick="apriInformazioniFE(this)">
                        <i class="fa fa-file-code-o"></i>
                    </a>

                    <a class="btn btn-xs btn-warning" title="'.tr('Modifica riga').'" onclick="modificaRiga(this)">
                        <i class="fa fa-edit"></i>
                    </a>

                    <a class="btn btn-xs btn-danger" title="'.tr('Rimuovi riga').'" onclick="rimuoviRiga([$(this).closest(\'tr\').data(\'id\')])">
                        <i class="fa fa-trash"></i>
                    </a>';
        }

        echo '
                    <a class="btn btn-xs btn-default handle '.($order_row_desc ? 'disabled' : '').'" title="'.tr('Modifica ordine delle righe').'">
                        <i class="fa fa-sort"></i>
                    </a>';
    }
    echo '
                </div>
            </td>
        </tr>';
}

echo '
        </tbody>';

// Individuazione dei totali
$imponibile = $fattura->imponibile;
$sconto = -$fattura->sconto;
$totale_imponibile = $fattura->totale_imponibile;
$iva = $fattura->iva;
$totale = $fattura->totale;
$sconto_finale = $fattura->getScontoFinale();
$netto_a_pagare = $fattura->netto;
$rivalsa_inps = $fattura->rivalsa_inps;
$ritenuta_acconto = $fattura->ritenuta_acconto;
$ritenuta_contributi = $fattura->totale_ritenuta_contributi;

// IMPONIBILE
echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b>'.tr('Imponibile', [], ['upper' => true]).':</b>
            </td>
            <td class="text-right">
                '.moneyFormat($imponibile, 2).'
            </td>
            <td></td>
        </tr>';

// SCONTO
if (!empty($sconto)) {
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b><span class="tip" title="'.tr('Un importo negativo indica uno sconto, mentre uno positivo indica una maggiorazione').'"><i class="fa fa-question-circle-o"></i> '.tr('Sconto/maggiorazione', [], ['upper' => true]).':</span></b>
            </td>
            <td class="text-right">
                '.moneyFormat($sconto, 2).'
            </td>
            <td></td>
        </tr>';

    // TOTALE IMPONIBILE
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b>'.tr('Totale imponibile', [], ['upper' => true]).':</b>
            </td>
            <td class="text-right">
                '.moneyFormat($totale_imponibile, 2).'
            </td>
            <td></td>
        </tr>';
}

// RIVALSA INPS
if ($rivalsa_inps > 0) {
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">';

    if ($dir == 'entrata') {
        $descrizione_rivalsa = $database->fetchOne('SELECT CONCAT_WS(\' - \', codice, descrizione) AS descrizione FROM fe_tipo_cassa WHERE codice = '.prepare(setting('Tipo Cassa Previdenziale')));
        echo '
				<span class="tip" title="'.$descrizione_rivalsa['descrizione'].'">
				    <i class="fa fa-question-circle-o"></i>
                </span> ';
    }

    echo '
                <b>'.tr('Cassa previdenziale', [], ['upper' => true]).' :</b>
            </td>
            <td class="text-right">
                '.moneyFormat($rivalsa_inps, 2).'
            </td>
            <td></td>
        </tr>
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b>'.tr('Totale imponibile', [], ['upper' => true]).' :</b>
            </td>
            <td class="text-right">
                '.moneyFormat($totale_imponibile + $rivalsa_inps, 2).'
            </td>
            <td></td>
        </tr>';
}

// IVA
if ($iva > 0) {
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">';

    if ($records[0]['split_payment']) {
        echo '<b>'.tr('Iva a carico del destinatario', [], ['upper' => true]).':</b>';
    } else {
        echo '<b>'.tr('Iva', [], ['upper' => true]).':</b>';
    }
    echo '
            </td>
            <td class="text-right">
                '.moneyFormat($iva, 2).'
            </td>
            <td></td>
        </tr>';
}

// TOTALE
echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b>'.tr('Totale documento', [], ['upper' => true]).':</b>
            </td>
            <td class="text-right">
                '.moneyFormat($totale, 2).'
            </td>
            <td></td>
        </tr>';

// RITENUTA D'ACCONTO
if ($ritenuta_acconto > 0) {
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b>'.tr("Ritenuta d'acconto", [], ['upper' => true]).':</b>
            </td>
            <td class="text-right">
                '.moneyFormat($ritenuta_acconto, 2).'
            </td>
            <td></td>
        </tr>';
}

// RITENUTA PREVIDENZIALE
if ($ritenuta_contributi > 0) {
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b>'.tr('Ritenuta previdenziale', [], ['upper' => true]).':</b>
            </td>
            <td class="text-right">
                '.moneyFormat($ritenuta_contributi, 2).'
            </td>
            <td></td>
        </tr>';
}

// SCONTO IN FATTURA
if ($sconto_finale > 0) {
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b>'.tr('Sconto in fattura', [], ['upper' => true]).':</b>
            </td>
            <td class="text-right">
                '.moneyFormat($sconto_finale, 2).'
            </td>
            <td></td>
        </tr>';
}

// NETTO A PAGARE
if ($totale != $netto_a_pagare) {
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                <b>'.tr('Netto a pagare', [], ['upper' => true]).':</b>
            </td>
            <td class="text-right">
                '.moneyFormat($netto_a_pagare, 2).'
            </td>
            <td></td>
        </tr>';
}

// Provvigione
if ($fattura->provvigione > 0) {
    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                '.tr('Provvigioni').':
            </td>
            <td class="text-right">
                '.moneyFormat($fattura->provvigione).'
            </td>
            <td></td>
        </tr>';

    echo '
        <tr>
            <td colspan="'.$colspan.'" class="text-right">
                '.tr('Netto da provvigioni').':
            </td>
            <td class="text-right">
                '.moneyFormat($fattura->totale_imponibile - $fattura->provvigione).'
            </td>
            <td></td>
        </tr>';
}

echo '
    </table>';
if (sizeof($righe) > 0) {
    echo '
    <div class="btn-group">
        <button type="button" class="btn btn-sm btn-primary disabled" id="copia_righe" onclick="copiaRighe(getSelectData());" title="'.tr('Copia righe selezionate negli appunti').'">
            <i class="fa fa-clipboard"></i> '.tr('Copia').'
        </button>';

    // Il tasto incolla è disponibile solo se il documento non è bloccato
    if (!$block_edit) {
        echo '
        <button type="button" class="btn btn-sm btn-primary" id="incolla_righe" onclick="incollaRighe();" title="'.tr('Incolla righe dagli appunti').'">
            <i class="fa fa-paste"></i> '.tr('Incolla').'
        </button>';
    }

    // I pulsanti di modifica sono disponibili solo se il documento non è bloccato
    if (!$block_edit) {
        echo '
        <button type="button" class="btn btn-sm btn-primary disabled" id="duplica_righe" onclick="duplicaRiga(getSelectData());">
            <i class="fa fa-copy"></i> '.tr('Duplica').'
        </button>
    
        <button type="button" class="btn btn-sm btn-danger disabled" id="elimina_righe" onclick="rimuoviRiga(getSelectData());">
            <i class="fa fa-trash"></i> '.tr('Elimina').'
        </button>';
        if ($dir == 'entrata') {
            echo '
        <button type="button" class="btn btn-sm btn-info disabled" id="confronta_righe" onclick="confrontaRighe(getSelectData());">
            <i class="fa fa-exchange"></i> '.tr('Confronta prezzi').'
        </button>';
        }
        echo '
        <button type="button" class="btn btn-sm btn-info disabled" id="aggiorna_righe" onclick="aggiornaRighe(getSelectData());">
            <i class="fa fa-refresh"></i> '.tr('Aggiorna prezzi').'
        </button>
    
        <button type="button" class="btn btn-sm btn-info disabled" id="modifica_iva_righe" onclick="modificaIvaRighe(getSelectData());">
            <i class="fa fa-percent"></i> '.tr('Modifica IVA').'
        </button>';
    }
    echo '
    </div>';
} else {
    // Anche quando non ci sono righe, il tasto incolla è disponibile solo se il documento non è bloccato
    if (!$block_edit) {
        echo '
    <div class="btn-group">
        <button type="button" class="btn btn-sm btn-primary" id="incolla_righe" onclick="incollaRighe();" title="'.tr('Incolla righe dagli appunti').'">
            <i class="fa fa-paste"></i> '.tr('Incolla').'
        </button>
    </div>';
    }
}
echo '
</div>

<script>
async function modificaRiga(button) {
    let riga = $(button).closest("tr");
    let id = riga.data("id");
    let type = riga.data("type");

    // Salvataggio via AJAX
    await salvaForm("#edit-form", {}, button);

    // Chiusura tooltip
    if ($(button).hasClass("tooltipstered"))
        $(button).tooltipster("close");

    // Apertura modal
    content_was_modified = false;
    openModal("'.tr('Modifica riga').'", "'.$module->fileurl('row-edit.php').'?id_module=" + globals.id_module + "&id_record=" + globals.id_record + "&riga_id=" + id + "&riga_type=" + type);
}

// Estraggo le righe spuntate
function getSelectData() {
    let data=new Array();
    $(\'#righe\').find(\'.check:checked\').each(function (){
        data.push($(this).closest(\'tr\').data(\'id\'));
    });

    return data;
}

function confrontaRighe(id) {
    openModal("'.tr('Confronta prezzi').'", "'.$module->fileurl('modals/confronta_righe.php').'?id_module=" + globals.id_module + "&id_record=" + globals.id_record + "&righe=" + id);
}

function aggiornaRighe(id) {
    Swal.fire({
        title: "'.tr('Aggiornare prezzi di queste righe?').'",
        html: `'.tr('Confermando verranno aggiornati i prezzi delle righe secondo i listini ed i prezzi predefiniti collegati all\'articolo e ai piani sconto collegati all\'anagrafica.').'.<br><br>
        {[ "type": "checkbox", "label": "", "name": "update_prezzo_acquisto", "value":"1", "values":" \"'.tr('Aggiornare prezzo di acquisto').'\",\"'.tr('Non aggiornare prezzo di acquisto').'\" " ]}<br>
        {[ "type": "checkbox", "label": "", "name": "update_prezzo_vendita", "value":"1", "values":" \"'.tr('Aggiornare prezzo di vendita').'\",\"'.tr('Non aggiornare prezzo di vendita').'\" " ]}<br>
        {[ "type": "checkbox", "label": "", "name": "update_descrizione", "value":"0", "values":" \"'.tr('Aggiornare descrizione').'\",\"'.tr('Non aggiornare descrizione').'\" " ]}<br>`,
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "'.tr('Sì').'"
    }).then(function (result) {
        if (result.isConfirmed) {
            $.ajax({
                url: globals.rootdir + "/actions.php",
                type: "POST",
                dataType: "json",
                data: {
                    id_module: globals.id_module,
                    id_record: globals.id_record,
                    op: "update-price",
                    righe: id,
                    update_prezzo_acquisto: input("update_prezzo_acquisto").get(),
                    update_prezzo_vendita: input("update_prezzo_vendita").get(),
                    update_descrizione: input("update_descrizione").get(),
                },
                success: function (response) {
                    renderMessages();
                    caricaRighe(null);
                },
                error: function() {
                    renderMessages();
                    caricaRighe(null);
                }
            });
        }
    }).catch(swal.noop);
}

function rimuoviRiga(id) {
    Swal.fire({
        title: "'.tr('Rimuovere queste righe?').'",
        html: "'.tr('Sei sicuro di volere rimuovere queste righe dal documento?').' '.tr("L'operazione è irreversibile").'.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "'.tr('Sì').'"
    }).then(function (result) {
        if (result.isConfirmed) {
            $.ajax({
                url: globals.rootdir + "/actions.php",
                type: "POST",
                dataType: "json",
                data: {
                    id_module: globals.id_module,
                    id_record: globals.id_record,
                    op: "delete_riga",
                    righe: id,
                },
                success: function (response) {
                    renderMessages();
                    caricaRighe(null);';
if (!in_array($fattura->codice_stato_fe, ['RC', 'MC', 'EC01', 'WAIT'])) {
    echo '
                $("#elimina").removeClass("disabled");';
}
echo '
                },
                error: function() {
                    renderMessages();
                    caricaRighe(null);';
if (!in_array($fattura->codice_stato_fe, ['RC', 'MC', 'EC01', 'WAIT'])) {
    echo '
                $("#elimina").removeClass("disabled");';
}
echo '
                }
            });
        }
    }).catch(swal.noop);
}

function duplicaRiga(id) {
    Swal.fire({
        title: "'.tr('Duplicare queste righe?').'",
        html: "'.tr('Sei sicuro di volere queste righe del documento?').'",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "'.tr('Sì').'"
    }).then(function (result) {
        if (result.isConfirmed) {
            $.ajax({
                url: globals.rootdir + "/actions.php",
                type: "POST",
                dataType: "json",
                data: {
                    id_module: globals.id_module,
                    id_record: globals.id_record,
                    op: "copy_riga",
                    righe: id,
                },
                success: function (response) {
                    renderMessages();
                    caricaRighe(null);
                },
                error: function() {
                    renderMessages();
                    caricaRighe(null);
                }
            });
        }
    }).catch(swal.noop);
}

function modificaSeriali(button) {
    let riga = $(button).closest("tr");
    let id = riga.data("id");
    let type = riga.data("type");

    openModal("'.tr('Aggiorna SN').'", globals.rootdir + "/modules/fatture/add_serial.php?id_module=" + globals.id_module + "&id_record=" + globals.id_record + "&riga_id=" + id + "&riga_type=" + type);
}

function apriInformazioniFE(button) {
    let riga = $(button).closest("tr");
    let id = riga.data("id");
    let type = riga.data("type");

    openModal("'.tr('Dati Fattura Elettronica').'", "'.$module->fileurl('fe/row-fe.php').'?id_module=" + globals.id_module + "&id_record=" + globals.id_record + "&riga_id=" + id + "&riga_type=" + type)
}

function modificaIvaRighe(righe) {
    if (righe.length > 0) {
        openModal("'.tr('Modifica IVA').'", globals.rootdir + "/include/modifica_iva.php?id_module=" + globals.id_module + "&id_record=" + globals.id_record + "&tipo_documento=fatture&righe=" + righe.join(','));
    }
}

function copiaRighe(righe) {
    if (righe.length === 0) {
        return;
    }

    // Raccolgo i dati delle righe selezionate
    $.ajax({
        url: globals.rootdir + "/actions.php",
        type: "POST",
        dataType: "json",
        data: {
            id_module: globals.id_module,
            id_record: globals.id_record,
            op: "get_righe_data",
            righe: righe,
        },
        success: function (response) {
            if (response && response.data) {
                // Copio i dati negli appunti del browser
                navigator.clipboard.writeText(JSON.stringify(response.data)).then(function() {
                    Swal.fire({
                        title: "'.tr('Righe copiate!').'",
                        text: "'.tr('Le righe selezionate sono state copiate negli appunti').'",
                        icon: "success",
                        timer: 2000,
                        showConfirmButton: false
                    });
                }).catch(function(err) {
                    Swal.fire({
                        title: "'.tr('Errore').'",
                        text: "'.tr('Impossibile copiare negli appunti').': " + err,
                        icon: "error"
                    });
                });
            }
        },
        error: function() {
            Swal.fire({
                title: "'.tr('Errore').'",
                text: "'.tr('Errore durante il recupero dei dati delle righe').'",
                icon: "error"
            });
        }
    });
}

function incollaRighe() {
    // Leggo i dati dagli appunti del browser
    navigator.clipboard.readText().then(function(text) {
        try {
            let righe_data = JSON.parse(text);

            Swal.fire({
                title: "'.tr('Incollare le righe?').'",
                html: "'.tr('Sei sicuro di voler incollare').' " + righe_data.length + " '.tr('righe in questo documento?').'",
                icon: "warning",
                showCancelButton: true,
                confirmButtonText: "'.tr('Sì').'"
            }).then(function (result) {
                if (result.isConfirmed) {
                    $.ajax({
                        url: globals.rootdir + "/actions.php",
                        type: "POST",
                        dataType: "json",
                        data: {
                            id_module: globals.id_module,
                            id_record: globals.id_record,
                            op: "paste_righe",
                            righe_data: JSON.stringify(righe_data),
                        },
                        success: function (response) {
                            renderMessages();
                            caricaRighe(null);
                            Swal.fire({
                                title: "'.tr('Righe incollate!').'",
                                text: "'.tr('Le righe sono state incollate con successo').'",
                                icon: "success",
                                timer: 2000,
                                showConfirmButton: false
                            });
                        },
                        error: function() {
                            renderMessages();
                            Swal.fire({
                                title: "'.tr('Errore').'",
                                text: "'.tr('Errore durante l\'incollaggio delle righe').'",
                                icon: "error"
                            });
                        }
                    });
                }
            }).catch(swal.noop);
        } catch (e) {
            Swal.fire({
                title: "'.tr('Errore').'",
                text: "'.tr('I dati negli appunti non sono validi').'",
                icon: "error"
            });
        }
    }).catch(function(err) {
        Swal.fire({
            title: "'.tr('Errore').'",
            text: "'.tr('Impossibile leggere dagli appunti').': " + err,
            icon: "error"
        });
    });
}

$(".check").on("change", function() {
    let checked = 0;
    $(".check").each(function() {
        if ($(this).is(":checked")) {
            checked = 1;
        }
    });

    if (checked) {
        // Pulsanti sempre attivi anche se documento bloccato
        $("#copia_righe").removeClass("disabled");

        // Pulsanti attivi solo se documento non bloccato';
if (!$block_edit) {
    echo '
        $("#elimina_righe").removeClass("disabled");
        $("#duplica_righe").removeClass("disabled");
        $("#confronta_righe").removeClass("disabled");
        $("#aggiorna_righe").removeClass("disabled");
        $("#modifica_iva_righe").removeClass("disabled");
        $("#incolla_righe").removeClass("disabled");
        $("#elimina").addClass("disabled");';
}
echo '
    } else {
        // Pulsanti sempre disabilitati quando nessuna riga è selezionata
        $("#copia_righe").addClass("disabled");

        // Pulsanti disabilitati solo se documento non bloccato';
if (!$block_edit) {
    echo '
        $("#elimina_righe").addClass("disabled");
        $("#duplica_righe").addClass("disabled");
        $("#confronta_righe").addClass("disabled");
        $("#aggiorna_righe").addClass("disabled");
        $("#modifica_iva_righe").addClass("disabled");
        $("#incolla_righe").addClass("disabled");
        $("#elimina").removeClass("disabled");';
}
echo '
    }
});

$("#check_all").click(function(){
    if( $(this).is(":checked") ){
        $(".check").each(function(){
            if( !$(this).is(":checked") ){
                $(this).trigger("click");
            }
        });
    }else{
        $(".check").each(function(){
            if( $(this).is(":checked") ){
                $(this).trigger("click");
            }
        });
    }
});

$(".tipo_icon_after").on("change", function() {
    aggiornaInline($(this).closest("tr").data("id"));
});

function aggiornaInline(id) {
    content_was_modified = false;
    var qta = input("qta_"+ id).get();
    var sconto = input("sconto_"+ id).get();
    var tipo_sconto = input("tipo_sconto_"+ id).get();
    var prezzo = input("prezzo_"+ id).get();
    var costo = input("costo_"+ id).get();

    $.ajax({
        url: globals.rootdir + "/actions.php",
        type: "POST",
        data: {
            id_module: globals.id_module,
            id_record: globals.id_record,
            op: "update_inline",
            riga_id: id,
            qta: qta,
            sconto: sconto,
            tipo_sconto: tipo_sconto,
            prezzo: prezzo,
            costo: costo
        },
        success: function (response) {
            caricaRighe(id);
            renderMessages();
        },
        error: function() {
            caricaRighe(null);
        }
    });
}
init();';

if (Plugin::where('name', 'Distinta base')->first()) {
    echo '
    async function viewDistinta(id_articolo) {
        openModal("'.tr('Distinta base').'", "'.Plugin::where('name', 'Distinta base')->first()->fileurl('view.php').'?id_module=" + globals.id_module + "&id_record=" + globals.id_record + "&id_articolo=" + id_articolo);
    }';
}
echo '
</script>';
