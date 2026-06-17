-- Campo per-articolo "Sconto su articolo": abilita/disabilita l'applicazione del piano sconto
-- legato all'anagrafica cliente (`an_anagrafiche.id_piano_sconto_vendite` → `mg_piani_sconto`)
-- sulle righe dei documenti di vendita.
--   ON  (1, default) → il piano sconto del cliente puo' essere combinato sopra il listino (comportamento storico OSM)
--   OFF (0)          → si applica solo il listino standard, senza il piano sconto cliente
--
-- Tabella CORE OSM (`mg_articoli`): colonna additiva, idempotente (IF NOT EXISTS), nome prefissato
-- `mncs_` per non collidere con un eventuale futuro campo upstream. Default 1 → non distruttivo:
-- gli articoli esistenti mantengono il comportamento attuale.
ALTER TABLE `mg_articoli`
  ADD COLUMN IF NOT EXISTS `mncs_sconto_su_articolo` TINYINT(1) NOT NULL DEFAULT 1;
