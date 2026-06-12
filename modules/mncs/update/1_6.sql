-- Feature MNCS: Export GECOM (tracciato TRAF TeamSystem) per fatture di vendita e note di credito.
--
-- Registra il modulo "Export GECOM" sotto Contabilità (pagina custom con filtro periodo/stato,
-- vedi modules/mncs_export_gecom/) e le impostazioni di mappatura verso i codici del gestionale
-- GECOM del commercialista. Tutto additivo e idempotente: nessun file core toccato, i valori
-- impostati dall'utente non vengono sovrascritti alle riesecuzioni.

-- 1) Modulo "Export GECOM" sotto Contabilità (additivo, inserito solo se assente) -------------
SET @mncs_parent_contabilita := (SELECT `id_record` FROM `zz_modules_lang` WHERE `title` = 'Contabilità' AND `id_lang` = 1 LIMIT 1);
SET @mncs_mod := (SELECT `id` FROM `zz_modules` WHERE `directory` = 'mncs_export_gecom' LIMIT 1);

INSERT INTO `zz_modules`
  (`name`, `directory`, `attachments_directory`, `options`, `options2`, `icon`, `version`, `compatibility`, `order`, `parent`, `default`, `enabled`)
SELECT
  'Export GECOM', 'mncs_export_gecom', 'mncs_export_gecom',
  'custom', '', 'fa fa-download', '2.11', '2.11', 20,
  @mncs_parent_contabilita, 1, 1
FROM DUAL
WHERE @mncs_mod IS NULL;

SET @mncs_mod := (SELECT `id` FROM `zz_modules` WHERE `directory` = 'mncs_export_gecom' LIMIT 1);

DELETE FROM `zz_modules_lang` WHERE `id_record` = @mncs_mod;
INSERT INTO `zz_modules_lang` (`id_lang`, `id_record`, `title`, `meta_title`) VALUES
  (1, @mncs_mod, 'Export GECOM', 'Export GECOM'),
  (2, @mncs_mod, 'GECOM export', 'GECOM export');

-- 2) Impostazioni (sezione "Export GECOM", inserite solo se assenti) --------------------------

-- 2a) Codice ditta
SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM codice ditta' LIMIT 1);

INSERT INTO `zz_settings` (`nome`, `valore`, `tipo`, `editable`, `sezione`, `is_user_setting`)
SELECT 'GECOM codice ditta', '000013', 'string', 1, 'Export GECOM', 0
FROM DUAL
WHERE @mncs_set IS NULL;

SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM codice ditta' LIMIT 1);

DELETE FROM `zz_settings_lang` WHERE `id_record` = @mncs_set;
INSERT INTO `zz_settings_lang` (`id_lang`, `id_record`, `title`, `help`) VALUES
  (1, @mncs_set, 'GECOM codice ditta', 'Codice ditta a 6 cifre del gestionale GECOM di destinazione (posizioni 1-6 di ogni record TRAF).'),
  (2, @mncs_set, 'GECOM company code', '6-digit company code of the target GECOM installation.');

-- 2b) Conto ricavo di fallback
SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM conto ricavo' LIMIT 1);

INSERT INTO `zz_settings` (`nome`, `valore`, `tipo`, `editable`, `sezione`, `is_user_setting`)
SELECT 'GECOM conto ricavo', '5805010', 'string', 1, 'Export GECOM', 0
FROM DUAL
WHERE @mncs_set IS NULL;

SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM conto ricavo' LIMIT 1);

DELETE FROM `zz_settings_lang` WHERE `id_record` = @mncs_set;
INSERT INTO `zz_settings_lang` (`id_lang`, `id_record`, `title`, `help`) VALUES
  (1, @mncs_set, 'GECOM conto ricavo', 'Conto GECOM (7 cifre) usato come contropartita per i conti OSM non presenti nella mappa conti.'),
  (2, @mncs_set, 'GECOM revenue account', '7-digit GECOM account used as fallback for OSM accounts missing from the accounts map.');

