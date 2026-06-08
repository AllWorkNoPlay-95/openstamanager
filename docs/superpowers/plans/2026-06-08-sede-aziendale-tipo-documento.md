# Sede aziendale predefinita sul tipo documento — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettere di associare a un tipo documento una sede aziendale predefinita, applicata automaticamente alla creazione della fattura e al cambio tipo nella fattura aperta, con fallback alla logica utente attuale quando il campo è vuoto.

**Architecture:** Nuova colonna `id_sede_partenza` su `co_tipi_documento`. UI di configurazione tramite override custom dei file piccoli del modulo `tipi_documento` (`custom/edit.php`, `custom/actions.php`). Effetto runtime tramite modifiche mirate al core sui file grandi (`Fattura::build()` per la creazione, `fatture/edit.php` per l'aggiornamento live via JS). Il valore rappresenta la sede aziendale: va in `id_sede_partenza` per vendita (`dir=entrata`), in `id_sede_destinazione` per acquisto (`dir=uscita`).

**Tech Stack:** PHP (OpenSTAManager, base Laravel 12), Eloquent, MySQL/MariaDB, jQuery + select2. Updater OSM versionato (`update/*.sql`).

**Testing note:** Il codebase non ha una suite di test funzionante (nessun `tests/`, nessun `phpunit.xml`) e la logica è DB-bound. La verifica è quindi **manuale (UAT)** secondo i passi del Task 6, più `php -l` per il lint sintattico dei file PHP modificati.

**Spec di riferimento:** `docs/superpowers/specs/2026-06-08-sede-aziendale-tipo-documento-design.md`

---

## File Structure

- **Create:** `update/2_11_1.sql` — migrazione: aggiunta colonna `id_sede_partenza` a `co_tipi_documento`.
- **Create:** `modules/tipi_documento/custom/edit.php` — clone di `modules/tipi_documento/edit.php` + nuovo select "Sede aziendale predefinita".
- **Create:** `modules/tipi_documento/custom/actions.php` — clone di `modules/tipi_documento/actions.php` + persistenza `id_sede_partenza`.
- **Modify:** `modules/fatture/src/Fattura.php` — `build()`, override di `$id_sede` dal tipo documento.
- **Modify:** `modules/fatture/edit.php` — query select `id_tipo_documento` (espone `id_sede_partenza` + `nome_sede`) e handler JS `change`.

---

## Task 1: Migrazione DB — colonna id_sede_partenza

**Files:**
- Create: `update/2_11_1.sql`

- [ ] **Step 1: Creare il file di update**

Create `update/2_11_1.sql` con:

```sql
-- Sede aziendale predefinita per tipo documento
-- Valore NULL = nessun default (fallback alla logica sedi utente)
-- Valore 0 = sede legale; valore > 0 = an_sedi.id
ALTER TABLE `co_tipi_documento`
  ADD `id_sede_partenza` INT NULL DEFAULT NULL AFTER `id_segment`;
```

- [ ] **Step 2: Verificare la sintassi SQL e l'ordinamento versione**

Run:
```bash
cd /Users/mncs/Projects/k-odin/openstamanager
ls update/ | sort -V | grep -A1 -E '^2_11\.sql$'
```
Expected: `2_11.sql` seguito da `2_11_1.sql` (l'updater applica `2.11.1` dopo `2.11`).

- [ ] **Step 3: Applicare la migrazione**

Applicare tramite l'updater OSM (interfaccia: Strumenti → Aggiornamenti), che auto-rileva `2_11_1.sql`.
In alternativa, eseguire manualmente lo statement SQL sul database `co_tipi_documento`.

- [ ] **Step 4: Verificare che la colonna esista**

Eseguire sul DB:
```sql
SHOW COLUMNS FROM `co_tipi_documento` LIKE 'id_sede_partenza';
```
Expected: una riga, `Type = int`, `Null = YES`, `Default = NULL`.

- [ ] **Step 5: Commit**

```bash
cd /Users/mncs/Projects/k-odin/openstamanager
git add update/2_11_1.sql
git commit -m "feat(tipi-documento): add id_sede_partenza column via update 2_11_1"
```

---

## Task 2: UI configurazione — custom edit.php del tipo documento

**Files:**
- Create: `modules/tipi_documento/custom/edit.php` (clone di `modules/tipi_documento/edit.php`)

Il meccanismo `App::filepath('modules/tipi_documento|custom|', 'edit.php')` carica la versione
in `custom/` se presente, altrimenti il core.

- [ ] **Step 1: Clonare il file core nella cartella custom**

Run:
```bash
cd /Users/mncs/Projects/k-odin/openstamanager
mkdir -p modules/tipi_documento/custom
cp modules/tipi_documento/edit.php modules/tipi_documento/custom/edit.php
```

- [ ] **Step 2: Aggiungere il select "Sede aziendale predefinita"**

In `modules/tipi_documento/custom/edit.php`, dentro il blocco `echo '...'`, subito DOPO la
chiusura del `<div class="col-md-3">` del "Sezionale predefinito" (il `</div>` che segue il
campo `id_segment`) e PRIMA del `<div class="col-md-12">` del campo Help, inserire:

```php
		<div class="col-md-3">
			{[ "type": "select", "label": "'.tr('Sede aziendale predefinita').'", "name": "id_sede_partenza", "ajax-source": "sedi_azienda", "value": "$id_sede_partenza$", "help": "'.tr('Sede impostata in automatico nei documenti di questo tipo. In vendita è la sede di partenza, in acquisto la sede di destinazione (arrivo). Vuoto = comportamento standard (sede utente).').'" ]}
		</div>
```

Il frammento risultante (campo Sezionale + nuovo campo + Help):

```php
		<div class="col-md-3">
        
			{[ "type": "select", "label": "'.tr('Sezionale predefinito').'", "name": "id_segment", "required": 1, "ajax-source": "segmenti", "select-options": '.json_encode(['id_module' => $record['dir'] == 'entrata' ? $id_module_vendite : $id_module_acquisti, 'is_sezionale' => 1, 'tipo' => $record['codice_tipo_documento_fe']]).', "value": "$id_segment$" ]}
		</div>

		<div class="col-md-3">
			{[ "type": "select", "label": "'.tr('Sede aziendale predefinita').'", "name": "id_sede_partenza", "ajax-source": "sedi_azienda", "value": "$id_sede_partenza$", "help": "'.tr('Sede impostata in automatico nei documenti di questo tipo. In vendita è la sede di partenza, in acquisto la sede di destinazione (arrivo). Vuoto = comportamento standard (sede utente).').'" ]}
		</div>

        <div class="col-md-12">
            {[ "type": "text", "label": "'.tr('Help').'", "name": "help", "value": "$help$" ]}
        </div>
```

- [ ] **Step 3: Lint sintattico**

Run:
```bash
cd /Users/mncs/Projects/k-odin/openstamanager
php -l modules/tipi_documento/custom/edit.php
```
Expected: `No syntax errors detected in modules/tipi_documento/custom/edit.php`

- [ ] **Step 4: Verifica manuale rapida**

Aprire un tipo documento esistente (Strumenti → Tabelle → Tipi documento → seleziona record).
Expected: compare il campo "Sede aziendale predefinita" (select con le sedi aziendali, incl. "Sede legale") accanto a "Sezionale predefinito". Il valore salvato (se presente) è preselezionato.

- [ ] **Step 5: Commit**

```bash
cd /Users/mncs/Projects/k-odin/openstamanager
git add modules/tipi_documento/custom/edit.php
git commit -m "feat(tipi-documento): add sede aziendale predefinita field (custom edit)"
```

---

## Task 3: Persistenza — custom actions.php del tipo documento

**Files:**
- Create: `modules/tipi_documento/custom/actions.php` (clone di `modules/tipi_documento/actions.php`)

- [ ] **Step 1: Clonare il file core nella cartella custom**

Run:
```bash
cd /Users/mncs/Projects/k-odin/openstamanager
cp modules/tipi_documento/actions.php modules/tipi_documento/custom/actions.php
```

- [ ] **Step 2: Salvare id_sede_partenza nell'azione `update`**

In `modules/tipi_documento/custom/actions.php`, nel `case 'update':`, subito DOPO la riga
`$tipo->id_segment = post('id_segment');`, aggiungere:

```php
                $sede_tipo = post('id_sede_partenza');
                $tipo->id_sede_partenza = ($sede_tipo === '' || $sede_tipo === null) ? null : (int) $sede_tipo;
```

Il frammento risultante:

```php
                $tipo->enabled = post('enabled');
                $tipo->id_segment = post('id_segment');
                $sede_tipo = post('id_sede_partenza');
                $tipo->id_sede_partenza = ($sede_tipo === '' || $sede_tipo === null) ? null : (int) $sede_tipo;
                $tipo->save();
```

Nota: si evita `?: null` perché renderebbe `null` anche la sede legale (`"0"` è falsy in PHP).
Il controllo esplicito su `''`/`null` preserva correttamente lo `0` (sede legale).

- [ ] **Step 3: Lint sintattico**

Run:
```bash
cd /Users/mncs/Projects/k-odin/openstamanager
php -l modules/tipi_documento/custom/actions.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Verifica manuale del salvataggio**

Aprire un tipo documento, impostare "Sede aziendale predefinita" su una sede, salvare.
Riaprire il record. Expected: la sede resta selezionata. Eseguire sul DB:
```sql
SELECT id, name, id_sede_partenza FROM co_tipi_documento WHERE id = <id_record>;
```
Expected: `id_sede_partenza` = id della sede scelta (o 0 per sede legale).

- [ ] **Step 5: Commit**

```bash
cd /Users/mncs/Projects/k-odin/openstamanager
git add modules/tipi_documento/custom/actions.php
git commit -m "feat(tipi-documento): persist id_sede_partenza (custom actions)"
```

---

## Task 4: Auto-set alla creazione — Fattura::build()

**Files:**
- Modify: `modules/fatture/src/Fattura.php` (metodo `build()`, blocco ~righe 138-156)

- [ ] **Step 1: Inserire l'override della sede dal tipo documento**

In `modules/fatture/src/Fattura.php`, individuare il blocco esistente:

```php
        // Imposto, come sede aziendale, la sede legale (0) se disponibile, altrimenti la prima sede disponibile
        $id_sede = 0;
        if (!empty($user->sedi)) {
            // Verifico se la sede legale (0) è tra le sedi dell'utente
            if (in_array(0, $user->sedi)) {
                $id_sede = 0;
            } else {
                // Se la sede legale non è disponibile, prendo la prima sede dell'utente
                $id_sede = $user->sedi[0];
            }
        }

        if ($direzione == 'entrata') {
            $model->id_sede_partenza = $id_sede;
        } else {
            $model->id_sede_destinazione = $id_sede;
        }
```

Inserire, TRA il blocco `if (!empty($user->sedi)) { ... }` e il blocco
`if ($direzione == 'entrata') { ... }`, il seguente override:

```php
        // Override: sede aziendale predefinita dal tipo documento, se impostata.
        // Ha priorità sulla logica delle sedi utente; se null si mantiene il default sopra.
        if ($tipo_documento->id_sede_partenza !== null) {
            $id_sede = (int) $tipo_documento->id_sede_partenza;
        }
```

Risultato finale del blocco:

```php
        $id_sede = 0;
        if (!empty($user->sedi)) {
            if (in_array(0, $user->sedi)) {
                $id_sede = 0;
            } else {
                $id_sede = $user->sedi[0];
            }
        }

        // Override: sede aziendale predefinita dal tipo documento, se impostata.
        if ($tipo_documento->id_sede_partenza !== null) {
            $id_sede = (int) $tipo_documento->id_sede_partenza;
        }

        if ($direzione == 'entrata') {
            $model->id_sede_partenza = $id_sede;
        } else {
            $model->id_sede_destinazione = $id_sede;
        }
```

- [ ] **Step 2: Lint sintattico**

Run:
```bash
cd /Users/mncs/Projects/k-odin/openstamanager
php -l modules/fatture/src/Fattura.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: Verifica manuale (vendita con tipo che ha sede)**

Prerequisito: un tipo documento di vendita con "Sede aziendale predefinita" = sede X (≠ sede legale).
Creare una nuova Fattura di vendita con quel tipo. Expected: nel documento creato, "Sede partenza" = sede X (non sede legale né prima sede utente).

- [ ] **Step 4: Verifica manuale (fallback)**

Creare una Fattura di vendita con un tipo documento che NON ha la sede impostata (`id_sede_partenza` = NULL).
Expected: "Sede partenza" segue la logica attuale (sede legale 0 se accessibile, altrimenti prima sede utente). Comportamento invariato rispetto a prima.

- [ ] **Step 5: Commit**

```bash
cd /Users/mncs/Projects/k-odin/openstamanager
git add modules/fatture/src/Fattura.php
git commit -m "feat(fatture): default sede aziendale da tipo documento alla creazione"
```

---

## Task 5: Aggiornamento live — fatture/edit.php (query + JS)

**Files:**
- Modify: `modules/fatture/edit.php` — query select `id_tipo_documento` (~riga 425) e handler `$("#id_tipo_documento").change` (~riga 1039)

- [ ] **Step 1: Esporre id_sede_partenza e nome_sede nella query del select tipo documento**

Nella query del campo `id_tipo_documento` (`name: "id_tipo_documento"`), aggiungere alle colonne
selezionate, subito dopo `CONCAT_WS(' - ',\`codice_tipo_documento_fe\`, \`title\`) AS descrizione`:

```sql
, `co_tipi_documento`.`id_sede_partenza`, IF(`co_tipi_documento`.`id_sede_partenza` = 0, 'Sede legale', (SELECT `nome_sede` FROM `an_sedi` WHERE `an_sedi`.`id` = `co_tipi_documento`.`id_sede_partenza`)) AS nome_sede
```

La porzione iniziale della query diventa:

```
query=SELECT `co_tipi_documento`.`id`, CONCAT_WS(' - ',`codice_tipo_documento_fe`, `title`) AS descrizione, `co_tipi_documento`.`id_sede_partenza`, IF(`co_tipi_documento`.`id_sede_partenza` = 0, 'Sede legale', (SELECT `nome_sede` FROM `an_sedi` WHERE `an_sedi`.`id` = `co_tipi_documento`.`id_sede_partenza`)) AS nome_sede FROM `co_tipi_documento` LEFT JOIN ...
```

(il resto della query — `FROM`, `LEFT JOIN`, `WHERE`, `ORDER BY` — resta invariato).

- [ ] **Step 2: Aggiornare la sede aziendale al cambio del tipo documento**

Individuare l'handler esistente (~riga 1039):

```php
    $("#id_tipo_documento").change(function() {
         updateSelectOption("id_tipo_documento", $(this).val());
         session_set("superselect,id_tipo_documento",$(this).val(), 0);
    });
```

Sostituirlo con (il campo destinazione dipende dalla direzione, decisa lato server tramite `$dir`):

```php
    $("#id_tipo_documento").change(function() {
         updateSelectOption("id_tipo_documento", $(this).val());
         session_set("superselect,id_tipo_documento",$(this).val(), 0);

         var tipoData = $(this).selectData();
         if (tipoData && tipoData.id_sede_partenza != null && tipoData.id_sede_partenza !== "") {
             $("#<?php echo ($dir == 'entrata') ? 'id_sede_partenza' : 'id_sede_destinazione'; ?>").selectSetNew(tipoData.id_sede_partenza, tipoData.nome_sede);
         }
    });
```

Nota: la guardia `!= null && !== ""` salta i tipi senza sede (NULL), ma applica la sede legale
quando `id_sede_partenza` è `"0"` (stringa non vuota). `selectSetNew(0, "Sede legale")` è valido.

- [ ] **Step 3: Lint sintattico**

Run:
```bash
cd /Users/mncs/Projects/k-odin/openstamanager
php -l modules/fatture/edit.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: Verifica manuale (cambio tipo live, vendita)**

Aprire una Fattura di vendita in stato Bozza (così il tipo è modificabile). Cambiare il "Tipo documento"
scegliendone uno con "Sede aziendale predefinita" impostata. Expected: il campo "Sede partenza" si
aggiorna automaticamente alla sede del tipo, senza ricaricare la pagina.

- [ ] **Step 5: Verifica manuale (acquisto)**

Ripetere su una Fattura di acquisto in Bozza con un tipo acquisto che ha la sede impostata.
Expected: si aggiorna il campo "Sede destinazione" (sede di arrivo azienda).

- [ ] **Step 6: Commit**

```bash
cd /Users/mncs/Projects/k-odin/openstamanager
git add modules/fatture/edit.php
git commit -m "feat(fatture): aggiorna sede aziendale al cambio tipo documento"
```

---

## Task 6: Verifica end-to-end (UAT)

**Files:** nessuno (solo verifica funzionale).

- [ ] **Step 1: Setup dati**

Creare/usare un sezionale "Rende" su Fatture di vendita (es. maschera `###/RE`) e una sede aziendale
"Rende" (Anagrafiche → Azienda → Sedi). Creare un tipo documento "Fattura di Vendita Rende" (dir
entrata, codice FE TD01) con Sezionale predefinito = Rende e Sede aziendale predefinita = sede Rende.

- [ ] **Step 2: Creazione**

Nuova Fattura di vendita con tipo "Fattura di Vendita Rende".
Expected: Sezionale = Rende (`###/RE`) E Sede partenza = sede Rende, entrambi auto-impostati.

- [ ] **Step 3: Cambio tipo live**

In una fattura Bozza, cambiare tipo verso "Fattura di Vendita Rende".
Expected: Sede partenza si aggiorna a sede Rende in tempo reale.

- [ ] **Step 4: Retrocompatibilità**

Creare una Fattura di vendita con un tipo documento privo di sede aziendale.
Expected: Sede partenza = sede legale / prima sede utente (comportamento invariato).

- [ ] **Step 5: Acquisto**

Tipo documento di acquisto con Sede aziendale predefinita impostata → nuova Fattura di acquisto.
Expected: Sede destinazione (arrivo azienda) = sede del tipo.

- [ ] **Step 6: Override manuale**

In una fattura, cambiare manualmente la sede dopo l'auto-set.
Expected: la modifica manuale viene salvata e rispettata (l'auto-set non la sovrascrive al salvataggio).
