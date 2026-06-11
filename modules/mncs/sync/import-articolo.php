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

// Endpoint custom MNCS: sync server-to-server di articoli + listini da k-odin → OSM.
// Chiamato dal worker Node (queue osm-sync-products) sulla rete Docker interna.
//
// Riusa la logica DB-side dell'importer ufficiale Articoli (via ArticoloSync, senza CSV)
// per l'upsert su `mg_articoli`, e scrive i 9 listini k-odin (EV1..EV5 + AUX1..AUX4) in
// `mg_listini_articoli`. NON movimenta il magazzino (nessuna `qta` nel record) e NON tocca
// i prezzi per-anagrafica (`mg_prezzi_articoli`).
//
// Auth: header `X-Osm-Sync-Secret` confrontato con la env `OSM_SYNC_SECRET` (shared secret).

header('Content-Type: application/json; charset=utf-8');

// ── Auth (prima di bootstrap, per rifiutare in fretta) ───────────────────────
$secret = (string) (getenv('OSM_SYNC_SECRET') ?: '');
$provided = (string) ($_SERVER['HTTP_X_OSM_SYNC_SECRET'] ?? '');
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);

    exit;
}

// ── Parse body ───────────────────────────────────────────────────────────────
$input = json_decode((string) file_get_contents('php://input'), true);
$prodotti = is_array($input) ? ($input['prodotti'] ?? null) : null;
if (!is_array($prodotti)) {
    http_response_code(400);
    echo json_encode(['error' => 'body non valido: atteso { "prodotti": [...] }']);

    exit;
}

// ── Bootstrap OSM ────────────────────────────────────────────────────────────
$skip_permissions = true;
include_once __DIR__.'/../../../core.php';

require_once __DIR__.'/ArticoloSync.php';

// Mappa chiave k-odin → nome listino OSM (creato da modules/mncs/update/1_5.sql).
$listino_nomi = [
    'EV1' => 'Effettivo Vendita 1 [EV1]',
    'EV2' => 'Effettivo Vendita 2 [EV2]',
    'EV3' => 'Effettivo Vendita 3 [EV3]',
    'EV4' => 'Effettivo Vendita 4 [EV4]',
    'EV5' => 'Effettivo Vendita 5 [EV5]',
    'AUX1' => 'Ausiliario 1 [AUX1]',
    'AUX2' => 'Ausiliario 2 [AUX2]',
    'AUX3' => 'Ausiliario 3 [AUX3]',
    'AUX4' => 'Ausiliario 4 [AUX4]',
];

// Risolvi (con fallback di creazione idempotente) gli id_listino una sola volta.
$listino_ids = [];
foreach ($listino_nomi as $key => $nome) {
    $row = database()->fetchOne('SELECT `id` FROM `mg_listini` WHERE `nome` = '.prepare($nome));
    if (empty($row)) {
        database()->insert('mg_listini', [
            'nome' => $nome,
            'data_attivazione' => null,
            'data_scadenza_predefinita' => null,
            'is_sempre_visibile' => 1,
            'attivo' => 1,
            'note' => '',
        ]);
        $listino_ids[$key] = database()->lastInsertedID();
    } else {
        $listino_ids[$key] = $row['id'];
    }
}

// Campi opzionali passati direttamente all'importer (solo se valorizzati).
$campi_opzionali = ['prezzo_acquisto', 'prezzo_vendita', 'categoria', 'marca', 'modello', 'barcode', 'um', 'codice_iva_vendita', 'note', 'peso_lordo'];

$importer = new \Modules\Mncs\Sync\ArticoloSync();
$results = ['imported' => 0, 'failed' => 0, 'errors' => []];

foreach ($prodotti as $p) {
    $codice = trim((string) ($p['codice'] ?? ''));
    if ($codice === '') {
        ++$results['failed'];
        $results['errors'][] = 'codice mancante';
        continue;
    }

    try {
        $descrizione = trim((string) ($p['descrizione'] ?? ''));
        if ($descrizione === '') {
            throw new \Exception('descrizione mancante');
        }

        // Record per l'importer: niente qta/data_qta (no movimenti magazzino),
        // niente anagrafica_listino/prezzo_listino (no prezzi per-anagrafica).
        $record = ['codice' => $codice, 'descrizione' => $descrizione];
        foreach ($campi_opzionali as $k) {
            if (isset($p[$k]) && $p[$k] !== '' && $p[$k] !== null) {
                $record[$k] = $p[$k];
            }
        }

        $esito = $importer->import($record, true, true);
        if ($esito === false) {
            $errs = $importer->getFailedErrors();
            throw new \Exception(!empty($errs) ? (string) end($errs) : 'import articolo fallito');
        }

        $articolo = \Modules\Articoli\Articolo::where('codice', $codice)->first();
        if (empty($articolo)) {
            throw new \Exception('articolo non trovato dopo import');
        }

        // Listini EV1..EV5 + AUX1..AUX4 → mg_listini_articoli (dir 'entrata' = vendita).
        // Ogni listino porta gli scaglioni già pronti come range [minimo, massimo]
        // calcolati lato k-odin: una riga per scaglione.
        $listini = is_array($p['listini'] ?? null) ? $p['listini'] : [];
        foreach ($listino_ids as $key => $id_listino) {
            $tiers = is_array($listini[$key] ?? null) ? $listini[$key] : [];

            // delete + rebuild: rimuove righe stale e ricrea uno scaglione per riga.
            database()->delete('mg_listini_articoli', [
                'id_articolo' => $articolo->id,
                'id_listino' => $id_listino,
                'dir' => 'entrata',
            ]);

            foreach ($tiers as $tier) {
                if (!isset($tier['prezzo'], $tier['minimo'], $tier['massimo'])) {
                    continue;
                }

                $riga = \Modules\ListiniCliente\Articolo::build($articolo, $id_listino, 'entrata');
                $riga->minimo = $tier['minimo'];
                $riga->massimo = $tier['massimo'];
                $riga->sconto_percentuale = 0;
                $riga->setPrezzoUnitario($tier['prezzo']);
                $riga->save();
            }
        }

        ++$results['imported'];
    } catch (\Throwable $e) {
        ++$results['failed'];
        $results['errors'][] = $codice.': '.$e->getMessage();
    }
}

echo json_encode($results);
