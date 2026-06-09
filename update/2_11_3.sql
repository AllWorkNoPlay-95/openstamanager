-- Corregge la query di listato del modulo Articoli: il sottoquery dei barcode usava
-- una derived table correlata (SELECT ... FROM (SELECT ... FROM `mg_articoli_barcode` `b2`
-- WHERE `b2`.`id_articolo` = `mg_articoli_barcode`.`id_articolo` ...) `b1`), costrutto NON
-- supportato da MariaDB (nessuna lateral/correlated derived table) -> errore 1054
-- "Unknown column 'mg_articoli_barcode.id_articolo' in 'WHERE'" all'apertura di Magazzino > Articoli.
-- Il ramo ELSE viene sostituito da un GROUP_CONCAT ordinato, equivalente nel risultato
-- (elenco dei barcode dell'articolo, ordinati) ma valido su MariaDB e MySQL.
UPDATE `zz_modules`
SET `options` = REPLACE(
    `options`,
    'CONCAT((SELECT GROUP_CONCAT(`b1`.`barcode` SEPARATOR ''<br />'') FROM (SELECT `barcode` FROM `mg_articoli_barcode` `b2` WHERE `b2`.`id_articolo` = `mg_articoli_barcode`.`id_articolo` ORDER BY `b2`.`barcode` ASC) `b1`))',
    'GROUP_CONCAT(`mg_articoli_barcode`.`barcode` ORDER BY `mg_articoli_barcode`.`barcode` ASC SEPARATOR ''<br />'')'
)
WHERE `name` = 'Articoli';
