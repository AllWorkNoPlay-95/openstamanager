-- Feature MNCS: abbuono automatico in fase di incasso fattura di vendita.
--
-- Due impostazioni globali (Impostazioni > Fatturazione):
--   - "Conto abbuono": conto su cui registrare la differenza abbuonata (abbuoni passivi).
--   - "Soglia abbuono": differenza massima (residuo - importo incassato) abbuonata
--     automaticamente alla chiusura dell'incasso. Default 0,05 €.
--
-- Schema OSM 2.11: il titolo/help dell'impostazione vive in `zz_settings_lang`.
-- Idempotente: le impostazioni vengono inserite solo se assenti (il valore impostato
-- dall'utente non viene sovrascritto alle riesecuzioni); i titoli vengono ricostruiti.

-- 1) Conto abbuono --------------------------------------------------------------------------
SET @mncs_set_conto := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'Conto abbuono' LIMIT 1);

INSERT INTO `zz_settings` (`nome`, `valore`, `tipo`, `editable`, `sezione`, `is_user_setting`)
SELECT 'Conto abbuono', '',
  'query=SELECT `id`, CONCAT_WS('' - '', `numero`, `descrizione`) AS descrizione FROM `co_piano_dei_conti3` ORDER BY `descrizione`',
  1, 'Fatturazione', 0
FROM DUAL
WHERE @mncs_set_conto IS NULL;

SET @mncs_set_conto := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'Conto abbuono' LIMIT 1);

DELETE FROM `zz_settings_lang` WHERE `id_record` = @mncs_set_conto;
INSERT INTO `zz_settings_lang` (`id_lang`, `id_record`, `title`, `help`) VALUES
  (1, @mncs_set_conto, 'Conto abbuono', 'Conto su cui registrare la differenza abbuonata durante un incasso (abbuoni passivi).'),
  (2, @mncs_set_conto, 'Discount account', 'Account used to post the rounded-off difference during a payment (discounts allowed).');

-- 2) Soglia abbuono -------------------------------------------------------------------------
SET @mncs_set_soglia := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'Soglia abbuono' LIMIT 1);

INSERT INTO `zz_settings` (`nome`, `valore`, `tipo`, `editable`, `sezione`, `is_user_setting`)
SELECT 'Soglia abbuono', '0.05', 'decimal', 1, 'Fatturazione', 0
FROM DUAL
WHERE @mncs_set_soglia IS NULL;

SET @mncs_set_soglia := (SELECT `id` FROM `zz_settings` WHERE `nome` = 'Soglia abbuono' LIMIT 1);

DELETE FROM `zz_settings_lang` WHERE `id_record` = @mncs_set_soglia;
INSERT INTO `zz_settings_lang` (`id_lang`, `id_record`, `title`, `help`) VALUES
  (1, @mncs_set_soglia, 'Soglia abbuono (€)', 'Differenza massima tra residuo della fattura e importo incassato che viene abbuonata automaticamente alla chiusura dell''incasso. Oltre questa soglia l''incasso resta parziale.'),
  (2, @mncs_set_soglia, 'Discount threshold (€)', 'Maximum difference between the invoice balance and the received amount that is automatically written off when registering a payment.');
