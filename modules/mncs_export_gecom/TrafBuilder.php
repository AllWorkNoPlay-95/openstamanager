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

namespace Modules\MncsExportGecom;

use UnexpectedValueException;

/**
 * Costruisce il file TRAF (tracciato TeamSystem GECOM Multi) per le fatture di vendita
 * e le note di credito.
 *
 * Tracciato reverse-engineered (2026-06-12) da due file TRAF reali esportati da TeamSystem
 * Enterprise e validato con roundtrip byte-per-byte su 1995 record (vedi CUSTOM.md).
 *
 * Struttura: record a larghezza fissa di 7000 caratteri terminati da CRLF.
 * Ogni documento produce:
 *   - 1 record testata (tipo '0'): anagrafica cliente inline, causale, date, numeri,
 *     castelletto IVA (max 8 slot), totale e contropartite ricavo (max 8 coppie);
 *   - 1 record tipo '5' opzionale, solo per NC con riferimento alla fattura originaria;
 *   - 1 record continuazione (tipo '1'): numero documento "visivo" (es. 123/FE) e data file.
 *
 * I byte non decodificati restano congelati ai valori costanti osservati nei file di
 * esempio (file template record0/1/5.tpl, privi di dati cliente).
 *
 * Posizioni dei campi: 1-based, [inizio, lunghezza].
 */
class TrafBuilder
{
    public const RECORD_LENGTH = 7000;
    public const EOL = "\r\n";

    /** Slot castelletto IVA: base 475 + 31*(n-1), layout [imponibile 12][codice IVA 3][filler '0000'][imposta 12]. */
    public const CASTELLETTO_SLOTS = 8;

    /** Contropartite: base 735 + 19*(n-1), layout [conto 7][importo 12]. */
    public const CONTROPARTITE_SLOTS = 8;

    /** @var string Codice ditta GECOM (6 cifre), posizioni 1-6 di ogni record. */
    private $ditta;

    /** @var string Data file (YYMMDD) scritta nel record continuazione, pos. 5912-5917. */
    private $dataFile;

    /** @var string[] Template dei 3 tipi record, indicizzati per tipo ('0', '1', '5'). */
    private $templates = [];

    public function __construct(string $codiceDitta, string $dataFile)
    {
        if (!preg_match('/^\d{6}$/', $codiceDitta)) {
            throw new UnexpectedValueException('Codice ditta GECOM non valido: atteso 6 cifre, ricevuto "'.$codiceDitta.'"');
        }
        $this->ditta = $codiceDitta;
        $this->dataFile = date('ymd', strtotime($dataFile));

        foreach (['0', '1', '5'] as $tipo) {
            $template = file_get_contents(__DIR__.'/templates/record'.$tipo.'.tpl');
            if ($template === false || strlen($template) !== self::RECORD_LENGTH) {
                throw new UnexpectedValueException('Template record'.$tipo.'.tpl mancante o di lunghezza errata');
            }
            $this->templates[$tipo] = $template;
        }
    }

    /**
     * Costruisce il contenuto completo del file TRAF.
     *
     * @param array $documenti elenco di documenti come da buildDocument()
     */
    public function build(array $documenti): string
    {
        $content = '';
        foreach ($documenti as $documento) {
            $content .= $this->buildDocument($documento);
        }

        return $content;
    }

    /**
     * Costruisce i record di un singolo documento (testata + eventuale tipo 5 + continuazione).
     *
     * Campi attesi in $doc:
     *  - ragione_sociale, indirizzo, cap, citta, provincia, codice_fiscale, partita_iva,
     *    telefono (string), persona_fisica (bool)
     *  - causale ('001'/'002'), causale_descrizione (max 15 char), sezionale (1 cifra)
     *  - data ('Y-m-d'), numero (int), anno (int), numero_descrittivo (es. '123/FE')
     *  - castelletto: array di ['imponibile' => centesimi (int, con segno),
     *                           'codice_iva' => 3 cifre GECOM, 'imposta' => centesimi]
     *  - contropartite: array di ['conto' => 7 cifre GECOM, 'importo' => centesimi]
     *  - totale: centesimi (int)
     *  - riferimento: null oppure ['numero' => int, 'data' => 'Y-m-d'] (solo NC)
     */
    public function buildDocument(array $doc): string
    {
        $records = $this->testata($doc);

        if (!empty($doc['riferimento'])) {
            $records .= $this->riferimento($doc['riferimento']);
        }

        $records .= $this->continuazione($doc);

        return $records;
    }

