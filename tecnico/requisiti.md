# Requisiti di Sistema

Specifiche dettagliate per l'installazione di Pinakes.

## Requisiti Minimi

| Componente | Minimo | Consigliato |
|------------|--------|-------------|
| PHP | 8.0 | 8.1+ |
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.3 | 10.6+ |
| RAM | 512 MB | 1 GB+ |
| Disco | 500 MB | 2 GB+ |

## PHP

### Versione

- **Minima**: PHP 8.0
- **Consigliata**: PHP 8.1 o 8.2
- **Testata fino a**: PHP 8.3

### Estensioni Richieste

| Estensione | Scopo |
|------------|-------|
| `mysqli` | Connessione database MySQL/MariaDB |
| `json` | Encoding/decoding JSON |
| `mbstring` | Supporto stringhe UTF-8 |
| `curl` | Richieste HTTP (scraping, API) |
| `zip` | Estrazione archivi (aggiornamenti, plugin) |

### Estensioni Opzionali

| Estensione | Scopo |
|------------|-------|
| `gd` | Ridimensionamento immagini |
| `imagick` | Elaborazione immagini avanzata |
| `intl` | Formattazione date/numeri locale |
| `opcache` | Cache bytecode (performance) |
| `apcu` | Cache in memoria |

### Configurazione php.ini

```ini
; Valori consigliati
memory_limit = 256M
max_execution_time = 120
upload_max_filesize = 50M
post_max_size = 50M
max_input_vars = 3000

; Sicurezza
expose_php = Off
display_errors = Off
log_errors = On

; Sessioni
session.cookie_httponly = 1
session.cookie_secure = 1    ; Se HTTPS
session.use_strict_mode = 1
```

## Database

### MySQL

- **Minimo**: 5.7
- **Consigliato**: 8.0+
- **Charset**: `utf8mb4`
- **Collation**: `utf8mb4_unicode_ci`
- **Storage Engine**: InnoDB (richiesto)

### MariaDB

- **Minimo**: 10.3
- **Consigliato**: 10.6+
- Stesse impostazioni di MySQL

### Configurazione

```ini
[mysqld]
# Charset
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# InnoDB
default-storage-engine = InnoDB
innodb_file_per_table = 1
innodb_buffer_pool_size = 256M

# Connessioni
max_connections = 100
wait_timeout = 28800
```

## Web Server

### Apache

- **Minimo**: 2.4
- **Moduli richiesti**: `mod_rewrite`, `mod_headers`

Verifica moduli:
```bash
apache2ctl -M | grep -E "rewrite|headers"
```

Abilita se mancanti:
```bash
a2enmod rewrite headers
systemctl restart apache2
```

### Nginx

- **Minimo**: 1.18
- Richiede configurazione `try_files` per routing

## Sistema Operativo

### Linux (Consigliato)

- Ubuntu 20.04+
- Debian 11+
- CentOS 8+ / Rocky Linux 8+
- Qualsiasi distribuzione con PHP 8.0+

### Windows

- Windows Server 2019+
- XAMPP / WAMP per sviluppo locale
- IIS con PHP FastCGI

### macOS

- macOS 12+
- MAMP per sviluppo locale

## Rete

| Servizio | Porta | Note |
|----------|-------|------|
| HTTP | 80 | Redirect a HTTPS |
| HTTPS | 443 | Consigliato per produzione |
| MySQL | 3306 | Solo localhost |
| SMTP | 25/465/587 | Per invio email |

### Firewall

```bash
# UFW (Ubuntu)
ufw allow 80/tcp
ufw allow 443/tcp
```

## Verifica Requisiti

Pinakes include un checker automatico:

1. Accedi a `/installer` prima dell'installazione
2. La prima pagina mostra lo stato di tutti i requisiti
3. Gli elementi rossi sono bloccanti
4. Gli elementi gialli sono warning

### Check Manuale

```bash
# Versione PHP
php -v

# Estensioni caricate
php -m | grep -E "mysqli|json|mbstring|curl|zip"

# Versione MySQL
mysql --version

# Spazio disco
df -h
```

---

## Domande Frequenti (FAQ)

### 1. Pinakes funziona con PHP 7.4?

**No**, Pinakes richiede **PHP 8.0 o superiore**.

**Motivi:**
- Uso di typed properties (`public string $name`)
- Union types (`int|string`)
- Named arguments
- Match expressions
- Constructor property promotion

**Upgrade:**
```bash
# Ubuntu/Debian
apt install php8.1 php8.1-mysqli php8.1-mbstring php8.1-curl php8.1-zip
```

---

### 2. Posso usare SQLite invece di MySQL?

**No**, Pinakes è progettato esclusivamente per MySQL/MariaDB.

