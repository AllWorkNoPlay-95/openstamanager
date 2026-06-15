-- Modulo custom "Azzeramento giacenze" sotto Strumenti: pagina con un bottone che
-- scarica un CSV (Codice;Qta Feroleto;Qta Rende, tutto a 0) per gli articoli con
-- giacenza != 0 in OSM, da caricare nell'importer "Giacenze magazzino" per fare
-- piazza pulita prima di un nuovo inventario.
--
-- 100% additivo: nessun file core toccato, nessun override. options='custom' rende
-- la pagina (edit.php) senza datatable (come Backups/Import). Niente zz_group_module:
-- i moduli senza grant espliciti sono visibili all'admin (come Backups e
-- mncs_incassi_conti). Idempotente.

SET @mncs_parent_strumenti := (SELECT `id` FROM `zz_modules` WHERE `name` = 'Strumenti' LIMIT 1);
SET @mncs_mod_azz := (SELECT `id` FROM `zz_modules` WHERE `directory` = 'mncs_azzeramento_giacenze' LIMIT 1);

INSERT INTO `zz_modules`
  (`name`, `directory`, `attachments_directory`, `options`, `options2`, `icon`, `version`, `compatibility`, `order`, `parent`, `default`, `enabled`)
SELECT
  'Azzeramento giacenze', 'mncs_azzeramento_giacenze', 'mncs_azzeramento_giacenze',
  'custom', '', 'fa fa-eraser', '2.11', '2.11', 100,
  @mncs_parent_strumenti, 1, 1
FROM DUAL
WHERE @mncs_mod_azz IS NULL;

SET @mncs_mod_azz := (SELECT `id` FROM `zz_modules` WHERE `directory` = 'mncs_azzeramento_giacenze' LIMIT 1);

-- Titolo del modulo (schema OSM 2.11: il titolo vive in zz_modules_lang). Rebuild idempotente.
DELETE FROM `zz_modules_lang` WHERE `id_record` = @mncs_mod_azz;
INSERT INTO `zz_modules_lang` (`id_lang`, `id_record`, `title`, `meta_title`) VALUES
  (1, @mncs_mod_azz, 'Azzeramento giacenze', 'Azzeramento giacenze'),
  (2, @mncs_mod_azz, 'Reset stock', 'Reset stock');
