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

switch (post('op')) {
    case 'add':
        $id_pagamento = post('id_pagamento');
        $id_sede = (int) post('id_sede');
        $id_conto = post('id_conto');

        // Vincolo logico: una sola riga per (metodo di pagamento + sede)
        $esiste = $dbo->fetchNum('SELECT `id` FROM `mncs_incassi_conti` WHERE `id_pagamento` = '.prepare($id_pagamento).' AND `id_sede` = '.prepare($id_sede));
        if ($esiste) {
            flash()->error(tr('Esiste già una mappatura per questo metodo di pagamento e sede.'));
            break;
        }

        $dbo->insert('mncs_incassi_conti', [
            'id_pagamento' => $id_pagamento,
            'id_sede' => $id_sede,
            'id_conto' => $id_conto,
        ]);
        $id_record = $dbo->lastInsertedID();

        flash()->info(tr('Mappatura aggiunta.'));

        break;

    case 'update':
        $id_pagamento = post('id_pagamento');
        $id_sede = (int) post('id_sede');
        $id_conto = post('id_conto');

        // Vincolo logico: una sola riga per (metodo di pagamento + sede)
        $esiste = $dbo->fetchNum('SELECT `id` FROM `mncs_incassi_conti` WHERE `id_pagamento` = '.prepare($id_pagamento).' AND `id_sede` = '.prepare($id_sede).' AND `id` != '.prepare($id_record));
        if ($esiste) {
            flash()->error(tr('Esiste già una mappatura per questo metodo di pagamento e sede.'));
            break;
        }

        $dbo->update('mncs_incassi_conti', [
            'id_pagamento' => $id_pagamento,
            'id_sede' => $id_sede,
            'id_conto' => $id_conto,
        ], ['id' => $id_record]);

        flash()->info(tr('Mappatura aggiornata.'));

        break;

    case 'delete':
        $dbo->delete('mncs_incassi_conti', ['id' => $id_record]);

        flash()->info(tr('Mappatura eliminata.'));

        break;
}
