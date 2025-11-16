# Guida all'Installazione - Z39.50/SRU Server Plugin

## Requisiti

Prima di installare il plugin, assicurati di avere:

- ✅ Pinakes versione 1.0.0 o superiore
- ✅ PHP 7.4 o superiore
- ✅ MySQL 5.7 o superiore / MariaDB 10.2 o superiore
- ✅ Accesso come amministratore al pannello di Pinakes

## Metodo 1: Installazione tramite Interfaccia Web (Raccomandato)

### Passo 1: Preparare il Plugin

1. Assicurati di avere tutti i file del plugin in una directory:
   ```
   z39-server/
   ├── plugin.json
   ├── Z39ServerPlugin.php
   ├── endpoint.php
   ├── activate.php
   ├── README.md
   ├── INSTALLATION.md
   └── classes/
       ├── SRUServer.php
       ├── CQLParser.php
       ├── RecordFormatter.php
       ├── MARCXMLFormatter.php
       ├── DublinCoreFormatter.php
       └── MODSFormatter.php
   ```

2. Crea un file ZIP contenente tutti i file:
   ```bash
   zip -r z39-server.zip z39-server/
   ```

### Passo 2: Caricare il Plugin

1. Accedi al pannello di amministrazione di Pinakes
2. Vai su **Admin → Plugin**
3. Clicca sul pulsante **"Carica Plugin"**
4. Seleziona il file `z39-server.zip`
5. Clicca **"Installa"**

Il sistema automaticamente:
- Estrarrà i file nella directory corretta
- Creerà le tabelle necessarie nel database
- Configurerà le impostazioni di default

### Passo 3: Attivare il Plugin

1. Nella lista dei plugin, trova **"Z39.50/SRU Server"**
2. Clicca sul pulsante **"Attiva"**
3. Il plugin sarà ora attivo e funzionante

### Passo 4: Aggiungere la Route (MANUALE)

**IMPORTANTE:** Dopo l'attivazione, devi aggiungere manualmente la route SRU al file delle route.

1. Apri il file `/app/Routes/web.php`
2. Cerca la fine del file (prima della riga `};`)
3. Aggiungi il seguente codice:

```php
    // Z39.50/SRU Server Plugin Endpoint
    $app->get('/api/sru', function ($request, $response) use ($app) {
        $db = $app->getContainer()->get('db');
        $pluginManager = $app->getContainer()->get('pluginManager');
        $plugin = $pluginManager->getPluginByName('z39-server');
        $pluginId = $plugin ? (int)$plugin['id'] : null;

        require __DIR__ . '/../../storage/plugins/z39-server/endpoint.php';
        return handleSRURequest($request, $response, $db, $pluginId);
    });
```

4. Salva il file

### Passo 5: Testare l'Installazione

Verifica che il plugin funzioni correttamente:

```bash
# Test explain operation
curl "https://tuo-dominio.it/api/sru?operation=explain"
```

Dovresti ricevere una risposta XML con le informazioni del server.

## Metodo 2: Installazione Manuale

### Passo 1: Caricare i File

1. Carica i file del plugin via FTP/SFTP nella directory:
   ```
   /storage/plugins/z39-server/
   ```

2. Assicurati che i permessi siano corretti:
   ```bash
   chmod 755 /storage/plugins/z39-server
   chmod 644 /storage/plugins/z39-server/*.php
   chmod 644 /storage/plugins/z39-server/classes/*.php
   ```

### Passo 2: Registrare il Plugin nel Database

Esegui le seguenti query SQL:

```sql
-- Inserisci il plugin nella tabella plugins
INSERT INTO plugins (
    name,
    display_name,
    description,
    version,
    author,
    path,
    main_file,
    requires_php,
    requires_app,
    is_active,
    metadata,
    installed_at
) VALUES (
    'z39-server',
    'Z39.50/SRU Server',
    'Server SRU per esporre il catalogo bibliografico tramite protocollo standard',
    '1.0.0',
    'Biblioteca',
    'z39-server',
    'Z39ServerPlugin.php',
    '7.4',
    '1.0.0',
    0,
    '{"category":"protocol","tags":["z39.50","sru","marc"]}',
    NOW()
);

-- Prendi nota dell'ID del plugin appena creato
SET @plugin_id = LAST_INSERT_ID();

-- Crea le tabelle necessarie
CREATE TABLE IF NOT EXISTS z39_access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL COMMENT 'Client IP address',
    user_agent TEXT COMMENT 'Client user agent',
    operation VARCHAR(50) NOT NULL COMMENT 'SRU operation',
    query TEXT COMMENT 'CQL query string',
    format VARCHAR(20) COMMENT 'Record format requested',
    num_records INT DEFAULT 0 COMMENT 'Number of records returned',
    response_time_ms INT COMMENT 'Response time in milliseconds',
    http_status INT COMMENT 'HTTP status code',
    error_message TEXT COMMENT 'Error message if any',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address),
    INDEX idx_operation (operation),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS z39_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    request_count INT DEFAULT 1,
    window_start DATETIME NOT NULL,
    last_request DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip_window (ip_address, window_start),
    INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserisci le impostazioni di default
INSERT INTO plugin_settings (plugin_id, setting_key, setting_value, created_at) VALUES
(@plugin_id, 'server_enabled', 'true', NOW()),
(@plugin_id, 'server_host', 'localhost', NOW()),
(@plugin_id, 'server_port', '80', NOW()),
(@plugin_id, 'server_database', 'catalog', NOW()),
(@plugin_id, 'max_records', '100', NOW()),
(@plugin_id, 'default_records', '10', NOW()),
(@plugin_id, 'supported_formats', 'marcxml,dc,mods,oai_dc', NOW()),
(@plugin_id, 'default_format', 'marcxml', NOW()),
(@plugin_id, 'require_authentication', 'false', NOW()),
(@plugin_id, 'rate_limit_enabled', 'true', NOW()),
(@plugin_id, 'rate_limit_requests', '100', NOW()),
(@plugin_id, 'rate_limit_window', '3600', NOW()),
(@plugin_id, 'enable_logging', 'true', NOW()),
(@plugin_id, 'cql_version', '1.2', NOW()),
(@plugin_id, 'sru_version', '1.2', NOW());
```

