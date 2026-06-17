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

// Helper custom MNCS: gate per-articolo del piano sconto cliente.
//
// Unico punto di verità che decide se, per un dato articolo, il piano sconto legato
// all'anagrafica cliente (an_anagrafiche.id_piano_sconto_vendite → mg_piani_sconto) può essere
// combinato sopra il listino sulle righe documento. Si basa sul flag `mncs_sconto_su_articolo`
// aggiunto a `mg_articoli` (vedi modules/mncs/update/1_10.sql).
//
// Usato in tutti i moduli documento (fatture/ordini/ddt/preventivi/interventi/contratti) per
// gating della condizione `parseScontoCombinato($piano_sconto->prc_guadagno.'+'.$sconto)`.

if (!function_exists('mncs_sconto_articolo_attivo')) {
    /**
     * Indica se per l'articolo dato è consentito applicare il piano sconto cliente.
     *
     * @param int|string|null $id_articolo id di mg_articoli (idarticolo della riga)
     *
     * @return bool true se il piano sconto può essere applicato (flag ON o articolo sconosciuto),
     *              false se l'articolo ha esplicitamente disattivato lo sconto su articolo
     */
    function mncs_sconto_articolo_attivo($id_articolo): bool
    {
        // Righe generiche/senza articolo a magazzino: nessun gate, comportamento storico.
        if (empty($id_articolo)) {
            return true;
        }

        $row = database()->fetchOne('SELECT mncs_sconto_su_articolo FROM mg_articoli WHERE id = '.prepare($id_articolo));

        // Articolo non trovato → fallback sicuro al comportamento storico (applica).
        return $row === null ? true : !empty($row['mncs_sconto_su_articolo']);
    }
}
