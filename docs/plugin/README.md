# 🔌 Sistema Plugin - Guida Completa

Benvenuto nella documentazione del sistema plugin di Pinakes, che permette di estendere le funzionalità dell'applicazione senza modificare il codice core.

## Panoramica

Pinakes supporta un sistema di plugin flessibile e potente per aggiungere nuove funzionalità. I plugin possono:
- Estendere i campi dei libri
- Aggiungere fonti di scraping personalizzate
- Creare nuove pagine admin
- Integrarsi con API esterne
- Modificare il comportamento dell'applicazione tramite hooks

## 📖 Guide Disponibili (Per Utenti)

### [→ Plugin Bundled](./plugin-bundled.md)
Plugin inclusi di default in Pinakes.

**Plugin disponibili:**
- **Open Library**: Scraping da OpenLibrary.org + Google Books fallback
- **Z39.50 Server**: API SRU 1.2 per interoperabilità con altri sistemi
- **API Book Scraper**: Scraping avanzato da fonti multiple
- **Digital Library**: Gestione eBook e audiolibri con streaming
- **Dewey Editor**: Editor visuale per classificazione Dewey

### [→ Gestione Plugin](./gestione-plugin.md)
Come installare, attivare e configurare i plugin.

**Cosa imparerai:**
- Installare plugin da ZIP
- Attivare/Disattivare plugin
- Configurare impostazioni plugin
- Disinstallare plugin
- Risolvere problemi comuni

## 🎯 Quick Start

**Attivare un plugin:**

1. **Dashboard → Plugin**
2. Trova il plugin nella lista
3. Clicca **"Attiva"**
4. Configura se necessario
5. ✅ Plugin attivo!

**Installare plugin da ZIP:**

1. **Dashboard → Plugin → "Carica Plugin"**
2. Seleziona file ZIP
3. Carica
4. Attiva
5. ✅ Pronto all'uso!

## 💡 Plugin Bundled

### Open Library
Fonte principale per lo scraping metadati libri.
- **Stato:** Preinstallato e attivo
- **Config:** Nessuna configurazione richiesta
- **Usa:** Google Books come fonte primaria, OpenLibrary come fallback

### Z39.50 Server (SRU)
API per interoperabilità con altri sistemi bibliotecari.
- **Stato:** Preinstallato
- **Config:** Abilita/Disabilita, configura server esterni
- **Usa:** Permette ad altri sistemi di interrogare il tuo catalogo

### Digital Library
Gestione contenuti digitali (eBook, audiolibri).
- **Stato:** Preinstallato
- **Config:** Configura storage e formati supportati
- **Usa:** Carica e gestisci contenuti digitali con streaming

### Dewey Editor
Editor visuale per classificazioni Dewey.
- **Stato:** Preinstallato
- **Config:** Nessuna
- **Usa:** Personalizza l'albero delle classificazioni Dewey

## ⚠️ Plugin NON Bundled

**Scraping Pro**: Plugin premium per scraping avanzato
- **Non incluso** in Pinakes open-source
- Richiede licenza separata
- Contatta lo sviluppatore per informazioni

## 🔗 Collegamenti Utili

- [→ Developer: Creare Plugin](../developer/plugin-development.md)
- [→ Developer: Hook System](../developer/hooks.md)
- [→ Documentazione Tecnica Plugin](../../PLUGIN_SYSTEM.md)

## ❓ Domande Frequenti

**D: I plugin sono sicuri?**
R: I plugin bundled sono testati e sicuri. Per plugin di terze parti, installa solo da fonti fidate.

**D: Posso disinstallare un plugin bundled?**
R: Sì, ma alcuni sono raccomandati per il funzionamento ottimale (es: Open Library per scraping).

**D: Come creo un plugin personalizzato?**
R: Consulta la [guida developer](../developer/plugin-development.md) per creare plugin custom.

**D: I plugin rallentano il sistema?**
R: I plugin ben scritti hanno impatto minimo. Disattiva plugin non utilizzati per ottimizzare.

---

**Ultimo aggiornamento:** Dicembre 2025
**Compatibile con:** Pinakes v0.4.1+
