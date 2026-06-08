# CLAUDE — OpenSTAManager fork

Istruzioni specifiche per questa cartella (`openstamanager/`). Si aggiungono — senza sostituirle —
alle regole del CLAUDE.md della cartella genitore (`../CLAUDE.md`).

## Contesto

- Questa cartella è il **fork OpenSTAManager** (`AllWorkNoPlay-95/openstamanager`, branch di lavoro
  `kartiell-prod`) che periodicamente effettua il **merge di upstream** `devcode-it/openstamanager`
  (remote `upstream`).
- È un'app PHP (base Laravel 12). Le modifiche di schema passano dall'**updater OSM** con file
  versionati in `update/*.sql` (non da Knex: quello riguarda l'app Node del genitore).
- `AGENTS.md` in questa root è la **guida OSM upstream** (architettura, convenzioni, versioning):
  consultarla per le convenzioni del codebase. Questo `CLAUDE.md` riguarda invece **come operiamo
  noi sul fork**.

## Naming: prefisso `mncs_` sulle colonne custom

- Ogni **colonna DB** che aggiungiamo noi a una tabella del fork **DEVE** usare il prefisso
  `mncs_` (es. `mncs_id_sede_partenza`).
- Motivo: evita a monte le collisioni di nome con le colonne di upstream. Senza prefisso, una
  colonna omonima a una già esistente (es. `id_sede_partenza` presente in `co_documenti`) rende
  ambigue le query che fanno JOIN tra le due tabelle (errore SQL 1052 "Column ... is ambiguous").
- Vale per colonne nuove su tabelle core esistenti. Per tabelle interamente nostre, prefissare
  comunque le colonne è buona prassi ma non obbligatorio.

## Custom Modifications Documentation (CUSTOM.md)

- Ogni nostra personalizzazione al fork **DEVE** essere documentata in modo strutturato in
  `CUSTOM.md` nella root di questa cartella (`openstamanager/CUSTOM.md`).
- È la mappa canonica di "cosa abbiamo cambiato rispetto a upstream": è ciò che rende sicuro e
  revisionabile il `git merge` di upstream, e segnala i file **CORE** che abbiamo modificato (che
  altrimenti darebbero conflitti) rispetto agli **override in cartelle `*/custom/`** (che mascherano
  silenziosamente upstream).
- Dopo ogni modifica al fork, aggiungere/aggiornare una voce. Ogni voce DEVE includere: data, titolo
  breve, obiettivo, i file esatti toccati (marcando ciascuno come `[CORE]` o `[CUSTOM]`), cosa è
  cambiato in ciascuno, gli SHA dei commit collegati, ed eventuali caveat (es. file core da
  ri-controllare al merge upstream).
- Mantenerlo strutturato e cronologico (più recente in alto), una sezione per feature/modifica. Non
  lasciarlo divergere dalla realtà: se una modifica viene annullata, rimuovere o barrare la voce.
