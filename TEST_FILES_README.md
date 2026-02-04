# File di Test per Import

Questi file sono stati creati per testare le funzionalità di import dopo le correzioni ai bind_param.

## File disponibili

### 1. test_librarything.tsv
**Formato:** TSV (Tab-Separated Values) - formato nativo LibraryThing
**Scopo:** Test completo dell'import LibraryThing con tutti i campi

**Contenuto:**
- 6 libri di esempio con titoli italiani famosi
- Tutti i campi LibraryThing compilati (BCID, Tags, Collections, Rating, etc.)
- Casi edge: caratteri accentati, apostrofi, virgole
- Date in vari formati (YYYY-MM-DD)
- Valori numerici (rating 4-5, prezzi, page count)
- Campi testuali lunghi (review, summary, comments)

**Come usare:**
1. Vai a **Plugin → LibraryThing → Import**
2. Seleziona `test_librarything.tsv`
3. Verifica che tutti i 6 libri vengano importati correttamente
4. Controlla che i campi LibraryThing (Tags, Rating, BCID, etc.) siano presenti

**Libri inclusi:**
- Il nome della rosa (Eco) - con prestito attivo
- Se questo è un uomo (Levi) - tutti i campi popolati
- La Divina Commedia (Dante) - edizione commentata
- 1984 (Orwell) - esempio con lingua originale diversa
- Il Gattopardo (Tomasi di Lampedusa) - non ancora letto
- Cent'anni di solitudine (García Márquez) - realismo magico

### 2. test_import_simple.csv
**Formato:** CSV (Comma-Separated Values) - formato semplificato
**Scopo:** Test rapido import base senza campi LibraryThing

**Contenuto:**
- 8 libri con campi base (titolo, autore, isbn, etc.)
- Nessun campo LibraryThing specifico
- Ideale per test rapidi della funzionalità base

**Come usare:**
1. Vai a **Libri → Import CSV** (o funzionalità import standard)
2. Seleziona `test_import_simple.csv`
3. Verifica che gli 8 libri vengano creati

### 3. test_bind_param.php
**Formato:** Script PHP
**Scopo:** Verifica automatica dei bind_param type strings

**Come usare:**
```bash
php test_bind_param.php
```

**Output atteso:**
```
✅ All bind_param tests PASSED!
  - updateBook with LT: 47 chars ✓
  - insertBook with LT: 48 chars ✓
  - updateBook without LT: 20 chars ✓
  - insertBook without LT: 21 chars ✓
```

## Test Sequence Consigliata

1. **Verifica sintassi:**
   ```bash
   php test_bind_param.php
   ```

2. **Test import semplice:**
   - Importa `test_import_simple.csv`
   - Verifica che 8 libri siano stati creati
   - Controlla che i dati base siano corretti

3. **Test import LibraryThing completo:**
   - Importa `test_librarything.tsv`
   - Verifica che 6 libri siano stati creati
   - Controlla campi LibraryThing (Tags, Rating, BCID)
   - Verifica che il campo Barcode sia popolato con EAN

4. **Test export:**
   - Esporta i libri appena importati
   - Verifica che la colonna "Barcode" contenga i valori EAN
   - Verifica che non ci siano celle vuote o errori

5. **Test database:**
   ```sql
   -- Verifica libri di test
   SELECT id, titolo, ean, bcid, rating
   FROM libri
   WHERE titolo IN (
       'Il nome della rosa',
       'Se questo è un uomo',
       '1984'
   );
   ```

## Cleanup dopo i test

Per rimuovere i libri di test:

```sql
-- Lista ID dei libri di test
SELECT id, titolo FROM libri
WHERE bcid LIKE 'BCID00%'
   OR titolo LIKE 'Test Book%';

-- Elimina dopo aver verificato gli ID
-- DELETE FROM libri WHERE bcid LIKE 'BCID00%';
```

O tramite interfaccia: cerca per BCID (BCID001-BCID006) ed elimina manualmente.

## Note

- I file TSV usano TAB (`\t`) come separatore, non virgole
- Gli ISBN sono validi ma potrebbero non corrispondere ai titoli reali
- I BCID sono inventati per il test (BCID001-BCID006)
- Le date sono nel formato YYYY-MM-DD
- I prezzi sono in Euro

## Problemi noti da verificare

✓ bind_param type strings corretti (47, 48, 20, 21 chars)
✓ Campo barcode mappato su EAN nell'export
✓ Caratteri speciali (accenti, apostrofi) gestiti correttamente
⚠️ Verifica che i campi LibraryThing opzionali non causino errori se vuoti
