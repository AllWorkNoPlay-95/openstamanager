-- Feature MNCS: registrazione rapida incasso da Dettaglio fattura di vendita -> Prima Nota.
--
-- Mappa (metodo di pagamento + sede) -> conto contropartita (cassa/banca) usata per sostituire
-- dinamicamente il conto aziendale nel movimento di prima nota generato dall'incasso.
--   id_sede = 0  -> Sede legale (coerente con `co_tipi_documento.mncs_id_sede_partenza`)
--   id_sede > 0  -> `an_sedi.id`
--
-- Registra anche il modulo gestionale "Incassi conti" sotto Strumenti > Tabelle (puramente
-- additivo: nessun file core toccato). Idempotente: gira da zero su ogni installazione.

-- 1) Tabella mappa --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mncs_incassi_conti` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `id_pagamento` INT(11) NOT NULL,
  `id_sede` INT(11) NOT NULL DEFAULT 0,
  `id_conto` INT(11) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mncs_incassi_conti_pagamento_sede` (`id_pagamento`, `id_sede`)
) ENGINE=InnoDB;

-- 2) Modulo gestionale sotto "Tabelle" (additivo, idempotente) ------------------------------
SET @mncs_parent_tabelle := (SELECT `id` FROM `zz_modules` WHERE `name` = 'Tabelle' LIMIT 1);
SET @mncs_mod_incassi := (SELECT `id` FROM `zz_modules` WHERE `name` = 'Incassi conti' LIMIT 1);

INSERT INTO `zz_modules`
  (`name`, `title`, `directory`, `options`, `options2`, `icon`, `version`, `compatibility`, `order`, `parent`, `default`, `enabled`)
SELECT
  'Incassi conti',
  'Incassi: conto per metodo e sede',
  'mncs_incassi_conti',
  'SELECT |select| FROM `mncs_incassi_conti` WHERE 1=1 HAVING 2=2',
  '',
  'fa fa-euro',
  '2.11', '2.*', '10',
  @mncs_parent_tabelle,
  '1', '1'
FROM DUAL
WHERE @mncs_mod_incassi IS NULL;

-- (Ri)leggo l'id del modulo per le viste
SET @mncs_mod_incassi := (SELECT `id` FROM `zz_modules` WHERE `name` = 'Incassi conti' LIMIT 1);

-- 3) Colonne del listato (zz_views), ognuna guardata per idempotenza ------------------------
SET @mncs_v := (SELECT `id` FROM `zz_views` WHERE `id_module` = @mncs_mod_incassi AND `name` = 'id' LIMIT 1);
INSERT INTO `zz_views` (`id_module`, `name`, `query`, `order`, `search`, `slow`, `default`, `visible`)
SELECT @mncs_mod_incassi, 'id', 'mncs_incassi_conti.id', 1, 1, 0, 1, 0
FROM DUAL WHERE @mncs_v IS NULL;

SET @mncs_v := (SELECT `id` FROM `zz_views` WHERE `id_module` = @mncs_mod_incassi AND `name` = 'Metodo di pagamento' LIMIT 1);
INSERT INTO `zz_views` (`id_module`, `name`, `query`, `order`, `search`, `slow`, `default`, `visible`)
SELECT @mncs_mod_incassi, 'Metodo di pagamento',
  '(SELECT `name` FROM `co_pagamenti` WHERE `co_pagamenti`.`id` = `mncs_incassi_conti`.`id_pagamento`)',
  2, 1, 0, 1, 1
FROM DUAL WHERE @mncs_v IS NULL;

SET @mncs_v := (SELECT `id` FROM `zz_views` WHERE `id_module` = @mncs_mod_incassi AND `name` = 'Sede' LIMIT 1);
INSERT INTO `zz_views` (`id_module`, `name`, `query`, `order`, `search`, `slow`, `default`, `visible`)
SELECT @mncs_mod_incassi, 'Sede',
  'IF(`mncs_incassi_conti`.`id_sede` = 0, ''Sede legale'', (SELECT `nome_sede` FROM `an_sedi` WHERE `an_sedi`.`id` = `mncs_incassi_conti`.`id_sede`))',
  3, 1, 0, 1, 1
FROM DUAL WHERE @mncs_v IS NULL;

SET @mncs_v := (SELECT `id` FROM `zz_views` WHERE `id_module` = @mncs_mod_incassi AND `name` = 'Conto' LIMIT 1);
INSERT INTO `zz_views` (`id_module`, `name`, `query`, `order`, `search`, `slow`, `default`, `visible`)
SELECT @mncs_mod_incassi, 'Conto',
  '(SELECT CONCAT(`co_piano_dei_conti2`.`numero`, ''.'', `co_piano_dei_conti3`.`numero`, '' '', `co_piano_dei_conti3`.`descrizione`) FROM `co_piano_dei_conti3` INNER JOIN `co_piano_dei_conti2` ON `co_piano_dei_conti3`.`id_piano_dei_conti2` = `co_piano_dei_conti2`.`id` WHERE `co_piano_dei_conti3`.`id` = `mncs_incassi_conti`.`id_conto`)',
  4, 1, 0, 1, 1
FROM DUAL WHERE @mncs_v IS NULL;
