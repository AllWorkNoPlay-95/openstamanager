-- Importer CSV "Giacenze magazzino": registra la voce nel menu Strumenti -> Importa.
-- La classe Modules\Articoli\Import\GiacenzeCSV vive in modules/articoli/custom/src/Import/
-- (override path PSR-4, nessun file core toccato) e aggiorna le giacenze per sede
-- (Feroleto -> id_sede 1, Rende -> id_sede 0) come rettifica di inventario.
--
-- Il dropdown in modules/import/add.php legge zz_imports_lang.title per la lingua
-- predefinita (id_lang = 1): senza la riga lang la voce non comparirebbe nel menu.
--
-- Idempotente: ogni INSERT e' guardato da NOT EXISTS (gli update custom girano da
-- zero su ogni installazione).

INSERT INTO `zz_imports` (`name`, `class`)
SELECT 'Giacenze magazzino', 'Modules\\Articoli\\Import\\GiacenzeCSV'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `zz_imports` WHERE `class` = 'Modules\\Articoli\\Import\\GiacenzeCSV'
);

INSERT INTO `zz_imports_lang` (`id_lang`, `id_record`, `title`)
SELECT 1,
    (SELECT `id` FROM `zz_imports` WHERE `class` = 'Modules\\Articoli\\Import\\GiacenzeCSV'),
    'Giacenze magazzino'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `zz_imports_lang`
    WHERE `id_lang` = 1
        AND `id_record` = (SELECT `id` FROM `zz_imports` WHERE `class` = 'Modules\\Articoli\\Import\\GiacenzeCSV')
);