### Passo 3: Attivare il Plugin

```sql
UPDATE plugins SET is_active = 1, activated_at = NOW() WHERE name = 'z39-server';
```

### Passo 4: Aggiungere la Route

Segui le istruzioni del Passo 4 del Metodo 1.

## Configurazione Post-Installazione

### 1. Configurare il Server

Accedi alle impostazioni del plugin:

1. Vai su **Admin → Z39.50/SRU Server** (o **Admin → Plugin → Z39.50/SRU Server → Impostazioni**)

2. Configura le seguenti impostazioni secondo le tue necessità:

   **Informazioni Server:**
   - `server_host`: Il dominio del tuo server (es. `biblioteca.esempio.it`)
   - `server_port`: La porta (di solito `80` per HTTP o `443` per HTTPS)
   - `server_database`: Nome identificativo del database (es. `catalog`, `biblio`)

   **Limiti Record:**
   - `max_records`: Massimo numero di record per richiesta (raccomandato: 100)
   - `default_records`: Numero di record di default se non specificato (raccomandato: 10)

   **Formati:**
   - `supported_formats`: Formati supportati separati da virgola
   - `default_format`: Formato di default se non specificato

   **Rate Limiting:**
   - `rate_limit_enabled`: Abilita protezione DoS
   - `rate_limit_requests`: Numero massimo di richieste
   - `rate_limit_window`: Finestra temporale in secondi (es. 3600 = 1 ora)

### 2. Testare le Operazioni

#### Test Explain
```bash
curl "https://tuo-dominio.it/api/sru?operation=explain"
```

Dovresti ricevere un XML con le informazioni del server.

#### Test SearchRetrieve
```bash
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=cql.anywhere=test&maximumRecords=5"
```

Dovresti ricevere i primi 5 risultati in formato MARCXML.

#### Test con formato Dublin Core
```bash
curl "https://tuo-dominio.it/api/sru?operation=searchRetrieve&query=dc.title=test&recordSchema=dc"
```

### 3. Configurare il Firewall (se necessario)

Se il server è dietro un firewall, assicurati che la porta sia aperta:

```bash
# Per firewalld
sudo firewall-cmd --permanent --add-service=http
sudo firewall-cmd --permanent --add-service=https
sudo firewall-cmd --reload

# Per ufw
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
```

### 4. Configurare CORS (Opzionale)

Se ricevi errori CORS da client esterni, verifica che il plugin abbia i giusti header CORS (già configurati nell'endpoint.php).

### 5. Monitorare i Log

Controlla regolarmente i log per vedere l'utilizzo:

```sql
-- Ultime 100 richieste
SELECT * FROM z39_access_logs ORDER BY created_at DESC LIMIT 100;

-- Richieste per oggi
SELECT COUNT(*) as total_today
FROM z39_access_logs
WHERE DATE(created_at) = CURDATE();

-- Performance media
SELECT AVG(response_time_ms) as avg_response_ms
FROM z39_access_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY);
```

## Risoluzione Problemi

### Problema: "Route not found"

**Soluzione:**
1. Verifica che la route sia stata aggiunta correttamente a `/app/Routes/web.php`
2. Riavvia il server web:
   ```bash
   sudo systemctl restart apache2
   # oppure
   sudo systemctl restart nginx
   sudo systemctl restart php-fpm
   ```

### Problema: "Plugin not found"

**Soluzione:**
1. Verifica che il plugin sia installato correttamente
2. Controlla che il record esista nella tabella `plugins`
3. Verifica i permessi dei file

### Problema: "Database connection failed"

**Soluzione:**
1. Verifica le credenziali del database in `.env`
2. Controlla che MySQL sia in esecuzione
3. Verifica i permessi dell'utente del database

### Problema: "Rate limit exceeded"

**Soluzione:**
1. Aumenta `rate_limit_requests` nelle impostazioni
2. Oppure disabilita temporaneamente: `rate_limit_enabled = false`
3. Pulisci la tabella rate_limits:
   ```sql
   TRUNCATE TABLE z39_rate_limits;
   ```

## Aggiornamenti Futuri

Per aggiornare il plugin in futuro:

1. Disattiva il plugin dall'interfaccia
2. Sostituisci i file del plugin con la nuova versione
3. Esegui eventuali script di migrazione del database
4. Riattiva il plugin

## Supporto

Per assistenza:
- Consulta il file `README.md` per la documentazione completa
- Controlla i log di sistema: `/var/log/apache2/error.log` o `/var/log/nginx/error.log`
- Controlla i log del plugin nella tabella `plugin_logs`

---

**Nota:** Assicurati sempre di fare un backup completo del database e dei file prima di installare o aggiornare plugin.
