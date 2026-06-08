-- Applica il prefisso `mncs_` alla nostra colonna custom su co_tipi_documento.
-- Convenzione del fork: ogni colonna DB aggiunta da noi usa il prefisso `mncs_` per
-- evitare collisioni con le colonne di upstream (qui id_sede_partenza collideva con
-- co_documenti.id_sede_partenza nelle query con JOIN, errore 1052 "ambiguous").
ALTER TABLE `co_tipi_documento`
  CHANGE `id_sede_partenza` `mncs_id_sede_partenza` INT NULL DEFAULT NULL;