    private function testata(array $doc): string
    {
        if (count($doc['castelletto']) > self::CASTELLETTO_SLOTS) {
            throw new UnexpectedValueException('Documento '.$doc['numero_descrittivo'].': più di '.self::CASTELLETTO_SLOTS.' aliquote IVA');
        }
        if (count($doc['contropartite']) > self::CONTROPARTITE_SLOTS) {
            throw new UnexpectedValueException('Documento '.$doc['numero_descrittivo'].': più di '.self::CONTROPARTITE_SLOTS.' contropartite');
        }

        $record = $this->templates['0'];

        $this->write($record, 1, 6, $this->ditta);
        $this->write($record, 7, 1, '0');                  // tipo record

        // Anagrafica cliente inline
        $this->write($record, 13, 32, $this->padAN($doc['ragione_sociale'], 32));
        $this->write($record, 45, 30, $this->padAN($doc['indirizzo'], 30));
        $this->write($record, 75, 5, $this->padAN($doc['cap'], 5));
        $this->write($record, 80, 25, $this->padAN($doc['citta'], 25));
        $this->write($record, 105, 2, $this->padAN($doc['provincia'], 2));
        $this->write($record, 107, 16, $this->padAN($doc['codice_fiscale'], 16));
        $this->write($record, 123, 11, $this->padAN($doc['partita_iva'], 11));
        $this->write($record, 134, 1, !empty($doc['persona_fisica']) ? 'S' : 'N');
        $this->write($record, 209, 20, $this->padAN($doc['telefono'], 20));

        // Codice cliente del gestionale di destinazione: non noto a OSM, lasciato a 0000
        // (l'abbinamento avviene tramite P.IVA/CF presenti nel record).
        $this->write($record, 253, 4, '0000');

        // Causale contabile
        $this->write($record, 268, 3, $this->padN($doc['causale'], 3));
        $this->write($record, 271, 15, $this->padAN($doc['causale_descrizione'], 15));

        // Date e numerazione
        $dataDoc = date('dmY', strtotime($doc['data']));
        $numero = $this->padN($doc['numero'], 6);
        $this->write($record, 372, 8, $dataDoc);            // data documento
        $this->write($record, 380, 8, $dataDoc);            // data registrazione
        $this->write($record, 388, 7, $numero.'0');         // numero documento (+ suffisso bis '0')
        $this->write($record, 395, 7, $numero.'0');         // numero protocollo
        $this->write($record, 402, 1, $this->padN($doc['sezionale'], 1));
        $this->write($record, 403, 6, $numero);
        $this->write($record, 409, 4, $this->padN($doc['anno'], 4));
        $this->write($record, 413, 4, '0100');              // costante osservata in tutti i record

        // Competenza (mese/anno della data di registrazione)
        $this->write($record, 469, 6, date('mY', strtotime($doc['data'])));

        // Castelletto IVA
        foreach (array_values($doc['castelletto']) as $i => $slot) {
            $base = 475 + 31 * $i;
            $this->write($record, $base, 12, $this->amount($slot['imponibile'], 12));
            $this->write($record, $base + 12, 3, $this->padN($slot['codice_iva'], 3));
            $this->write($record, $base + 15, 4, '0000');
            $this->write($record, $base + 19, 12, $this->amount($slot['imposta'], 12));
        }

        // Totale documento
        $this->write($record, 723, 12, $this->amount($doc['totale'], 12));

        // Contropartite ricavo
        foreach (array_values($doc['contropartite']) as $i => $coppia) {
            if ($coppia['importo'] < 0) {
                throw new UnexpectedValueException('Documento '.$doc['numero_descrittivo'].': contropartita negativa sul conto '.$coppia['conto'].' non supportata');
            }
            $base = 735 + 19 * $i;
            $this->write($record, $base, 7, $this->padN($coppia['conto'], 7));
            $this->write($record, $base + 7, 12, $this->amount($coppia['importo'], 12));
        }

        $this->write($record, 6962, 1, '0');                // costante osservata
        $this->write($record, 6995, 1, !empty($doc['riferimento']) ? 'S' : ' ');

        return $this->seal($record);
    }

