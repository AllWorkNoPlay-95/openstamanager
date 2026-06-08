-- Rinomina co_tipi_documento.id_sede_partenza -> id_sede_predefinita.
-- Il nome `id_sede_partenza` collideva con co_documenti.id_sede_partenza (e altre tabelle
-- documento) nelle query che fanno JOIN tra co_tipi_documento e co_documenti, causando
-- l'errore 1052 "Column 'id_sede_partenza' in SELECT is ambiguous".
-- Applicabile sia su DB freschi (dopo 2_11_1) sia su DB dove 2_11_1 era già stato applicato.
ALTER TABLE `co_tipi_documento`
  CHANGE `id_sede_partenza` `id_sede_predefinita` INT NULL DEFAULT NULL;
