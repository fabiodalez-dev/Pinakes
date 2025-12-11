# 🌍 Sistema Traduzioni - Guida Completa

Benvenuto nella documentazione del sistema multilingua di Pinakes.

## Panoramica

Pinakes supporta nativamente più lingue. Attualmente sono disponibili:
- 🇮🇹 **Italiano** (it_IT)
- 🇬🇧 **Inglese** (en_US)

Il sistema è progettato per essere facilmente estensibile con nuove lingue.

## 📖 Guida Utente

### Come Cambiare Lingua

**Per utenti:**
1. Header del sito → Selettore lingua (bandierine)
2. Clicca sulla lingua desiderata
3. La pagina si ricarica nella nuova lingua
4. La preferenza viene salvata

**Per admin:**
1. Dashboard → Impostazioni → Lingua
2. Seleziona lingua predefinita del sistema
3. Salva

### Lingue Disponibili

| Lingua | Codice | Stato | Completezza |
|--------|--------|-------|-------------|
| Italiano | it_IT | ✅ Completo | 100% |
| Inglese | en_US | ✅ Completo | 100% |

## 🔧 Come Funziona

### File di Traduzione

Le traduzioni sono in file JSON nella cartella `locale/`:

```
locale/
├── it_IT.json           # Traduzioni interfaccia italiana
├── en_US.json           # Traduzioni interfaccia inglese
├── routes_it_IT.json    # URL italiani (/libri, /prestiti)
├── routes_en_US.json    # URL inglesi (/books, /loans)
├── dewey_it_IT.json     # Classificazione Dewey italiana
└── dewey_en_US.json     # Classificazione Dewey inglese
```

### Struttura File JSON

```json
{
  "dashboard.title": "Pannello di Controllo",
  "books.add_new": "Aggiungi Nuovo Libro",
  "common.save": "Salva"
}
```

### Funzione i18n()

Nel codice si usa la funzione `i18n()` per tradurre:

```php
<?= i18n('dashboard.title') ?>
// Output italiano: "Pannello di Controllo"
// Output inglese: "Dashboard"
```

## 🆕 Aggiungere una Nuova Lingua

### Per Utenti Avanzati

1. Copia `it_IT.json` in `nuova_lingua.json` (es: `fr_FR.json`)
2. Traduci tutti i valori
3. Copia anche i file `routes_` e `dewey_`
4. Registra la lingua in `config/settings.php`
5. Testa e verifica

### Per Developer

Consulta la [guida developer](../developer/traduzioni.md) per dettagli tecnici completi.

## 📊 Statistiche

- ✅ ~1.500 stringhe traducibili
- ✅ 2 lingue complete (IT, EN)
- ✅ URL localizzati
- ✅ Classificazione Dewey multilingua
- ✅ Email template multilingua
- ✅ Frontend completamente tradotto
- ✅ Admin panel completamente tradotto

## 🔗 Collegamenti Utili

- [→ File Traduzioni](../../locale/)
- [→ Developer: Sistema i18n](../developer/i18n.md)
- [→ Contribuire Traduzioni](../../CONTRIBUTING.md)

## ❓ Domande Frequenti

**D: Posso aggiungere la mia lingua?**
R: Sì! Segui la guida "Aggiungere una Nuova Lingua" sopra.

**D: Le traduzioni sono automatiche?**
R: No, sono traduzioni manuali verificate. NON usiamo traduttori automatici.

**D: Come contribuisco miglioramenti alle traduzioni?**
R: Apri una Pull Request su GitHub con le modifiche ai file JSON.

**D: Gli URL cambiano in base alla lingua?**
R: Sì! `/libri` in italiano diventa `/books` in inglese.

**D: I dati dei libri vengono tradotti?**
R: No, i metadati (titoli, descrizioni) restano nella lingua originale. Solo l'interfaccia cambia.

---

**Ultimo aggiornamento:** Dicembre 2025
**Compatibile con:** Pinakes v0.4.1+
