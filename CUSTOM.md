# CUSTOM.md — Modifiche del fork rispetto a upstream

Questo file è la **mappa canonica di tutto ciò che abbiamo cambiato** nel fork
(`AllWorkNoPlay-95/openstamanager`, branch `kartiell-prod`) rispetto a upstream
`devcode-it/openstamanager`.

Serve a rendere **sicuro e revisionabile il `git merge` di upstream**: distingue le
modifiche al **CORE** (che generano conflitti visibili al merge) dagli **override CUSTOM**
(file in `*/custom/` che mascherano il core e quindi **non** ricevono i bugfix upstream →
vanno ri-controllati a ogni allineamento).

**Convenzioni:**
- Voci in ordine **cronologico inverso** (più recente in alto), una sezione per feature/modifica.
- Per ogni file toccato indicare se è `[CORE]` o `[CUSTOM]`.
- Se una modifica viene annullata, rimuovere o barrare la voce.

---

## 2026-06-09 — Footer: branding "Fork MNCS per Kartiell Verona SRL"

**Obiettivo:** nel footer dell'interfaccia, a destra resta la versione attuale ma la dicitura tra
parentesi non deve più essere `In sviluppo` (mostrata quando non c'è una revision) bensì
`Fork MNCS per Kartiell Verona SRL`.

**Soluzione:** override CUSTOM invece di toccare il core. Il footer è incluso via
`App::filepath('include|custom|', 'bottom.php')` (`src/App.php:323`), che usa
`include/custom/bottom.php` se esiste, altrimenti `include/bottom.php`. Creato l'override come copia
del core con la sola riga della parentesi modificata.

**File toccati:**
- `include/custom/bottom.php` `[CUSTOM]` — nuovo override (copia di `include/bottom.php`). Cambiata
  solo la riga `<small>` del footer: da
  `('.(!empty($revision) ? $revision : tr('In sviluppo')).')` a
  `('.tr('Fork MNCS per Kartiell Verona SRL').')`.

**Caveat:** essendo una copia integrale del core, `include/custom/bottom.php` **non** riceve i
bugfix upstream di `include/bottom.php`. Ad ogni merge upstream ri-allineare manualmente il resto
del file, mantenendo solo la riga del footer modificata.

---

## 2026-06-09 — Convenzione: gli update SQL custom vivono in `modules/mncs/update/`

**Obiettivo:** evitare la collisione di numerazione tra i nostri update e quelli di upstream.
I file in `update/` core sono nel namespace di upstream (es. un nostro `update/2_11_1.sql` collide
con un futuro `2_11_1.sql` di upstream → conflitto `git merge`).

**Soluzione:** OSM scansiona anche `modules/*/update/` e `plugins/*/update/`
(`Update::getCustomUpdates()`, `src/Update.php:592`) come sequenza di versioni **indipendente**,
namespacciata dalla colonna `directory` della tabella `updates`. I nostri update SQL custom vanno
quindi in **`modules/mncs/update/`** con numerazione propria (`1_0.sql`, `1_1.sql`, …).

> **Regola:** mai mettere update nostri in `update/` core. Sempre `modules/mncs/update/N_M.sql`,
> nomi **solo numerici** (`isVersion` accetta solo `^\d+(?:\.\d+)+$`: un suffisso tipo `_mncs`
> renderebbe la versione `…mncs` e il file verrebbe **ignorato in silenzio**). Renderli **idempotenti**
> (`IF [NOT] EXISTS`, `REPLACE`, guardie) perché girano da zero su ogni installazione.

**Migrazione fatta ora:** i 3 ex update fork in `update/` sono stati spostati e consolidati:

| Prima (core `update/`) | Dopo (`modules/mncs/update/`) |
|------|------|
| `2_11_1.sql` + `2_11_2.sql` (add `id_sede_partenza` + rename `mncs_`) | `1_0.sql` (consolidato, idempotente: garantisce `mncs_id_sede_partenza`) |
| `2_11_3.sql` (fix barcode Articoli) | `1_1.sql` (invariato, `REPLACE` idempotente) |

> **Caveat (riesecuzione):** spostando i file cambia il path → l'updater li vede come "mai eseguiti"
> e li **rieseguirà** una volta su ogni ambiente, anche dove i vecchi giravano già. Per questo `1_0`
> è idempotente (la coppia add+rename non era ri-eseguibile come due passi: consolidata in uno solo).

---

## 2026-06-09 — Fix listato/ricerca Articoli: derived table correlata incompatibile con MariaDB

**Obiettivo:** sbloccare *Magazzino > Articoli*, che falliva all'apertura con errore AJAX
`SQLSTATE[42S22] 1054 Unknown column 'mg_articoli_barcode.id_articolo' in 'WHERE'`.

**Causa radice:** il sottoquery dei barcode (presente sia nel `zz_modules.options` del modulo
Articoli sia nella ricerca articoli) usava una **derived table correlata** nel ramo `ELSE` del
`CASE`:

```sql
ELSE CONCAT((SELECT GROUP_CONCAT(`b1`.`barcode` ...)
            FROM (SELECT `barcode` FROM `mg_articoli_barcode` `b2`
                  WHERE `b2`.`id_articolo` = `mg_articoli_barcode`.`id_articolo`  -- riferimento esterno
                  ORDER BY `b2`.`barcode` ASC) `b1`)) END
```

La derived table `b2` referenzia il `mg_articoli_barcode` della query esterna. **MariaDB non
supporta le lateral/correlated derived table** (nessuna versione), quindi non risolve la colonna
→ errore 1054. La colonna `id_articolo` esiste: è un problema di **scope SQL**, non di nome.
È un bug **upstream** (il costrutto è in `update/2_9_1.sql`/`2_11.sql`); emerge su questo fork
perché gira su MariaDB (12.3.2), mentre upstream dichiara MariaDB ≥ 10.5 pienamente supportato.

**Fix:** ramo `ELSE` sostituito da `GROUP_CONCAT(... ORDER BY ... SEPARATOR ...)`, equivalente nel
risultato (elenco barcode dell'articolo, ordinati) e valido su MariaDB e MySQL.

**File toccati:**

| File | Tipo | Modifica |
|------|------|----------|
| `modules/articoli/ajax/search.php` | `[CORE]` | Riga 48: ramo `ELSE` del sottoquery barcode → `GROUP_CONCAT` ordinato (ricerca globale articoli). |
| `modules/mncs/update/1_1.sql` | `[CUSTOM]` | **Nuovo file** (ex `update/2_11_3.sql`). `UPDATE zz_modules ... REPLACE(options, ...)` sul modulo Articoli: stessa correzione sulla query di listato persistita nel DB. |

**Commit collegati:** `03162cbb7` (fix originale), + commit di spostamento in `modules/mncs/update/`.

> **Caveat (merge upstream):** è un bugfix su un costrutto **upstream**. Al merge ricontrollare
> `update/2_9_1.sql` e `update/2_11.sql` (sorgenti originali del sottoquery rotto) e
> `modules/articoli/ajax/search.php`. Inviata PR draft a upstream con la stessa correzione
> (devcode-it/openstamanager#1837); se accettata, al merge questa voce potrà essere ritirata. La fix
> DB live è già applicata su questo ambiente; altri ambienti la ricevono eseguendo l'updater
> (`modules/mncs/update/1_1.sql`).

---

## 2026-06-09 — Soppressione deprecation del motore PHP 8.5 (`E_DEPRECATED`)

**Obiettivo:** eliminare i report di deprecazione del motore PHP 8.5 (es. *"Using null as an array
offset is deprecated, use an empty string instead"* a `src/Modules.php:141`) senza modificare la
logica dei singoli file core.

**Modifica:** in `core.php:141` aggiunto `& ~E_DEPRECATED` alla `error_reporting(...)`. La riga
escludeva già `E_USER_DEPRECATED`; ora esclude anche le deprecation del **motore** PHP (livello
`E_DEPRECATED`), coerentemente col commento esistente *"Ignora gli avvertimenti... relativi alla
deprecazione"*. `config.inc.php` viene incluso prima (riga 38) e `error_reporting` esplicito vince
sul valore di `php.ini`, quindi questo è l'unico punto efficace di soppressione.

**File toccati:**

| File | Tipo | Modifica |
|------|------|----------|
| `core.php` | `[CORE]` | `error_reporting(...)`: aggiunto `& ~E_DEPRECATED` (soppressione globale deprecation motore PHP 8.5). |

> **Caveat:** soppressione **globale** — nasconde tutte le deprecation del motore in tutta l'app,
> incluse eventuali future segnalazioni reali di incompatibilità PHP 8.5 (non risolve le cause).
> Al merge upstream verificare il diff su `core.php:141`.

---

## 2026-06-08 — Sede aziendale predefinita sul tipo documento

**Obiettivo:** associare a un tipo documento una sede aziendale predefinita, applicata
automaticamente alla creazione della fattura e all'aggiornamento live al cambio tipo nella
fattura aperta. Fallback alla logica esistente (sede legale / prima sede utente) quando il
campo è vuoto.

**Semantica:** il valore rappresenta la sede aziendale e viene mappato per direzione:
`id_sede_partenza` per vendita (`dir=entrata`), `id_sede_destinazione` per acquisto (`dir=uscita`).

> **Naming:** la nostra colonna usa il prefisso `mncs_` (→ `mncs_id_sede_partenza`) per evitare la
> collisione con `co_documenti.id_sede_partenza` nelle query con JOIN (che dava errore SQL 1052
> "ambiguous"). Vedi la convenzione `mncs_` in `CLAUDE.md`.

**File toccati:**

| File | Tipo | Modifica |
|------|------|----------|
| `modules/mncs/update/1_0.sql` | `[CUSTOM]` (nuovo) | Consolida ex `update/2_11_1.sql`+`2_11_2.sql` in un unico step idempotente: garantisce `co_tipi_documento.mncs_id_sede_partenza INT NULL DEFAULT NULL`. NULL = nessun default; 0 = sede legale; >0 = `an_sedi.id`. Prefisso `mncs_` per evitare l'ambiguità SQL 1052. |
| `modules/tipi_documento/custom/edit.php` | `[CUSTOM]` (clone di `edit.php`) | Aggiunto select "Sede aziendale predefinita" (`name=mncs_id_sede_partenza`, `ajax-source=sedi_azienda`) accanto a "Sezionale predefinito". |
| `modules/tipi_documento/custom/actions.php` | `[CUSTOM]` (clone di `actions.php`) | Nel `case 'update'`: persiste `mncs_id_sede_partenza` (`'' / null → null`, altrimenti `(int)`, così `0`=sede legale è preservato). |
| `modules/fatture/src/Fattura.php` | `[CORE]` | In `build()`, dopo il calcolo di `$id_sede`: se `$tipo_documento->mncs_id_sede_partenza !== null` lo usa (priorità sulla logica sedi utente). |
| `modules/fatture/edit.php` | `[CORE]` | (a) query del select `id_tipo_documento`: esposte `mncs_id_sede_partenza` e `nome_sede` (con `IF(...=0,'Sede legale', subquery an_sedi)`). (b) handler `$("#id_tipo_documento").change`: `selectSetNew` sul campo sede del documento (`id_sede_partenza`/`id_sede_destinazione` per direzione) leggendo `tipoData.mncs_id_sede_partenza`. |

**Commit:**
- `bf7367adb` — colonna `id_sede_partenza` (ex update 2_11_1, ora consolidata in `modules/mncs/update/1_0.sql`)
- `8815c1289` — campo sede aziendale predefinita (custom edit)
- `b6040e2d9` — persistenza id_sede_partenza (custom actions)
- `1c59e4767` — auto-set in `Fattura::build()`
- `0e985937b` — aggiornamento live al cambio tipo (`fatture/edit.php`)
- `ecf05ab83` — spec + piano (`docs/superpowers/`)
- _revert_ `67e8bfca1` (iterazione errata: rename a `id_sede_predefinita`) + applicazione prefisso `mncs_` (colonna → `mncs_id_sede_partenza`, ex `update/2_11_2.sql`, ora in `modules/mncs/update/1_0.sql`)

**Caveat / da ricontrollare al merge upstream:**
- `[CORE] modules/fatture/src/Fattura.php` e `[CORE] modules/fatture/edit.php` sono file grandi e
  cambiati spesso da upstream: al merge attendersi conflitti su queste righe, risolverli mantenendo
  l'override / la query estesa.
- `[CUSTOM] modules/tipi_documento/custom/{edit,actions}.php` **mascherano** il core: se upstream
  modifica `modules/tipi_documento/edit.php` o `actions.php`, quei cambiamenti **non arrivano**.
  Verificare il diff core↔custom a ogni allineamento e riportare a mano le novità.
- `Fattura::build()` è condiviso da fatture, note di credito, autofatture, conversioni. L'override
  è sicuro: dove la sede viene impostata esplicitamente dopo `build()` (note di credito → sede
  della fattura padre; conversioni → sede del documento origine) quel valore continua a vincere.
  L'override incide solo su **creazione fattura** e **autofattura** (comportamento voluto).
- Accesso `$tipo_documento->id_sede_partenza` è sicuro anche **prima** che la migrazione sia
  applicata (Eloquent ritorna `null` per attributi assenti, non lancia errore).
- **Numerazione update:** l'`ALTER` di questa feature vive ora in `modules/mncs/update/1_0.sql`
  (namespace custom, sequenza indipendente da upstream) — vedi la voce di convenzione in cima a
  questo file. Non è più nel namespace `2_11_x` di upstream, quindi la nota AGENTS.md sui file
  `PATCH` non si applica più.
