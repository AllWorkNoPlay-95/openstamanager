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

## 2026-06-08 — Sede aziendale predefinita sul tipo documento

**Obiettivo:** associare a un tipo documento una sede aziendale predefinita, applicata
automaticamente alla creazione della fattura e all'aggiornamento live al cambio tipo nella
fattura aperta. Fallback alla logica esistente (sede legale / prima sede utente) quando il
campo è vuoto.

**Semantica:** il valore rappresenta la sede aziendale e viene mappato per direzione:
`id_sede_partenza` per vendita (`dir=entrata`), `id_sede_destinazione` per acquisto (`dir=uscita`).

**File toccati:**

| File | Tipo | Modifica |
|------|------|----------|
| `update/2_11_1.sql` | `[CORE]` (nuovo) | `ALTER TABLE co_tipi_documento ADD id_sede_partenza INT NULL DEFAULT NULL AFTER id_segment`. NULL = nessun default; 0 = sede legale; >0 = `an_sedi.id`. |
| `modules/tipi_documento/custom/edit.php` | `[CUSTOM]` (clone di `edit.php`) | Aggiunto select "Sede aziendale predefinita" (`name=id_sede_partenza`, `ajax-source=sedi_azienda`) accanto a "Sezionale predefinito". |
| `modules/tipi_documento/custom/actions.php` | `[CUSTOM]` (clone di `actions.php`) | Nel `case 'update'`: persiste `id_sede_partenza` (`'' / null → null`, altrimenti `(int)`, così `0`=sede legale è preservato). |
| `modules/fatture/src/Fattura.php` | `[CORE]` | In `build()`, dopo il calcolo di `$id_sede`: se `$tipo_documento->id_sede_partenza !== null` lo usa (priorità sulla logica sedi utente). |
| `modules/fatture/edit.php` | `[CORE]` | (a) query del select `id_tipo_documento`: esposte le colonne `id_sede_partenza` e `nome_sede` (con `IF(...=0,'Sede legale', subquery an_sedi)`). (b) handler `$("#id_tipo_documento").change`: `selectSetNew` sul campo sede aziendale corretto per direzione. |

**Commit:**
- `bf7367adb` — colonna `id_sede_partenza` (update 2_11_1)
- `8815c1289` — campo sede aziendale predefinita (custom edit)
- `b6040e2d9` — persistenza id_sede_partenza (custom actions)
- `1c59e4767` — auto-set in `Fattura::build()`
- `0e985937b` — aggiornamento live al cambio tipo (`fatture/edit.php`)
- `ecf05ab83` — spec + piano (`docs/superpowers/`)

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
- **Convenzione versioni (AGENTS.md):** i file `PATCH` (`2_11_1`) "non dovrebbero contenere
  feature". Qui `2_11_1.sql` contiene un `ALTER` di feature: deviazione consapevole rispetto alla
  convenzione upstream. Valutare se spostarlo nel file della prossima MINOR in caso di rilascio formale.
