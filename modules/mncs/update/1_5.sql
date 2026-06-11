-- Sync prodotti/listini da k-odin: 9 listini dedicati in `mg_listini`.
--
-- k-odin gestisce 5 listini di vendita "Effettivi Vendita" (EV1..EV5) + 4 ausiliari
-- "AUX" (EX1..EX4). Questo update crea le 9 righe corrispondenti in OSM, una volta,
-- in modo che l'endpoint di sync (modules/mncs/sync/import-articolo.php) possa
-- scrivere i prezzi per-articolo in `mg_listini_articoli` risolvendo l'id per `nome`.
--
-- Idempotente (gira da zero su ogni installazione): inserisce solo i `nome` mancanti.
-- `note` è NOT NULL → stringa vuota; `is_sempre_visibile`/`attivo` = 1.
INSERT INTO `mg_listini` (`nome`, `data_attivazione`, `data_scadenza_predefinita`, `is_sempre_visibile`, `attivo`, `note`)
SELECT `v`.`nome`, NULL, NULL, 1, 1, ''
FROM (
    SELECT 'Effettivo Vendita 1 [EV1]' AS `nome`
    UNION ALL SELECT 'Effettivo Vendita 2 [EV2]' AS `nome`
    UNION ALL SELECT 'Effettivo Vendita 3 [EV3]' AS `nome`
    UNION ALL SELECT 'Effettivo Vendita 4 [EV4]' AS `nome`
    UNION ALL SELECT 'Effettivo Vendita 5 [EV5]' AS `nome`
    UNION ALL SELECT 'Ausiliario 1 [AUX1]' AS `nome`
    UNION ALL SELECT 'Ausiliario 2 [AUX2]' AS `nome`
    UNION ALL SELECT 'Ausiliario 3 [AUX3]' AS `nome`
    UNION ALL SELECT 'Ausiliario 4 [AUX4]' AS `nome`
) AS `v`
WHERE NOT EXISTS (
    SELECT 1 FROM `mg_listini` `m` WHERE `m`.`nome` = `v`.`nome`
);
