-- Sede aziendale predefinita per tipo documento — colonna custom `mncs_id_sede_partenza`.
-- Consolidamento idempotente degli ex update fork `update/2_11_1.sql` (ADD id_sede_partenza)
-- e `update/2_11_2.sql` (rename -> mncs_id_sede_partenza), spostati qui nel namespace del
-- modulo custom `mncs` per non collidere con la numerazione di upstream in `update/`.
--
-- Valore della colonna: NULL = nessun default (fallback alla logica sedi utente);
--                       0 = sede legale; > 0 = an_sedi.id.
--
-- Idempotente (gira da zero su ogni installazione, anche dove i vecchi update erano già
-- stati applicati): se resta la vecchia colonna non prefissata la rinomina, poi garantisce
-- comunque la presenza di `mncs_id_sede_partenza`. Sintassi IF [NOT] EXISTS supportata da MariaDB.
ALTER TABLE `co_tipi_documento`
  CHANGE COLUMN IF EXISTS `id_sede_partenza` `mncs_id_sede_partenza` INT NULL DEFAULT NULL;
ALTER TABLE `co_tipi_documento`
  ADD COLUMN IF NOT EXISTS `mncs_id_sede_partenza` INT NULL DEFAULT NULL AFTER `id_segment`;
