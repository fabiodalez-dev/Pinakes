# Istruzioni di Installazione - API Book Scraper Server

## 📦 Deployment Rapido

### 1. Carica i File sul Server

Carica l'intera directory `/server` sul tuo hosting. La struttura finale deve essere:

```
/path/to/server/
├── public/          # ← Questo sarà il document root di Apache
├── src/
├── data/
├── logs/
├── admin.php
├── .env.example
└── README.md
```

### 2. Configura Apache

Imposta il **document root** del tuo dominio/sottodominio per puntare a `/path/to/server/public/`.

**Esempio configurazione vhost Apache:**

```apache
<VirtualHost *:80>
    ServerName api.tuodominio.com
    DocumentRoot /path/to/server/public

    <Directory /path/to/server/public>
        AllowOverride All
        Require all granted
    </Directory>

    # Log files (opzionale)
    ErrorLog ${APACHE_LOG_DIR}/api-error.log
    CustomLog ${APACHE_LOG_DIR}/api-access.log combined
</VirtualHost>
```

**Su hosting condiviso:**
- Usa il pannello di controllo (cPanel, Plesk, ecc.)
- Crea un sottodominio (es. `api.tuodominio.com`)
- Imposta il document root su `/server/public/`

### 3. Crea il File di Configurazione

```bash
cd /path/to/server
cp .env.example .env
```

### 4. Genera la Password Hash per l'Admin

Esegui questo comando per generare l'hash della tua password:

```bash
php -r "echo password_hash('la_tua_password_sicura', PASSWORD_DEFAULT);"
```

**Output esempio:**
```
$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
```

### 5. Configura il File .env

Apri `.env` e modifica:

```env
# Database
DB_PATH=data/api_keys.db

# Rate Limiting
RATE_LIMIT_ENABLED=true
RATE_LIMIT_REQUESTS_PER_HOUR=1000
RATE_LIMIT_DIR=data/rate_limits

# Logging
LOG_DIR=logs
LOG_LEVEL=info

# Statistics
STATS_ENABLED=true

# Scraping Configuration
SCRAPER_TIMEOUT=10
SCRAPER_USER_AGENT="Mozilla/5.0 (compatible; PinakesBot/1.0)"

# Admin Interface
ADMIN_ENABLED=true
ADMIN_USERNAME=admin
ADMIN_PASSWORD_HASH=$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
# ↑ SOSTITUISCI CON LA TUA PASSWORD HASH GENERATA AL PASSO 4

# CORS
CORS_ENABLED=true
CORS_ALLOWED_ORIGINS=*
```

**⚠️ IMPORTANTE:** Sostituisci `ADMIN_PASSWORD_HASH` con l'hash generato al passo 4!

### 6. Imposta i Permessi

```bash
chmod 755 public/
chmod 755 data/
chmod 755 data/rate_limits/
chmod 755 logs/
chmod 644 .env
chmod 644 admin.php
chmod 644 public/index.php
```

**Su hosting condiviso:** Usa il file manager del pannello di controllo per impostare i permessi.

### 7. Verifica l'Installazione

Apri il browser e vai a:

```
https://api.tuodominio.com/health
```

Dovresti vedere:
```json
{
  "status": "ok",
  "timestamp": "2025-11-18T10:30:00+00:00",
  "version": "1.0.0"
}
```

✅ Se vedi questo, il server funziona!

### 8. Accedi all'Admin

Vai su:

```
https://api.tuodominio.com/admin.php
```

**Login:**
- Username: `admin` (o quello che hai impostato in `.env`)
- Password: la password che hai usato per generare l'hash al passo 4

### 9. Crea la Prima API Key

Nell'interfaccia admin:

1. Vai alla sezione **"Create New API Key"**
2. Compila:
   - **Name**: `Pinakes Production`
   - **Notes**: `Chiave principale per Pinakes`
3. Clicca **"Create API Key"**
4. **Copia la chiave generata** (verrà mostrata solo una volta!)

Esempio di chiave generata:
```
a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2
```

### 10. Testa l'API

Testa la chiave API appena creata:

```bash
curl -H "Authorization: Bearer TUA_API_KEY" \
     https://api.tuodominio.com/api/books/9788804710707
```

Dovresti ricevere i dati del libro in formato JSON.