**Motivi:**
- Uso di `INSERT ... ON DUPLICATE KEY UPDATE`
- Transazioni con `FOR UPDATE` locking
- Funzioni specifiche MySQL (`GROUP_CONCAT`, `JSON_*`)
- Indici FULLTEXT per ricerca

**Alternative:**
- MySQL 5.7+ (minimo)
- MariaDB 10.3+ (consigliato, drop-in replacement)
- MySQL 8.0+ (ottimale, miglior performance JSON)

---

### 3. Quali estensioni PHP sono obbligatorie?

**Obbligatorie:**

| Estensione | Scopo | Verifica |
|------------|-------|----------|
| `mysqli` | Database | `php -m | grep mysqli` |
| `json` | API JSON | Inclusa di default in PHP 8+ |
| `mbstring` | UTF-8 | `php -m | grep mbstring` |
| `curl` | HTTP/Scraping | `php -m | grep curl` |
| `zip` | Aggiornamenti | `php -m | grep zip` |

**Installazione Ubuntu:**
```bash
apt install php8.1-mysqli php8.1-mbstring php8.1-curl php8.1-zip
```

---

### 4. Quanto spazio disco serve?

**Installazione base:** ~50 MB

**Spazio aggiuntivo:**
| Componente | Stima |
|------------|-------|
| Copertine libri (1000 libri) | 200-500 MB |
| Backup database | 10-100 MB per backup |
| Log files | 10-50 MB |
| Cache | 5-20 MB |

**Consiglio:** Prevedi almeno 2 GB per crescita futura.

---

### 5. Pinakes funziona su hosting condiviso?

**Sì**, con alcune condizioni:

**Requisiti hosting:**
- PHP 8.0+
- MySQL 5.7+
- `mod_rewrite` abilitato
- Accesso a `.htaccess`
- Almeno 256 MB RAM PHP

**Limitazioni comuni:**
- Alcuni hosting bloccano `curl` verso esterni
- Timeout brevi possono interrompere import grandi
- Nessun accesso SSH per cron job

**Hosting testati:**
- SiteGround ✓
- Aruba (piani recenti) ✓
- OVH ✓
- Altervista ❌ (PHP vecchio)

---

### 6. Come verifico se il mio server soddisfa i requisiti?

**Metodo 1 - Installer:**
Accedi a `/installer` prima dell'installazione. La prima pagina mostra lo stato di tutti i requisiti con semaforo verde/rosso.

**Metodo 2 - CLI:**
```bash
# Versione PHP
php -v

# Estensioni
php -m | grep -E "mysqli|json|mbstring|curl|zip"

# Versione MySQL
mysql --version

# Configurazione PHP
php -i | grep -E "memory_limit|upload_max"
```

---

### 7. Quali porte devono essere aperte sul firewall?

| Porta | Servizio | Direzione |
|-------|----------|-----------|
| 80 | HTTP | In ingresso |
| 443 | HTTPS | In ingresso |
| 3306 | MySQL | Solo localhost |
| 25/465/587 | SMTP | In uscita |

**Esempio UFW (Ubuntu):**
```bash
ufw allow 80/tcp
ufw allow 443/tcp
# MySQL NON deve essere esposto pubblicamente
```

---

### 8. Posso installare su Windows?

**Sì**, con alcune differenze:

**Sviluppo locale:**
- XAMPP (consigliato): include PHP, MySQL, Apache
- WAMP: alternativa popolare
- Laragon: moderno e veloce

**Produzione Windows Server:**
- IIS con PHP FastCGI
- Apache per Windows
- Percorsi: usare `/` o `\\` (Pinakes gestisce entrambi)

**Attenzione:**
- Case-insensitive filesystem (differenze con Linux)
- Newline CRLF vs LF
- Permessi file diversi

---

### 9. Qual è la configurazione php.ini consigliata?

```ini
; Memoria e tempo
memory_limit = 256M
max_execution_time = 120
max_input_time = 120

; Upload
upload_max_filesize = 50M
post_max_size = 50M
max_file_uploads = 20
max_input_vars = 3000

; Sicurezza
expose_php = Off
display_errors = Off
log_errors = On
error_log = /path/to/php-error.log

; Sessioni
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1

; Performance (opzionale)
opcache.enable = 1
opcache.memory_consumption = 128
```

---

### 10. Pinakes funziona con Nginx invece di Apache?

**Sì**, Nginx è pienamente supportato.

**Configurazione minima:**
```nginx
server {
    listen 80;
    server_name biblioteca.esempio.it;
    root /var/www/pinakes/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(ht|git|env) {
        deny all;
    }
}
```

**Differenze da Apache:**
- Nessun `.htaccess` (regole nel config Nginx)
- Usa PHP-FPM invece di mod_php
- Performance generalmente migliori
