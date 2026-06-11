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

namespace Modules\Mncs\Sync;

/**
 * Importer articoli k-odin → OSM senza CSV.
 *
 * Riusa tutta la logica DB-side dell'importer ufficiale degli Articoli
 * (Modules\Articoli\Import\CSV::import()): auto-creazione categoria/marca/modello,
 * setPrezzoVendita IVA-aware, barcode, traduzioni. L'unica differenza è il costruttore:
 * quello di CSVImporter richiede un file (League\Csv\Reader::createFromPath), che qui
 * non abbiamo. Lo bypassiamo e impostiamo direttamente la chiave primaria su `codice`,
 * così `import($record, true, true)` lavora su un array associativo già pronto.
 *
 * NB: dipende dalla firma di import() upstream → ricontrollare al merge di upstream
 * (vedi openstamanager/CUSTOM.md).
 */
class ArticoloSync extends \Modules\Articoli\Import\CSV
{
    public function __construct()
    {
        // Volutamente NON chiamiamo parent::__construct(): eviterebbe Reader::createFromPath()
        // su un file inesistente. import() non usa il reader, solo getPrimaryKey()/$record.
        $this->primary_key = 'codice';
        $this->column_associations = [];
    }
}
