# Installazione

Guida all'installazione di Pinakes su un server web.

## Requisiti

### Server

| Componente | Versione | Note |
|------------|----------|------|
| PHP | 8.0+ | 8.1 consigliato |
| MySQL/MariaDB | 5.7+ / 10.3+ | InnoDB required |
| Apache/Nginx | 2.4+ / 1.18+ | mod_rewrite per Apache |

### Estensioni PHP

Richieste:
- `mysqli` - Connessione database
- `json` - Parsing JSON
- `mbstring` - Stringhe multibyte
- `curl` - Richieste HTTP
- `zip` - Gestione archivi

Opzionali:
- `gd` o `imagick` - Elaborazione immagini
- `intl` - Internazionalizzazione
- `opcache` - Cache bytecode

### Spazio Disco

- Applicazione: ~50 MB
- Dipendenze: ~100 MB
- Database: variabile (dipende dal catalogo)
- Upload: variabile (copertine, ebook)

Consigliato: almeno 1 GB disponibile

## Installazione

### 1. Download

```bash
# Clona repository
git clone https://github.com/fabiodalez-dev/Pinakes.git biblioteca

# Oppure scarica release
wget https://github.com/fabiodalez-dev/Pinakes/releases/latest/download/pinakes.zip
unzip pinakes.zip -d biblioteca
```

### 2. Configurazione Web Server

**Apache** - Crea virtual host:

```apache
<VirtualHost *:80>
    ServerName biblioteca.example.com
    DocumentRoot /var/www/biblioteca/public

    <Directory /var/www/biblioteca/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx**:

```nginx
server {
    listen 80;
    server_name biblioteca.example.com;
    root /var/www/biblioteca/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 3. Permessi

```bash
cd /var/www/biblioteca

# Cartelle scrivibili
chmod -R 755 storage
chmod -R 755 public/uploads

# Proprietario webserver
chown -R www-data:www-data storage public/uploads
```

### 4. Configurazione Ambiente

```bash
# Copia template
cp .env.example .env

# Modifica con le tue impostazioni
nano .env
```

Contenuto `.env`:

```ini
DB_HOST=localhost
DB_NAME=pinakes_db
DB_USER=pinakes_user
DB_PASS=password_sicura

APP_URL=https://biblioteca.example.com
APP_DEBUG=false
```

### 5. Wizard Installazione

1. Apri il browser su `http://biblioteca.example.com/installer`
2. Segui i passaggi:
   - Verifica requisiti
   - Configurazione database
   - Creazione admin
   - Impostazioni iniziali
3. Al termine, elimina la cartella `installer/` (opzionale)

## Post-Installazione

### Cron Job

Per attività pianificate (notifiche, pulizia):

```bash
# Aggiungi al crontab
crontab -e

# Esegui ogni 5 minuti
*/5 * * * * cd /var/www/biblioteca && php cli/cron.php >> /dev/null 2>&1
```

### HTTPS

Consigliato per produzione:

```bash
# Con Certbot (Let's Encrypt)
certbot --apache -d biblioteca.example.com
```

Aggiorna `.env`:
```ini
APP_URL=https://biblioteca.example.com
```

### Backup Iniziale

Dopo l'installazione, crea subito un backup:

1. Vai in **Amministrazione → Backup**
2. Clicca **Crea backup completo**
3. Scarica e conserva in luogo sicuro

## Aggiornamento

Per aggiornare un'installazione esistente:

1. **Preferito**: Usa l'aggiornamento integrato
   - Vai in **Amministrazione → Aggiornamenti**
   - Clicca **Aggiorna** se disponibile

2. **Manuale**: Vedi [guida aggiornamento](../admin/updater.md)

## Troubleshooting

### Errore 500

Controlla:
1. Log Apache/Nginx: `/var/log/apache2/error.log`
2. Log PHP: configurato in `php.ini`
3. Log applicazione: `storage/logs/app.log`

### Pagina bianca

```bash
# Verifica errori PHP
php -l public/index.php

# Attiva debug temporaneamente
# In .env:
APP_DEBUG=true
```

### Database connection failed

1. Verifica credenziali in `.env`
2. Verifica che MySQL sia in esecuzione
3. Verifica che l'utente abbia permessi sul database

### Permessi insufficienti

```bash
# Fix permessi
chown -R www-data:www-data /var/www/biblioteca
find /var/www/biblioteca -type d -exec chmod 755 {} \;
find /var/www/biblioteca -type f -exec chmod 644 {} \;
chmod -R 775 storage public/uploads
```

---

## Domande Frequenti (FAQ)

### 1. Posso installare Pinakes su hosting condiviso?

Sì, Pinakes funziona su hosting condiviso se il provider soddisfa i requisiti:
- PHP 8.0 o superiore
- MySQL 5.7+ o MariaDB 10.3+
- Accesso FTP o File Manager

**Procedura**:
1. Carica i file via FTP nella root del dominio
2. Configura il file `.env` con le credenziali database
3. Apri `tuosito.it/installer` nel browser
4. Segui il wizard di installazione

**Nota**: Alcuni hosting limitano le funzioni PHP. Verifica che `curl`, `zip` e `mysqli` siano abilitati.

---

### 2. Quanto spazio disco serve per Pinakes?

Spazio minimo consigliato:

| Componente | Spazio |
|------------|--------|
| Applicazione | ~50 MB |
| Dipendenze | ~100 MB |
| Database | 10-500 MB (dipende dal catalogo) |
| Copertine libri | Variabile (~200 KB per copertina) |
| Backup | Variabile |

**Raccomandazione**: Inizia con almeno 1 GB libero. Per cataloghi grandi (10.000+ libri con copertine), considera 5+ GB.

---

### 3. Come configuro il cron job per le notifiche automatiche?

Il cron job esegue operazioni pianificate (email scadenze, aggiornamento stati, ecc.).

**Linux/cPanel**:
```bash
# Ogni 5 minuti
*/5 * * * * cd /path/to/pinakes && php cli/cron.php >> /dev/null 2>&1
```

**Plesk**: Usa "Attività pianificate" nel pannello.

**Hosting senza accesso cron**: Alcuni provider offrono alternative web-based. In alternativa, puoi usare servizi esterni come `cron-job.org` per chiamare un URL dedicato.

---

### 4. Come installo il certificato SSL (HTTPS)?

**Con Let's Encrypt (gratuito)**:
```bash
# Apache
sudo certbot --apache -d biblioteca.tuosito.it

# Nginx
sudo certbot --nginx -d biblioteca.tuosito.it
```

**Su hosting condiviso**: Usa il pannello del provider (cPanel → SSL/TLS, Plesk → Certificati).

**Dopo l'installazione**:
1. Aggiorna `.env`: `APP_URL=https://biblioteca.tuosito.it`
2. In Impostazioni → Avanzate, attiva "Forza HTTPS"

---

### 5. Posso usare SQLite invece di MySQL?

No, Pinakes richiede MySQL o MariaDB. Il sistema usa funzionalità specifiche di MySQL:
- Transazioni InnoDB
- Foreign keys
- Query specifiche MySQL

Se non hai accesso a MySQL, molti hosting gratuiti come InfinityFree o 000webhost offrono database MySQL.

---

### 6. Come migro Pinakes su un nuovo server?

1. **Sul vecchio server**:
   - Crea un backup completo (Amministrazione → Backup)
   - Scarica il file ZIP

2. **Sul nuovo server**:
   - Installa Pinakes normalmente
   - Carica il backup via Amministrazione → Backup
   - Clicca "Ripristina"

3. **Aggiorna DNS**: Punta il dominio al nuovo server

**Alternativa manuale**:
```bash
# Esporta database
mysqldump -u user -p pinakes > backup.sql

# Copia file
rsync -avz /var/www/biblioteca nuovo_server:/var/www/

# Importa database sul nuovo server
mysql -u user -p pinakes < backup.sql
```

---

### 7. L'installer mostra "requisiti non soddisfatti", cosa faccio?

Verifica ogni requisito nella lista:

| Errore | Soluzione |
|--------|-----------|
| PHP versione | Chiedi all'hosting di aggiornare PHP |
| Estensione mysqli | Attiva in php.ini o pannello hosting |
| Estensione curl | Attiva in php.ini o pannello hosting |
| Cartella non scrivibile | `chmod 755 storage` e `chmod 755 public/uploads` |
| ZipArchive | Attiva estensione zip in PHP |

Se usi cPanel: vai in "Select PHP Version" per abilitare le estensioni.

---

### 8. Come eseguo l'installazione senza wizard (CLI)?

Per installazioni automatizzate o headless:

```bash
# 1. Copia configurazione
cp .env.example .env

# 2. Modifica .env con le credenziali corrette
nano .env

# 3. Esegui installazione CLI
php installer/install-cli.php

# 4. Crea admin
php cli/create-admin.php admin@example.com password
```

Utile per deployment automatico con script o CI/CD.

---

### 9. Come aggiorno PHP da 7.x a 8.x?

1. **Verifica compatibilità**: Pinakes richiede PHP 8.0+
2. **Su hosting condiviso**: Cambia versione dal pannello (cPanel → PHP Selector)
3. **Su VPS**:
   ```bash
   # Ubuntu/Debian
   sudo apt install php8.1 php8.1-mysql php8.1-curl php8.1-zip php8.1-mbstring
   sudo update-alternatives --set php /usr/bin/php8.1
   ```
4. **Riavvia Apache/Nginx**

---

### 10. Pinakes non carica dopo l'installazione, schermo bianco

Soluzioni in ordine:

1. **Attiva debug**: In `.env`, imposta `APP_DEBUG=true`
2. **Controlla log PHP**: `tail -50 /var/log/php/error.log`
3. **Controlla log app**: `tail -50 storage/logs/app.log`
4. **Verifica sintassi**: `php -l public/index.php`
5. **Permessi**: `chmod -R 755 storage`
6. **mod_rewrite**: Assicurati sia attivo (Apache: `a2enmod rewrite`)

Se nulla funziona, verifica che DocumentRoot punti a `public/`, non alla root del progetto.