-- 2c) Mappa IVA (id co_iva -> codice IVA GECOM a 3 cifre)
SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM mappa IVA' LIMIT 1);

INSERT INTO `zz_settings` (`nome`, `valore`, `tipo`, `editable`, `sezione`, `is_user_setting`)
SELECT 'GECOM mappa IVA', '{}', 'textarea', 1, 'Export GECOM', 0
FROM DUAL
WHERE @mncs_set IS NULL;

SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM mappa IVA' LIMIT 1);

DELETE FROM `zz_settings_lang` WHERE `id_record` = @mncs_set;
INSERT INTO `zz_settings_lang` (`id_lang`, `id_record`, `title`, `help`) VALUES
  (1, @mncs_set, 'GECOM mappa IVA', 'JSON con id aliquota OSM -> codice GECOM a 3 cifre, es. {''161'': ''022'', ''163'': ''004''} (nel valore usare i doppi apici standard JSON). Per le aliquote ordinarie il codice è la percentuale (022, 010, 004); per esenzioni/non imponibili usare il codice GECOM dedicato (es. 315, 374). Le aliquote usate nel periodo ma non mappate bloccano l''export.'),
  (2, @mncs_set, 'GECOM VAT map', 'JSON: OSM VAT id -> 3-digit GECOM code.');

-- 2d) Mappa conti (id co_piano_dei_conti3 -> conto GECOM a 7 cifre)
SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM mappa conti' LIMIT 1);

INSERT INTO `zz_settings` (`nome`, `valore`, `tipo`, `editable`, `sezione`, `is_user_setting`)
SELECT 'GECOM mappa conti', '{}', 'textarea', 1, 'Export GECOM', 0
FROM DUAL
WHERE @mncs_set IS NULL;

SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM mappa conti' LIMIT 1);

DELETE FROM `zz_settings_lang` WHERE `id_record` = @mncs_set;
INSERT INTO `zz_settings_lang` (`id_lang`, `id_record`, `title`, `help`) VALUES
  (1, @mncs_set, 'GECOM mappa conti', 'JSON con id conto OSM (co_piano_dei_conti3) -> conto GECOM a 7 cifre, es. {''34'': ''5805010''} (nel valore usare i doppi apici standard JSON). I conti non mappati usano il conto dell''impostazione GECOM conto ricavo.'),
  (2, @mncs_set, 'GECOM accounts map', 'JSON: OSM account id -> 7-digit GECOM account.');

-- 2e) Mappa documenti (id co_tipi_documento -> causale/sezionale GECOM)
SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM mappa documenti' LIMIT 1);

INSERT INTO `zz_settings` (`nome`, `valore`, `tipo`, `editable`, `sezione`, `is_user_setting`)
SELECT 'GECOM mappa documenti', '{}', 'textarea', 1, 'Export GECOM', 0
FROM DUAL
WHERE @mncs_set IS NULL;

SET @mncs_set := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'GECOM mappa documenti' LIMIT 1);

DELETE FROM `zz_settings_lang` WHERE `id_record` = @mncs_set;
INSERT INTO `zz_settings_lang` (`id_lang`, `id_record`, `title`, `help`) VALUES
  (1, @mncs_set, 'GECOM mappa documenti', 'JSON con id tipo documento OSM -> oggetto con chiavi causale, descrizione, sezionale, nota_credito. Es. {''43'': {''causale'': ''001'', ''descrizione'': ''Fattura di vend'', ''sezionale'': ''1'', ''nota_credito'': false}} (nel valore usare i doppi apici standard JSON). Solo i tipi presenti nella mappa vengono esportati. Causali osservate: 001 fattura di vendita, 002 nota credito cliente. Sezionali: 1 = FE (Feroleto), 0 = RE (Rende), 6 = NF, 5 = NR.'),
  (2, @mncs_set, 'GECOM documents map', 'JSON: OSM document type id -> object with causale, descrizione, sezionale, nota_credito keys. Only mapped types are exported.');