    /** Record tipo '5': estremi della fattura originaria collegata a una nota di credito. */
    private function riferimento(array $riferimento): string
    {
        $record = $this->templates['5'];

        $this->write($record, 1, 6, $this->ditta);
        $this->write($record, 7, 1, '5');                  // tipo record
        $this->write($record, 6708, 8, $this->padN($riferimento['numero'], 6).'00');
        $this->write($record, 6716, 8, date('dmY', strtotime($riferimento['data'])));

        return $this->seal($record);
    }

    /** Record tipo '1' (continuazione): numero documento "visivo" e data file. */
    private function continuazione(array $doc): string
    {
        $record = $this->templates['1'];

        $this->write($record, 1, 6, $this->ditta);
        $this->write($record, 7, 1, '1');                  // tipo record
        $this->write($record, 5894, 18, $this->padAN($doc['numero_descrittivo'], 18));
        $this->write($record, 5912, 6, $this->dataFile);

        return $this->seal($record);
    }

    /** Sovrascrive $len caratteri di $record a partire dalla posizione 1-based $pos. */
    private function write(string &$record, int $pos, int $len, string $value): void
    {
        if (strlen($value) !== $len) {
            throw new UnexpectedValueException('Valore "'.$value.'" di lunghezza errata per il campo a posizione '.$pos.' (attesi '.$len.' caratteri)');
        }
        $record = substr_replace($record, $value, $pos - 1, $len);
    }

    /** Verifica finale di lunghezza: mai produrre un file corrotto. */
    private function seal(string $record): string
    {
        if (strlen($record) !== self::RECORD_LENGTH) {
            throw new UnexpectedValueException('Record di lunghezza '.strlen($record).' anziché '.self::RECORD_LENGTH);
        }

        return $record.self::EOL;
    }

    /** Campo alfanumerico: translitterazione ASCII, troncamento e padding a destra con spazi. */
    private function padAN(?string $value, int $len): string
    {
        // Accenti italiani gestiti esplicitamente (iconv//TRANSLIT è dipendente dal sistema)
        $ascii = strtr((string) $value, [
            'à' => 'a', 'è' => 'e', 'é' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
            '°' => ' ', '€' => 'E',
        ]);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ascii);
        $ascii = preg_replace('/[^\x20-\x7E]/', ' ', (string) $ascii);

        return str_pad(substr($ascii, 0, $len), $len);
    }

    /** Campo numerico: zero-fill a sinistra. */
    private function padN($value, int $len): string
    {
        $digits = preg_replace('/\D/', '', (string) $value);
        if (strlen($digits) > $len) {
            throw new UnexpectedValueException('Valore numerico "'.$value.'" troppo lungo per '.$len.' cifre');
        }

        return str_pad($digits, $len, '0', STR_PAD_LEFT);
    }

    /**
     * Importo in centesimi: zero-fill; se negativo il segno '-' sostituisce il primo zero
     * (convenzione osservata nei file di esempio).
     */
    private function amount(int $cents, int $len): string
    {
        if ($cents >= 0) {
            return $this->padN($cents, $len);
        }

        return '-'.$this->padN(abs($cents), $len - 1);
    }
}
