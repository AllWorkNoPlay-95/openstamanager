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

// Helper custom MNCS: regola di coerenza sconto/listino per le anagrafiche cliente.
//
// Un cliente non può avere assegnati contemporaneamente un piano di sconto vendite "reale"
// (an_anagrafiche.id_piano_sconto_vendite → mg_piani_sconto con prc_guadagno > 0) e un listino
// superiore a EV3 (EV4/EV5): sarebbe un doppio sconto sul prezzo già scontato del listino alto.
// Usato come gate bloccante al salvataggio in modules/anagrafiche/actions.php (case 'update').

if (!function_exists('mncs_cliente_sconto_listino_in_conflitto')) {
    /**
     * Indica se la combinazione listino + piano sconto vendite del cliente è vietata.
     *
     * @param int|string|null $id_listino               id di mg_listini assegnato all'anagrafica
     * @param int|string|null $id_piano_sconto_vendite  id di mg_piani_sconto assegnato all'anagrafica
     *
     * @return bool true se c'è conflitto (listino EV4/EV5 + sconto > 0), false altrimenti
     */
    function mncs_cliente_sconto_listino_in_conflitto($id_listino, $id_piano_sconto_vendite): bool
    {
        // Serve che entrambi siano assegnati perché possa esserci conflitto.
        if (empty($id_listino) || empty($id_piano_sconto_vendite)) {
            return false;
        }

        // Listino "alto": il nome contiene il codice [EV4] o [EV5]. Il codice è l'identificatore
        // stabile (gli id auto-increment variano tra installazioni). Vedi modules/mncs/update/1_5.sql.
        $listino = database()->fetchOne('SELECT nome FROM mg_listini WHERE id = '.prepare($id_listino));
        $nome = $listino === null ? '' : (string) $listino['nome'];
        $listino_alto = str_contains($nome, '[EV4]') || str_contains($nome, '[EV5]');
        if (!$listino_alto) {
            return false;
        }

        // Sconto attivo: piano sconto vendite con prc_guadagno > 0 (solo sconto reale; una
        // maggiorazione, prc_guadagno < 0, è permessa anche sui listini alti).
        $piano = database()->fetchOne('SELECT prc_guadagno FROM mg_piani_sconto WHERE id = '.prepare($id_piano_sconto_vendite));
        $sconto = $piano === null ? 0.0 : floatval($piano['prc_guadagno']);

        return $sconto > 0;
    }
}
