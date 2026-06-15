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

namespace Modules\Articoli\Import;

use Carbon\Carbon;
use Importer\CSVImporter;
use Modules\Articoli\Articolo;

/**
 * Importazione (da CSV) delle giacenze di magazzino per sede.
 *
 * Formato file: una riga per articolo con SKU (codice) e le quantità per le due sedi.
 * Le quantità sono interpretate come valori ASSOLUTI (inventario): per ogni sede si
 * calcola il delta rispetto alla giacenza attuale e si registra un singolo movimento
 * di rettifica in mg_movimenti. Una cella vuota lascia invariata quella sede.
 *
 * Mappatura sedi (odin -> OpenSTAManager id_sede):
 *   - Feroleto (odin sede 1) -> id_sede 1 (an_sedi "Feroleto Antico")
 *   - Rende    (odin sede 2) -> id_sede 0 (Sede legale azienda predefinita)
 *
 * Custom fork Kartiell.
 */
class GiacenzeCSV extends CSVImporter
{
    /**
     * Associazione id_sede (OpenSTAManager) -> campo CSV.
     */
    protected const SEDI = [
        1 => 'qta_feroleto',
        0 => 'qta_rende',
    ];

    public function getAvailableFields()
    {
        return [
            [
                'field' => 'codice',
                'label' => 'Codice',
                'primary_key' => true,
                'required' => true,
            ],
            [
                'field' => 'qta_feroleto',
                'label' => 'Qt&agrave; Feroleto (sede 1)',
                'names' => [
                    'Feroleto',
                    'Qta Feroleto',
                    'Quantità Feroleto',
                    'qta_sede_1',
                ],
            ],
            [
                'field' => 'qta_rende',
                'label' => 'Qt&agrave; Rende (sede 2)',
                'names' => [
                    'Rende',
                    'Qta Rende',
                    'Quantità Rende',
                    'qta_sede_2',
                ],
            ],
            [
                'field' => 'data',
                'label' => 'Data inventario',
                'names' => [
                    'Data',
                    'Data inventario',
                ],
            ],
        ];
    }

    public function import($record, $update_record = true, $add_record = true)
    {
        if (empty($record['codice'])) {
            throw new \Exception('Codice articolo mancante');
        }

        $articolo = Articolo::where('codice', $record['codice'])->first();
        if (empty($articolo)) {
            throw new \Exception('Articolo non trovato: '.$record['codice']);
        }

        $data = $this->parseData($record['data'] ?? '');
        $giacenze = $articolo->getGiacenze($data->format('Y-m-d'));

        foreach (self::SEDI as $id_sede => $field) {
            // Cella vuota: si lascia invariata la sede (solo uno 0 esplicito azzera).
            if (!isset($record[$field]) || trim((string) $record[$field]) === '') {
                continue;
            }

            $valore = str_replace(',', '.', trim((string) $record[$field]));
            if (!is_numeric($valore)) {
                throw new \Exception('Quantità non valida ('.$field.'): '.$record[$field].' [codice '.$record['codice'].']');
            }

            $nuova_qta = (float) $valore;
            $attuale = isset($giacenze[$id_sede]) ? (float) $giacenze[$id_sede][0] : 0;
            $delta = $nuova_qta - $attuale;

            if ($delta != 0) {
                $articolo->movimenta($delta, tr('Inventario da importazione'), $data, true, [
                    'id_sede' => $id_sede,
                ]);
            }
        }

        return true;
    }

    public static function getExample()
    {
        return [
            ['Codice', 'Qta Feroleto', 'Qta Rende', 'Data inventario'],
            ['OSM-BUDGET', '10', '5', date('d/m/Y')],
        ];
    }

    /**
     * La createExample del core non crea la cartella di destinazione: alla prima
     * generazione dell'esempio (prima che sia mai stato caricato un CSV) la cartella
     * files/import puo' non esistere e fopen() fallisce -> il bottone "Scarica esempio
     * CSV" non produce nulla. Garantiamo la cartella prima di delegare al core,
     * come gia' fa saveFailedRecordsWithErrors().
     */
    #[\Override]
    public static function createExample($filepath)
    {
        $dir = dirname((string) $filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        parent::createExample($filepath);
    }

    /**
     * Interpreta la data inventario con fallback a oggi.
     */
    protected function parseData($valore): Carbon
    {
        $valore = trim((string) $valore);
        if ($valore === '') {
            return Carbon::now();
        }

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y', 'Y/m/d'] as $formato) {
            try {
                $data = Carbon::createFromFormat($formato, $valore);
                if ($data) {
                    return $data;
                }
            } catch (\Exception) {
                continue;
            }
        }

        return Carbon::now();
    }
}
