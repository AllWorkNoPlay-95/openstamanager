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

// Helper custom MNCS: import FE con carico giacenze su più sedi ("Mix").
//
// Con sede di testata = "mix" l'utente indica, per ogni riga XML, la quantità da caricare in ogni
// sede aziendale (`qta_sede[$key][$id_sede]`). In salvataggio la riga XML diventa una riga fattura
// per ogni sede con quota > 0, ognuna con il proprio `id_sede`: il movimento di magazzino nasce già
// sulla sede giusta (Article::movimentaMagazzino usa la sede della riga).
// Usato da plugins/importFE/{generate.php, actions.php, src/FatturaOrdinaria.php}.

use Common\Components\Component;
use Plugins\ImportFE\FatturaOrdinaria;

if (!function_exists('mncs_importfe_sedi')) {
    /**
     * Sedi aziendali selezionabili: Sede legale (0) + sedi dell'Azienda predefinita.
     *
     * @return array<int, string> id_sede => nome
     */
    function mncs_importfe_sedi()
    {
        $sedi = [0 => tr('Sede legale')];
        $rows = database()->fetchArray('SELECT `id`, `nome_sede` FROM `an_sedi` WHERE `id_anagrafica` = '.prepare(setting('Azienda predefinita')).' AND `deleted_at` IS NULL ORDER BY `nome_sede`');
        foreach ($rows as $row) {
            $sedi[(int) $row['id']] = $row['nome_sede'];
        }

        return $sedi;
    }
}

if (!function_exists('mncs_importfe_quote')) {
    /**
     * Normalizza le quote di una riga: solo sedi con quantità > 0, nell'ordine inserito.
     *
     * @return array<int, float> id_sede => quantità (unità XML)
     */
    function mncs_importfe_quote($qta_sede_riga)
    {
        $quote = [];
        foreach ((array) $qta_sede_riga as $id_sede => $qta) {
            $qta = (float) str_replace(',', '.', (string) $qta);
            if ($qta > 0) {
                $quote[(int) $id_sede] = $qta;
            }
        }

        return $quote;
    }
}

if (!function_exists('mncs_importfe_riga_origine')) {
    /**
     * Riga del documento di origine (ordine/DDT) collegata alla riga XML, se presente.
     */
    function mncs_importfe_riga_origine($tipo, $id)
    {
        if (empty($tipo) || empty($id) || !is_subclass_of($tipo, Component::class)) {
            return null;
        }

        return $tipo::find($id);
    }
}

if (!function_exists('mncs_importfe_origine_movimentata')) {
    /**
     * True se la riga di origine appartiene a un documento che ha già movimentato il magazzino (es. DDT):
     * in quel caso la fattura non movimenta e la suddivisione per sede non ha senso.
     */
    function mncs_importfe_origine_movimentata($origine)
    {
        return !empty($origine) && !empty($origine->getDocument()->movimenta_magazzino);
    }
}

if (!function_exists('mncs_importfe_valida_mix')) {
    /**
     * Verifica le quote Mix: per ogni riga che diventerà articolo (stesse condizioni di saveRighe) con
     * quantità positiva e non già movimentata da un DDT, la somma deve coincidere con la quantità XML;
     * le righe con seriali (inseriti o ereditati dall'origine) non possono essere divise su più sedi.
     *
     * @return string|null messaggio di errore, null se valido
     */
    function mncs_importfe_valida_mix($fattura_pa, $qta_sede, $articoli, $crea_articoli, $serials, $tipi_riferimenti = [], $id_riferimenti = [])
    {
        if (!$fattura_pa instanceof FatturaOrdinaria) {
            return tr('La suddivisione Mix è disponibile solo per le fatture ordinarie');
        }

        foreach ($fattura_pa->getRighe() as $key => $riga) {
            $qta = (empty($riga['Quantita']) && (!empty($riga['PrezzoUnitario']) || !empty($riga['Importo']))) ? 1 : (float) $riga['Quantita'];
            // Come saveRighe: l'articolo viene creato solo se la riga ha un CodiceArticolo
            $is_articolo = !empty($articoli[$key]) || (!empty($crea_articoli[$key]) && !empty($riga['CodiceArticolo']));
            $origine = mncs_importfe_riga_origine($tipi_riferimenti[$key] ?? null, $id_riferimenti[$key] ?? null);
            if ($qta <= 0 || !$is_articolo || mncs_importfe_origine_movimentata($origine)) {
                continue;
            }

            $quote = mncs_importfe_quote($qta_sede[$key] ?? []);
            if (abs(array_sum($quote) - $qta) > 0.0001) {
                return tr('Riga _NUM_ (_DESC_): la somma delle quantità per sede (_SOMMA_) non corrisponde alla quantità della fattura (_QTA_)', [
                    '_NUM_' => $key + 1,
                    '_DESC_' => $riga['Descrizione'],
                    '_SOMMA_' => numberFormat(array_sum($quote), 'qta'),
                    '_QTA_' => numberFormat($qta, 'qta'),
                ]);
            }

            $has_serial = !empty(array_filter((array) ($serials[$key] ?? []))) || (!empty($origine) && !empty($origine->serials));
            if (count($quote) > 1 && $has_serial) {
                return tr('Riga _NUM_ (_DESC_): gli articoli con seriali non possono essere suddivisi su più sedi', [
                    '_NUM_' => $key + 1,
                    '_DESC_' => $riga['Descrizione'],
                ]);
            }
        }

        return null;
    }
}

if (!function_exists('mncs_importfe_split')) {
    /**
     * Crea una copia della riga articolo per ogni sede aggiuntiva, con la relativa quota.
     * La copia mantiene prezzo, sconto, IVA, conto e origine: Component::replicate() azzera l'origine,
     * che va ripristinata prima di impostare la qta perché l'evasione dell'ordine si sommi sulle copie.
     *
     * @param array<int, float> $quote             id_sede => quantità (unità XML), senza la prima sede
     * @param float             $fattore           rapporto unità documento / unità XML (conversione UM)
     * @param bool              $movimentazione    flag "Movimenta gli articoli" dell'import
     */
    function mncs_importfe_split($obj, $quote, $fattore, $movimentazione)
    {
        foreach ($quote as $id_sede => $qta) {
            $copia = $obj->replicate(['qta', 'qta_evasa']);
            $copia->original_type = $obj->original_type;
            $copia->original_id = $obj->original_id;
            $copia->original_document_type = $obj->original_document_type;
            $copia->original_document_id = $obj->original_document_id;
            $copia->id_sede = $id_sede;
            $copia->movimentazione($movimentazione);
            $copia->qta = $qta * $fattore;
            $copia->save();
        }
    }
}
