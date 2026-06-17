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

// Helper custom MNCS: ricerca articoli via Elasticsearch k-odin (node-api).
//
// Interroga l'endpoint node-api `GET /prodotti/elastic/search` (lo stesso usato dal
// ProductSelector del frontend Next.js) con filtro `vendita` e `codes_only=1`, ottenendo
// solo l'array di codici in ordine di rilevanza ES. I codici corrispondono 1:1 all'OSM
// `mg_articoli.codice` (= k-odin IFNULL(cod, old_cod)), così select.php può vincolare la
// query nativa a quei codici mantenendo intatto il JSON select2.
//
// Chiamata server-to-server diretta su rete Docker (bypassa nginx → path SENZA `/api`).
// Config via env: OSM_NODE_INTERFACE_URL (default http://node-api:8000) e OSM_NODE_INTERFACE_TOKEN.

if (!function_exists('mncs_elastic_search_articoli_cods')) {
    /**
     * Ricerca articoli su Elasticsearch e ritorna i codici corrispondenti.
     *
     * @param string $search termine di ricerca digitato dall'utente
     * @param int    $size   numero massimo di codici da richiedere a ES
     *
     * @return array|null elenco di codici (stringhe) in ordine di rilevanza ES;
     *                     array vuoto = ES ha risposto senza match;
     *                     null = ES non disponibile / errore → usare il fallback nativo
     */
    function mncs_elastic_search_articoli_cods(string $search, int $size = 100): ?array
    {
        $search = trim($search);
        if ($search === '') {
            return null;
        }

        $base = (string) (getenv('OSM_NODE_INTERFACE_URL') ?: '');
        $token = (string) (getenv('OSM_NODE_INTERFACE_TOKEN') ?: '');
        if ($base === '' || $token === '') {
            return null;
        }

        try {
            // Timeout brevi: la ricerca è nel percorso interattivo (select2 ad ogni tasto) e tiene
            // un worker Apache per la durata della chiamata. Se node-api rallenta, si fa fallback
            // rapido alla ricerca nativa invece di accumulare worker occupati.
            $client = new \GuzzleHttp\Client([
                'timeout' => 1.0,
                'connect_timeout' => 0.5,
            ]);

            $response = $client->request('GET', rtrim($base, '/').'/prodotti/elastic/search', [
                'query' => [
                    'query' => $search,
                    'filter' => 'vendita',
                    'codes_only' => 1,
                    'size' => $size,
                    'from' => 0,
                    'token' => $token,
                ],
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $body = json_decode((string) $response->getBody(), true);
            if (!is_array($body)) {
                return null;
            }

            // Dedup preservando l'ordine di rilevanza ES; scarta valori vuoti/non scalari.
            $cods = [];
            $seen = [];
            foreach ($body as $cod) {
                if (!is_scalar($cod)) {
                    continue;
                }
                $cod = (string) $cod;
                if ($cod === '' || isset($seen[$cod])) {
                    continue;
                }
                $seen[$cod] = true;
                $cods[] = $cod;
            }

            return $cods;
        } catch (\Throwable $e) {
            // ES/node-api irraggiungibile o risposta inattesa → fallback alla ricerca nativa.
            return null;
        }
    }
}