### 11. Configura il Plugin in Pinakes

Nel pannello admin di Pinakes:

1. Vai su **Admin → Plugin → API Book Scraper**
2. Configura:
   - **API URL**: `https://api.tuodominio.com/api/books`
   - **API Key**: La chiave generata al passo 9
   - **Timeout**: `10` (secondi)
3. Salva le impostazioni
4. Testa cercando un libro per ISBN

## ✅ Checklist Post-Installazione

- [ ] Il server risponde a `/health`
- [ ] Hai cambiato la password admin di default
- [ ] L'admin interface è accessibile
- [ ] Hai creato almeno una API key
- [ ] Il test con curl funziona
- [ ] Il plugin Pinakes è configurato
- [ ] HTTPS è abilitato (Let's Encrypt consigliato)
- [ ] I permessi dei file sono corretti
- [ ] I log vengono scritti in `logs/access.log`

## 🔐 Sicurezza in Produzione

### Abilita HTTPS

**Con Let's Encrypt (consigliato):**

```bash
sudo certbot --apache -d api.tuodominio.com
```

### Limita le Origini CORS

In `.env`, cambia:

```env
CORS_ALLOWED_ORIGINS=https://tuodominio-pinakes.com
```

Questo permette solo al tuo Pinakes di usare l'API.

### Proteggi l'Admin Interface

Aggiungi protezione con password HTTP Basic in `.htaccess`:

Crea `/server/admin_htaccess.txt`:
```apache
AuthType Basic
AuthName "Admin Access"
AuthUserFile /path/to/.htpasswd
Require valid-user
```

Poi sposta su `admin.php`:
```bash
mv admin_htaccess.txt .htaccess_admin
```

## 🛠️ Risoluzione Problemi

### Errore 500

**Causa:** Errori PHP o permessi

**Soluzione:**
1. Controlla `logs/error.log`
2. Verifica permessi: `chmod 755 data/ logs/`
3. Verifica versione PHP: `php -v` (deve essere 8.0+)

### "API key required"

**Causa:** Chiave API non fornita

**Soluzione:** Verifica di passare la chiave via header `Authorization: Bearer` o `X-API-Key` o query param `?api_key=`

### Database Errors

**Causa:** Database non scrivibile

**Soluzione:**
```bash
chmod 755 data/
rm data/api_keys.db  # se esiste e corrotto
```

Il database verrà ricreato automaticamente.

### "Rate limit exceeded"

**Causa:** Troppe richieste

**Soluzione:** Aspetta 1 ora o aumenta il limite in `.env`:
```env
RATE_LIMIT_REQUESTS_PER_HOUR=5000
```

### I Scrapers Non Funzionano

**Causa:** Siti web cambiati o bloccati

**Soluzione:**
1. Verifica che curl funzioni: `curl -I https://www.libreriauniversitaria.it`
2. Controlla `logs/access.log` per errori
3. Testa manualmente l'URL: `https://www.libreriauniversitaria.it/libri-search/9788804710707.htm`

## 📊 Monitoraggio

### Visualizza i Log in Tempo Reale

```bash
tail -f logs/access.log
tail -f logs/error.log
```

### Statistiche via API

```bash
curl -H "Authorization: Bearer TUA_API_KEY" \
     https://api.tuodominio.com/api/stats?limit=100
```

### Statistiche via Admin

Accedi a `https://api.tuodominio.com/admin.php` e visualizza:
- Numero di API key attive
- Totale richieste
- Successi/fallimenti
- Ultimi 50 accessi

## 🔧 Manutenzione

### Backup del Database

```bash
cp data/api_keys.db data/api_keys.db.backup
```

### Pulizia dei Log Vecchi

```bash
# Mantieni solo ultimi 7 giorni
find logs/ -name "*.log" -mtime +7 -delete
```

### Reset Rate Limiting per una Chiave

```bash
rm data/rate_limits/MD5_HASH_DELLA_CHIAVE.json
```

## 📞 Supporto

Se hai problemi:

1. Controlla i log: `tail -100 logs/error.log`
2. Verifica la configurazione: `cat .env`
3. Testa il health endpoint: `curl https://api.tuodominio.com/health`
4. Consulta il README.md completo

---

**Installazione completata con successo?** Ora puoi usare l'API dal plugin Pinakes!
