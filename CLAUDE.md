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

## Update SQL custom: sempre in `modules/mncs/update/`

- Ogni nostro update di schema/dati **DEVE** stare in **`modules/mncs/update/`**, **mai** in
  `update/` core. La cartella `update/` core è il namespace di upstream: un nostro file lì (es.
  `update/2_11_1.sql`) collide con un futuro update omonimo di upstream → conflitto `git merge`.
- OSM scansiona `modules/*/update/` come sequenza di versioni **indipendente**
  (`Update::getCustomUpdates()`), namespacciata dalla colonna `directory` di `updates`. La nostra
  sequenza è `modules/mncs/update/1_0.sql`, `1_1.sql`, …
- Nomi **solo numerici** (`N_M.sql`): `isVersion` accetta solo `^\d+(?:\.\d+)+$`. Un suffisso tipo
  `_mncs` rende la versione non valida e il file viene **ignorato in silenzio**.
- Gli update custom girano **da zero su ogni installazione**: renderli **idempotenti**
  (`ADD/CHANGE/DROP COLUMN IF [NOT] EXISTS`, `REPLACE`, guardie). Non assumere uno stato pregresso.

## Dove mettere il codice custom in `modules/`

OSM scopre i **moduli registrati** (`zz_modules`) solo come cartelle **flat** `modules/<directory>`
(`Module::$main_folder = 'modules'`; nessun modulo core usa directory annidate). Regole del fork:

- **Nuovo modulo registrato** (ha una riga in `zz_modules`, es. una "Tabella" sotto *Strumenti*):
  cartella **flat** `modules/mncs_<nome>/` con prefisso **`mncs_`**. Il prefisso evita la collisione
  con un eventuale futuro modulo upstream omonimo (stessa logica del prefisso colonne). **Mai**
  directory annidate (es. `modules/mncs/<nome>/`): non sono standard OSM e rompono URL/allegati/glob.
  La registrazione (`INSERT INTO zz_modules` + `zz_views`) va nell'update SQL ed è **additiva**:
  nessun file core toccato.
- **Endpoint / script custom non-modulo** (file inclusi via URL o `include`, non registrati in
  `zz_modules`): sotto il namespace fork **`modules/mncs/<area>/`** (es. `modules/mncs/incassi/`).
- **Funzioni riutilizzabili** condivise tra più punti: in **`modules/mncs/shared/`**, per rispettare
  DRY/KISS; includerle dove servono (`include_once`). Creare la cartella **solo quando serve**
  davvero (KISS), non in anticipo.
- **Update SQL**: in `modules/mncs/update/` (vedi sezione sopra).
- **Override del core**: in `*/custom/` (meccanismo `App::filepath()` — vedi la sezione
  «Meccanismo override custom» sotto), da **minimizzare** perché mascherano upstream. Quasi sempre
  è **replace dell'intero file**: preferire una modifica `[CORE]` minima e documentata.

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

## Meccanismo override custom (`App::filepath`): quasi sempre replace-whole-file

Sintesi verificata sul codice (`src/App.php:321-337`, `src/Traits/PathTrait.php:40-43`, `src/AJAX.php:227-266`).
Serve a decidere, prima di toccare un file CORE, se conviene un override `*/custom/`.

- **Non esiste alcuna classe `Structure`.** Il meccanismo reale è `App::filepath($path, $file)`: nel
  `$path` c'è il token `|custom|`, sostituito con `''` (candidato core) o con `/custom` (candidato
  custom). Ritorna **un solo path**, preferendo il custom se esiste, altrimenti il core. **Nessun
  merge/append**: il file custom **maschera per intero** il core ⇒ chi lo usa deve ri-implementare
  tutto, e perde in silenzio ogni bugfix upstream di quel file.
- **Conseguenza pratica.** Override custom = **replace-whole-file** per: `modules/<mod>/{actions,edit,
  init,bulk,validation}.php`, `modules/<mod>/ajax/<resource>.php` (entro il modulo), i file
  `include/common/<x>.php` (via `App::load` → candidato `include/custom/common/<x>.php`), `include/
  {top,bottom,form}.php`, e le stampe (`stampa.php`/`pdfgen.*` — if/else esplicito ma sempre replace).
- **Le UNICHE estensioni davvero additive** (preferirle quando applicabili):
  - **Classi `src/` dei moduli** (PSR-4, `composer.json`): una classe **nuova** in
    `modules/<mod>/custom/src/` è additiva; stesso FQCN invece sostituisce. Le classi core top-level
    (`App`, `AJAX`, `Modules`, `Prints`, …) mappano solo `src/` ⇒ **non** override-abili.
  - **Ajax tra moduli diversi**: `AJAX::find()` è first-match-wins tra moduli, ma replace entro lo
    stesso modulo.
  - **Hook di iniezione puri**: `include/custom/extra/extra.php` (PHP/JS globale su ogni pagina
    autenticata) e `include/custom/extra/login.php` — inclusi solo se esistono, non sovrascrivono nulla.
  - **Campi aggiuntivi dinamici** (`zz_fields`/`zz_field_record`): aggiungono un campo a una scheda
    (render + salvataggio automatici) con **zero file core**, via sola `INSERT` in `modules/mncs/
    update/`. Già usati nel fork (campo *Alias*). **Caveat:** il valore vive in EAV, non in colonna;
    se il dato è letto su **hot-path** (es. ricalcolo righe documento) la lettura diventa un join su
    `zz_field_record` — pattern noto-lento (vedi indice `mncs_zfr_id_record`, `1_9.sql`): in quel caso
    preferire una colonna `mncs_` tipizzata + edit `[CORE]` minimo.
- **Regola operativa.** Un override `*/custom/` conviene **solo** quando si rimpiazza davvero l'intero
  comportamento di un file *poco toccato da upstream*, o per aggiungere qualcosa di **nuovo** via i
  meccanismi additivi sopra. Per modifiche **chirurgiche** dentro file CORE attivamente manutenuti da
  upstream (dispatcher `actions.php`, `include/common/articolo.php`, ajax articoli): preferire l'edit
  `[CORE]` minimo. Converte un conflitto git **rumoroso e revisionabile** al merge in un override che
  invece produrrebbe **deriva silenziosa** — il rischio esatto da evitare. Precedente: in `CUSTOM.md`
  l'override custom di `select.php` è stato scartato per questa stessa ragione.
