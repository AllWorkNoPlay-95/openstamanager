-- Campo personalizzato "Alias" sugli Articoli: controparte OSM di `prodotti.uf_cod`
-- (uf_code) di k-odin. Valorizzato dal sync server-to-server (modules/mncs/sync/
-- import-articolo.php) e modificabile a mano dai form articolo (add + edit), dove il
-- core lo renderizza e lo salva da solo (FieldManager + actions.php radice).
--
-- Scelta deliberata: campo personalizzato zz_fields/zz_field_record, NON colonna su
-- mg_articoli -> zero file core toccati per la UI. `html_name` stabile 'mncs_alias'
-- cosi' sync e vista lo risolvono per nome senza dipendere dagli id.
--
-- `id_plugin` esplicitamente NULL: FieldManager::getInfo() e actions.php radice
-- filtrano per id_plugin quando valorizzato -> un campo di modulo deve averlo NULL
-- per comparire (solo) nel form del modulo.
--
-- Idempotente: ogni INSERT e' guardato da NOT EXISTS (gli update custom girano da
-- zero su ogni installazione).

INSERT INTO `zz_fields` (`id_module`, `id_plugin`, `name`, `html_name`, `content`, `order`, `on_add`, `top`)
SELECT
    (SELECT `id` FROM `zz_modules` WHERE `name` = 'Articoli'),
    NULL,
    'Alias',
    'mncs_alias',
    '{[ "type": "text", "label": "|label|", "name": "|name|", "value": "|value|" ]}',
    0,
    1,
    0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `zz_fields` WHERE `html_name` = 'mncs_alias');

-- Colonna "Alias" nell'elenco articoli (ricercabile). Il valore vive in zz_field_record
-- (EAV), quindi la vista usa una subquery correlata risolta per html_name.
-- I permessi zz_group_view NON vanno inseriti: il post-update (src/Update.php) li
-- popola automaticamente per le viste orfane, per tutti i gruppi.
INSERT INTO `zz_views` (`id_module`, `name`, `query`, `order`, `search`, `slow`, `format`, `visible`, `summable`, `default`)
SELECT
    (SELECT `id` FROM `zz_modules` WHERE `name` = 'Articoli'),
    'Alias',
    '(SELECT `zfr`.`value` FROM `zz_field_record` `zfr` INNER JOIN `zz_fields` `zf` ON `zf`.`id` = `zfr`.`id_field` WHERE `zf`.`html_name` = ''mncs_alias'' AND `zfr`.`id_record` = `mg_articoli`.`id`)',
    99,
    1,
    0,
    0,
    1,
    0,
    0
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `zz_views`
    WHERE `id_module` = (SELECT `id` FROM `zz_modules` WHERE `name` = 'Articoli') AND `name` = 'Alias'
);

INSERT INTO `zz_views_lang` (`id_lang`, `id_record`, `title`)
SELECT
    1,
    (SELECT `id` FROM `zz_views` WHERE `id_module` = (SELECT `id` FROM `zz_modules` WHERE `name` = 'Articoli') AND `name` = 'Alias'),
    'Alias'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `zz_views_lang`
    WHERE `id_record` = (SELECT `id` FROM `zz_views` WHERE `id_module` = (SELECT `id` FROM `zz_modules` WHERE `name` = 'Articoli') AND `name` = 'Alias')
        AND `id_lang` = 1
);
