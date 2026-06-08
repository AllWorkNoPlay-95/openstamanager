# Sede aziendale predefinita sul tipo documento

**Data:** 2026-06-08
**Branch:** kartiell-prod
**Modulo:** OpenSTAManager (PHP legacy)

## Obiettivo

Permettere di associare a un **tipo documento** una **sede aziendale predefinita**, così
che creando un documento di quel tipo (es. "Fattura di Vendita Rende") la sede aziendale
venga impostata automaticamente, senza dipendere dalle sedi assegnate all'utente loggato.

## Comportamento

- Alla **creazione** della fattura (`Fattura::build()`) la sede aziendale viene presa dal
  tipo documento, se valorizzata.
- Al **cambio del tipo documento** dentro la fattura già aperta (`edit.php`), il campo sede
  aziendale si aggiorna in automatico via JS.
- Se il tipo documento **non** ha la sede impostata (campo vuoto / `NULL`) → si applica la
  **logica attuale** (sede legale `0` se l'utente vi ha accesso, altrimenti `$user->sedi[0]`).
  Retrocompatibile: i tipi documento esistenti continuano a funzionare come oggi.

## Semantica per direzione

In OSM la "sede aziendale" sul documento non è sempre la *partenza*:

| Direzione | Modulo | Sede aziendale = |
|-----------|--------|------------------|
| `entrata` (Vendita) | Fatture di vendita | `id_sede_partenza` (la merce parte da noi) |
| `uscita` (Acquisto) | Fatture di acquisto | `id_sede_destinazione` (la merce arriva da noi) |

Il campo sul tipo documento rappresenta quindi **la sede aziendale predefinita**, che
`Fattura::build()` assegna a `id_sede_partenza` (vendita) o `id_sede_destinazione` (acquisto).
Il campo è mostrato per **entrambe le direzioni**.

> Nota: la colonna si chiama `id_sede_partenza` per coerenza con il linguaggio dell'utente,
> ma il valore è la sede aziendale e viene mappato a partenza/destinazione in base alla direzione.

## Modello dati

Nuova colonna su `co_tipi_documento`:

```sql
ALTER TABLE `co_tipi_documento`
  ADD `id_sede_partenza` INT NULL DEFAULT NULL AFTER `id_segment`;
```

- `NULL` = nessuna sede predefinita → fallback alla logica attuale.
- Valore `0` = sede legale; valore `> 0` = `an_sedi.id`.

### Consegna migrazione

Nuovo file di update versionato **`update/2_11_1.sql`** (e relativo `.php` se necessario per
le logiche post-update — qui non serve). L'updater OSM (`src/Update.php::getUpdates`) rileva
i file via `glob` + `natsort`, converte `2_11_1` → versione `2.11.1` e la applica dopo `2.11`.
Scelto perché il DB di produzione è già alla versione `2.11` (un append a `2_11.sql` non verrebbe
ri-eseguito).

## Strategia core vs custom (ibrido leggero)

Contesto rilevato: il fork (`kartiell-prod`) ha il remote `upstream = devcode-it/openstamanager`
e si allinea upstream **via `git merge`** (presenti commit `Merge branch 'devcode-it:master'`),
non con l'updater OSM. Implicazione:

- **Modifica al core**: un merge upstream genera un **conflitto visibile** sulle righe toccate →
  manutenzione consapevole.
- **Clone in `custom/`** (override via `App::filepath` per i file vista, via PSR-4
  `modules/<m>/custom/src/` per le classi): nessun conflitto, ma il clone **maschera** il core →
  i bugfix upstream a quel file **non arrivano** silenziosamente (staleness silenziosa).

Decisione: **ibrido leggero**.

| Componente | Dove | Motivo |
|-----------|------|--------|
| `tipi_documento/edit.php` (88 righe) | **custom** (`modules/tipi_documento/custom/edit.php`) | File piccolo e stabile: clone a basso rischio |
| `tipi_documento/actions.php` (108 righe) | **custom** (`modules/tipi_documento/custom/actions.php`) | idem |
| `Fattura::build()` (`Fattura.php`, 1109 righe) | **core** | File grande, cambia spesso: clone = staleness silenziosa. Meglio conflitto visibile |
| `fatture/edit.php` (1469 righe) | **core** | idem |
| Colonna `co_tipi_documento` | **core** (`update/2_11_1.sql`) | Nessuno slot custom per tabelle core |

## Componenti da modificare

### 1. `update/2_11_1.sql` (nuovo)
ALTER TABLE sopra indicato.

### 2. `modules/tipi_documento/custom/edit.php` (CUSTOM — clone di edit.php)
Copia integrale del core `edit.php` + nuovo select **"Sede aziendale predefinita"**,
`ajax-source: "sedi_azienda"`, `name: "id_sede_partenza"`, `value: "$id_sede_partenza$"`,
accanto al "Sezionale predefinito" già presente. Mostrato per entrambe le direzioni.
Help text che spiega la mappatura partenza/destinazione.

### 3. `modules/tipi_documento/custom/actions.php` (CUSTOM — clone di actions.php)
Copia integrale del core `actions.php` + nell'azione `update`, dopo `$tipo->id_segment = ...`:
`$tipo->id_sede_partenza = post('id_sede_partenza') ?: null;`. Il modello `Modules\Fatture\Tipo`
salva l'attributo direttamente (no `$fillable`), basta che la colonna esista.

### 4. `modules/fatture/src/Fattura.php` — `build()` (righe ~138-156)
Dopo il calcolo di `$id_sede` (logica utente), se `$tipo_documento->id_sede_partenza` non è
`NULL`, sovrascrivere `$id_sede` con quel valore. L'assegnazione esistente a
`id_sede_partenza` / `id_sede_destinazione` per direzione resta invariata.

### 5. `modules/fatture/edit.php`
- **Query select `id_tipo_documento`** (riga ~425): aggiungere `co_tipi_documento.id_sede_partenza`
  e il nome sede (join condizionale: `0` → "Sede legale", altrimenti `an_sedi.nome_sede`) come
  campi extra, così `selectData()` li espone al JS.
- **Handler JS `$("#id_tipo_documento").change`** (riga ~1039): quando il tipo ha
  `id_sede_partenza` valorizzato, `selectSetNew(data.id_sede_partenza, data.nome_sede)` sul campo:
  - `#id_sede_partenza` per direzione `entrata`,
  - `#id_sede_destinazione` per direzione `uscita`.
  Entrambi usano `ajax-source: sedi_azienda`, quindi `selectSetNew(id, nome_sede)` è compatibile.

## Fuori scope (YAGNI)

- Nessuna modifica a `modules/fatture/add.php` (la sede è già derivata da `build()` al submit).
- Nessuna modifica ad altri moduli (DDT, preventivi, ordini, contratti).
- Nessuna modifica al comportamento dei tipi documento senza sede impostata.

## Verifica / test manuale

1. Tipi documento → "Fattura di Vendita Rende" → impostare Sede aziendale = sede di Rende → salvare.
2. Nuova fattura di vendita con quel tipo → la Sede partenza è Rende (non sede legale/utente).
3. In fattura aperta, cambiare il tipo documento → la Sede partenza si aggiorna live.
4. Tipo documento senza sede impostata → comportamento invariato (sede legale / prima sede utente).
5. Verifica analoga lato acquisto: la sede del tipo finisce in Sede destinazione (arrivo azienda).
