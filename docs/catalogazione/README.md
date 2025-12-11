# 📊 Sistema di Catalogazione - Guida Completa

Benvenuto nella sezione dedicata alla catalogazione dei libri secondo standard biblioteconomici professionali.

## Panoramica

Pinakes supporta due sistemi principali di organizzazione del catalogo:
- **Classificazione Dewey (DDC)**: Sistema decimale per organizzare i libri per argomento
- **Collocazione Fisica**: Sistema per indicare dove si trova fisicamente ogni libro nella biblioteca

## 📖 Guide Disponibili

### [→ Classificazione Dewey](./dewey.md)
Guida completa al sistema Dewey Decimal Classification integrato in Pinakes.

**Cosa imparerai:**
- Cos'è la classificazione Dewey e perché usarla
- Le 10 classi principali (000-900)
- Come classificare i tuoi libri
- Gestione Dewey tramite JSON (dalla v0.3.0)
- Plugin Dewey Editor per modificare le classificazioni

### [→ Sistema di Collocazione](./collocazione.md)
Come organizzare fisicamente i libri sugli scaffali della biblioteca.

**Cosa imparerai:**
- Come funziona scaffale.mensola.posizione
- Come creare scaffali e mensole
- Come generare automaticamente le posizioni
- Come riorganizzare gli scaffali
- Come esportare la mappa della biblioteca

## 🎯 Quick Start

**Configurare gli scaffali:**

1. Vai a **Dashboard → Collocazione**
2. **Aggiungi scaffali**: A, B, C (o codici personalizzati)
3. Per ogni scaffale, **aggiungi mensole** (livelli 1, 2, 3...)
4. ✅ Quando inserisci libri, le posizioni si generano automaticamente!

**Tempo:** ~5 minuti per configurazione iniziale

## 💡 Concetti Chiave

### Dewey vs Collocazione

Sono due cose **diverse ma complementari**:

| Aspetto | Dewey | Collocazione |
|---------|-------|--------------|
| **Cosa classifica** | L'argomento del libro | La posizione fisica |
| **Esempio** | 853 (Narrativa italiana) | A.2.15 (Scaffale A, Mensola 2, Posizione 15) |
| **Quando usarlo** | Per organizzare per contenuto | Per trovare fisicamente il libro |
| **Obbligatorio?** | No (opzionale) | Sì (per trovare il libro) |

**Possono coincidere**: Puoi organizzare gli scaffali per categorie Dewey (es: Scaffale A = 800-899 Letteratura).

### Classificazione Dewey - Struttura

```
000-099: Informatica, Informazione, Opere Generali
100-199: Filosofia e Psicologia
200-299: Religione
300-399: Scienze Sociali
400-499: Lingue
500-599: Scienze Pure
600-699: Tecnologia e Scienze Applicate
700-799: Arti e Ricreazione
800-899: Letteratura
900-999: Storia e Geografia
```

Ogni classe si divide in divisioni (es: 850 = Letteratura Italiana) e sezioni (es: 853 = Narrativa italiana).

### Collocazione - Formato

```
A.2.15
│ │ │
│ │ └─ Posizione: 15° libro da sinistra
│ └─── Mensola: Livello 2
└───── Scaffale: A
```

## 🔧 Gestione Dewey in Pinakes

### Sistema JSON (dalla v0.3.0)

I dati Dewey sono memorizzati in file JSON invece che nel database:

```
locale/
├── dewey_it_IT.json  (1.369 voci in italiano)
└── dewey_en_US.json  (1.369 voci in inglese)
```

**Vantaggi:**
- ✅ Facile da modificare e personalizzare
- ✅ Backup semplice (copia file)
- ✅ Condivisibile tra installazioni
- ✅ Import/Export integrato

### Plugin Dewey Editor

Dalla dashboard admin puoi:
- Visualizzare l'albero completo Dewey
- Aggiungere nuove classificazioni
- Modificare quelle esistenti
- Eliminare categorie non utilizzate
- Esportare/Importare set di classificazioni

## 🆕 Novità dalla Versione 0.3.0

### Migrazione da Database a JSON

**Prima (v0.2.x):**
- Tabella `classificazione` nel database
- Difficile da modificare
- Non condivisibile

**Ora (v0.3.0+):**
- File JSON modificabili
- Plugin editor integrato
- Import/Export facile
- Multilingua nativo

### Breaking Change

La colonna database è stata rinominata:
- ❌ Vecchio: `classificazione_dowey` (typo)
- ✅ Nuovo: `classificazione_dewey` (corretto)

**Nota:** La migrazione viene eseguita automaticamente durante l'aggiornamento.

## 📊 Statistiche

Con il sistema di catalogazione puoi:
- ✅ 1.369 categorie Dewey precaricate (italiano + inglese)
- ✅ Scaffali e mensole illimitati
- ✅ Generazione automatica posizioni
- ✅ Riordinamento drag & drop
- ✅ Export mappa biblioteca in CSV
- ✅ Plugin per personalizzazione Dewey

## 🔗 Collegamenti Utili

- [→ Inserimento Libri](../libri/inserimento.md) - Come assegnare Dewey e collocazione durante l'inserimento
- [→ Plugin Dewey Editor](../plugin/dewey-editor.md) - Personalizzare le classificazioni
- [→ Guida Admin: Gestione Scaffali](../guida-admin/scaffali.md)

## ❓ Domande Frequenti

**D: Devo usare per forza la classificazione Dewey?**
R: No, è opzionale. Molte piccole biblioteche usano solo i generi letterari e la collocazione fisica.

**D: Posso modificare le categorie Dewey?**
R: Sì, tramite il plugin Dewey Editor o modificando direttamente i file JSON.

**D: Cosa succede se cambio la collocazione di un libro?**
R: Aggiorna il sistema e ricorda di spostare fisicamente il libro. Stampa una nuova etichetta se necessario.

**D: Posso avere scaffali con nomi invece di lettere?**
R: Sì! Usa codici come "NAR" (Narrativa), "SAG" (Saggistica), "BAMBINI", ecc.

**D: Come organizzo una biblioteca molto grande?**
R: Usa Dewey per organizzare gli scaffali: Scaffale A = 000-199, Scaffale B = 200-399, ecc.

---

**Ultimo aggiornamento:** Dicembre 2025
**Versione documentazione:** 1.0.0
**Compatibile con:** Pinakes v0.4.1+
