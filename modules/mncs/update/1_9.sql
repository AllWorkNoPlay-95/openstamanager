-- Indice di supporto alla subquery EAV dell'Alias (campo personalizzato `mncs_alias`) su
-- `zz_field_record`. La colonna "Alias" della lista Articoli e la ricerca per alias usano una
-- subquery correlata `WHERE zfr.id_record = mg_articoli.id`. La tabella `zz_field_record` aveva
-- indice solo su `id_field`: senza un indice su `id_record`, ogni valutazione faceva un FULL SCAN
-- (EXPLAIN: type=ALL su ~32k righe). Sulla lista di decine di migliaia di articoli questo rendeva
-- `ajax_dataload.php?id_module=21` lento di MINUTI, e — tenendo il lock di sessione PHP per tutta
-- la durata — bloccava l'intera UI. L'indice trasforma la subquery in un lookup `ref`.
--
-- Tabella CORE OSM: indice additivo, idempotente (IF NOT EXISTS), nome prefissato `mncs_` per non
-- collidere con un eventuale futuro indice upstream.
ALTER TABLE `zz_field_record`
  ADD INDEX IF NOT EXISTS `mncs_zfr_id_record` (`id_record`, `id_field`);
