# Sistema di Auto-Registrazione Route per Plugin

## Indice

1. [Introduzione](#introduzione)
2. [Come Funziona](#come-funziona)
3. [Configurazione App](#configurazione-app)
4. [Creare un Plugin con Auto-Route](#creare-un-plugin-con-auto-route)
5. [Migrare Plugin Esistenti](#migrare-plugin-esistenti)
6. [Esempi Completi](#esempi-completi)
7. [Troubleshooting](#troubleshooting)

---

## Introduzione

### Il Problema

Prima dell'implementazione del sistema di auto-registrazione, ogni plugin che necessitava di route personalizzate richiedeva:

1. ❌ Modifica manuale di `app/Routes/web.php`
2. ❌ Rischio di errori durante l'editing
3. ❌ Difficoltà nella manutenzione
4. ❌ Conflitti tra plugin
5. ❌ Processo di installazione complesso

### La Soluzione

Il nuovo sistema utilizza un **hook dedicato** (`app.routes.register`) che permette ai plugin di registrare automaticamente le proprie route quando vengono attivati.

**Vantaggi:**
- ✅ Zero modifiche manuali a `web.php`
- ✅ Installazione semplificata
- ✅ Route isolate per plugin
- ✅ Disattivazione automatica route con plugin
- ✅ Manutenzione facilitata

---

## Come Funziona

### Architettura

```
┌─────────────────────────────────────┐
│   app/Routes/web.php                │
│                                     │
│   1. Carica route standard          │
│   2. Trigger hook:                  │
│      app.routes.register            │
└──────────────┬──────────────────────┘
               │
               │ doAction('app.routes.register', [$app])
               │
               ▼
┌─────────────────────────────────────┐
│   Hook Manager                      │
│   - Cerca plugin attivi             │
│   - Chiama registerRoutes($app)     │
└──────────────┬──────────────────────┘
               │
               │ Foreach plugin attivo
               │
    ┌──────────┴──────────┬──────────────────┐
    ▼                     ▼                  ▼
┌─────────┐          ┌─────────┐       ┌─────────┐
│ Plugin1 │          │ Plugin2 │       │ Plugin3 │
│         │          │         │       │         │
│ Routes  │          │ Routes  │       │ Routes  │
└─────────┘          └─────────┘       └─────────┘
```

### Flusso di Esecuzione

1. **Caricamento App**: L'applicazione si avvia e carica `web.php`
2. **Route Standard**: Vengono registrate le route di base dell'app
3. **Hook Trigger**: Alla fine di `web.php` viene chiamato `doAction('app.routes.register', [$app])`
4. **Plugin Attivi**: Hook Manager identifica i plugin attivi che hanno registrato l'hook
5. **Registrazione Route**: Ogni plugin registra le sue route tramite `registerRoutes($app)`

---

## Configurazione App

### Modifica a `app/Routes/web.php`

Aggiungi questo codice **alla fine del file** `app/Routes/web.php`:

```php
// ============================================================
// Plugin Routes Hook
// ============================================================
// Permette ai plugin di registrare dinamicamente le proprie route
// senza modificare manualmente questo file

try {
    $hookManager = $app->getContainer()->get('hookManager');
    $hookManager->doAction('app.routes.register', [$app]);
} catch (\Throwable $e) {
    // Log dell'errore senza bloccare l'applicazione
    error_log('[Routes] Error loading plugin routes: ' . $e->getMessage());
}
```

**Nota**: Questa modifica va fatta **una sola volta** e abilita il sistema per tutti i plugin.

---

## Creare un Plugin con Auto-Route

### Struttura File Plugin

```
storage/plugins/mio-plugin/
├── plugin.json                 # Metadata plugin
├── MioPlugin.php              # Classe principale
├── endpoint.php               # (opzionale) Handler route
├── classes/                   # (opzionale) Classi helper
└── README.md                  # Documentazione
```

### 1. File `plugin.json`

```json
{
  "name": "mio-plugin",
  "display_name": "Mio Plugin",
  "description": "Plugin con route personalizzate",
  "version": "1.0.0",
  "author": "Il tuo nome",
  "capabilities": {
    "routes": true
  }
}
```

### 2. Classe Principale `MioPlugin.php`

```php
<?php

class MioPlugin
{
    private $db;
    private $pluginId;

    public function __construct($db, $pluginId)
    {
        $this->db = $db;
        $this->pluginId = $pluginId;
    }

    /**
     * Registra gli hook del plugin
     */
    public function registerHooks($hookManager): void
    {
        $hooks = [
            // Hook per registrazione route
            ['app.routes.register', 'registerRoutes', 10],

            // Altri hook del plugin...
        ];

        foreach ($hooks as [$hookName, $method, $priority]) {
            $hookManager->addAction($hookName, [$this, $method], $priority);
        }
    }

    /**
     * Registra le route del plugin
     *
     * @param object $app Istanza Slim App
     */
    public function registerRoutes($app): void
    {
        // Route GET semplice
        $app->get('/api/mio-plugin/test', function ($request, $response) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Plugin funzionante!',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Route POST con validazione
        $app->post('/api/mio-plugin/action', function ($request, $response) use ($app) {
            $data = $request->getParsedBody();

            // Validazione input
            if (empty($data['param'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Parametro mancante'
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(400);
            }

            // Logica del plugin...
            $result = $this->executeAction($data['param']);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $result
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Route con file esterno
        $app->get('/api/mio-plugin/advanced', function ($request, $response) use ($app) {
            $db = $app->getContainer()->get('db');
            $pluginManager = $app->getContainer()->get('pluginManager');
            $plugin = $pluginManager->getPluginByName('mio-plugin');
            $pluginId = $plugin ? (int)$plugin['id'] : null;

            // Carica handler esterno
            $endpointFile = __DIR__ . '/endpoint.php';
            if (file_exists($endpointFile)) {
                require_once $endpointFile;
                return handleAdvancedRequest($request, $response, $db, $pluginId);
            }

            // Fallback
            $response->getBody()->write(json_encode([
                'error' => 'Endpoint non trovato'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        });
    }

    private function executeAction($param)
    {
        // Implementazione logica
        return ['processed' => $param];
    }
}
```

### 3. File Endpoint Esterno `endpoint.php` (opzionale)

```php
<?php

/**
 * Handler per route complesse
 * Mantiene la classe principale pulita
 */
function handleAdvancedRequest($request, $response, $db, $pluginId)
{
    $queryParams = $request->getQueryParams();

    // Logica complessa...

    $response->getBody()->write(json_encode([
        'success' => true,
        'plugin_id' => $pluginId
    ]));

    return $response->withHeader('Content-Type', 'application/json');
}
```

---

## Migrare Plugin Esistenti

### Caso 1: Plugin con `activate.php`

**Prima della migrazione:**

```php
// activate.php (DA ELIMINARE)
$app->get('/api/mio-plugin/test', function ($request, $response) {
    // Route logic
});
```

**Dopo la migrazione:**

1. **Elimina** `activate.php`
2. **Aggiungi** l'hook in `MioPlugin.php`:

```php
public function registerHooks($hookManager): void
{
    $hooks = [
        // Hook esistenti...

        // AGGIUNGI QUESTO:
        ['app.routes.register', 'registerRoutes', 10],
    ];

    foreach ($hooks as [$hookName, $method, $priority]) {
        $hookManager->addAction($hookName, [$this, $method], $priority);
    }
}
```

3. **Crea** il metodo `registerRoutes()`:

```php
public function registerRoutes($app): void
{
    // Sposta qui il codice da activate.php
    $app->get('/api/mio-plugin/test', function ($request, $response) {
        // Route logic
    });
}
```

### Caso 2: Plugin senza Route Custom

Se il plugin **non ha route personalizzate** (usa solo altri hook), **non serve fare nulla**.

Esempio: Plugin che aggiunge solo hook per scraping o modifica dati.

---

## Esempi Completi

### Esempio 1: Plugin API RESTful

```php
public function registerRoutes($app): void
{
    $basePath = '/api/my-resource';

    // GET /api/my-resource - Lista risorse
    $app->get($basePath, function ($request, $response) {
        $items = $this->getAllItems();
        $response->getBody()->write(json_encode($items));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // GET /api/my-resource/{id} - Singola risorsa
    $app->get($basePath . '/{id}', function ($request, $response, $args) {
        $id = (int)$args['id'];
        $item = $this->getItemById($id);

        if (!$item) {
            return $response->withStatus(404);
        }

        $response->getBody()->write(json_encode($item));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // POST /api/my-resource - Crea risorsa
    $app->post($basePath, function ($request, $response) {
        $data = $request->getParsedBody();
        $newItem = $this->createItem($data);

        $response->getBody()->write(json_encode($newItem));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(201);
    });

    // PUT /api/my-resource/{id} - Aggiorna risorsa
    $app->put($basePath . '/{id}', function ($request, $response, $args) {
        $id = (int)$args['id'];
        $data = $request->getParsedBody();
        $updated = $this->updateItem($id, $data);

        $response->getBody()->write(json_encode($updated));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // DELETE /api/my-resource/{id} - Elimina risorsa
    $app->delete($basePath . '/{id}', function ($request, $response, $args) {
        $id = (int)$args['id'];
        $this->deleteItem($id);

        return $response->withStatus(204);
    });
}
```

### Esempio 2: Plugin con Rate Limiting

```php
public function registerRoutes($app): void
{
    $app->get('/api/external-service', function ($request, $response) {
        // Check rate limit
        if (!$this->checkRateLimit()) {
            $response->getBody()->write(json_encode([
                'error' => 'Rate limit exceeded',
                'retry_after' => 3600
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(429);
        }

        // Process request
        $result = $this->callExternalService();

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    });
}

private function checkRateLimit(): bool
{
    // Implementazione rate limiting
    // Usando session, cache, o database
    return true;
}
```

### Esempio 3: Plugin con Middleware Custom

```php
public function registerRoutes($app): void
{
    // Route protetta con autenticazione
    $app->get('/api/protected', function ($request, $response) use ($app) {
        // Verifica autenticazione
        $auth = $request->getHeader('Authorization');

        if (empty($auth) || !$this->validateToken($auth[0])) {
            $response->getBody()->write(json_encode([
                'error' => 'Unauthorized'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }

        // Dati protetti
        $data = $this->getSecureData();
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    });
}
```

---

## Troubleshooting

### Route non funzionano dopo attivazione plugin

**Problema**: Le route del plugin non sono accessibili.

**Soluzione**:

1. Verifica che l'hook sia registrato:
```sql
SELECT h.*, p.name, p.is_active
FROM plugin_hooks h
JOIN plugins p ON h.plugin_id = p.id
WHERE h.hook_name = 'app.routes.register'
AND p.is_active = 1;
```

2. Verifica che il plugin sia attivo:
```sql
SELECT * FROM plugins WHERE name = 'mio-plugin';
```

3. Controlla i log per errori:
```bash
tail -f storage/logs/app.log
```

### Errore "Call to undefined method"

**Problema**: `Fatal error: Call to undefined method MioPlugin::registerRoutes()`

**Soluzione**: Assicurati che il metodo `registerRoutes()` sia definito nella classe:

```php
public function registerRoutes($app): void
{
    // Il metodo deve esistere anche se vuoto
}
```

### Route duplicata

**Problema**: `FastRoute\BadRouteException: Cannot register two routes matching "/api/test"`

**Causa**: Due plugin registrano la stessa route.

**Soluzione**: Usa prefissi univoci per ogni plugin:
- Plugin 1: `/api/plugin1/...`
- Plugin 2: `/api/plugin2/...`

### Hook non viene chiamato

**Problema**: Il metodo `registerRoutes()` non viene mai eseguito.

**Verifica**:

1. Controlla che `web.php` abbia il codice dell'hook:
```php
$hookManager->doAction('app.routes.register', [$app]);
```

2. Aggiungi debug temporaneo:
```php
public function registerRoutes($app): void
{
    error_log('[DEBUG] registerRoutes called for mio-plugin');
    // ... resto del codice
}
```

3. Riavvia il server web:
```bash
service apache2 restart
# oppure
service nginx restart
```

### Route funziona in locale ma non in produzione

**Problema**: Route accessibile in development ma 404 in produzione.

**Soluzione**:

1. Verifica `.htaccess` (Apache) o configurazione Nginx
2. Controlla permessi file plugin
3. Verifica che il plugin sia attivato anche in produzione
4. Controlla cache route (se implementata)

---

## Best Practices

### 1. Naming Convention

**Route Pattern**: `/api/{plugin-name}/{risorsa}/{azione}`

Esempi:
- ✅ `/api/z39-server/sru`
- ✅ `/api/open-library/test`
- ✅ `/api/my-plugin/users/search`
- ❌ `/test` (troppo generico)
- ❌ `/api/search` (manca plugin name)

### 2. Sicurezza

```php
public function registerRoutes($app): void
{
    $app->post('/api/mio-plugin/action', function ($request, $response) {
        // 1. Validazione Input
        $data = $request->getParsedBody();
        $sanitized = filter_var($data['param'], FILTER_SANITIZE_STRING);

        // 2. Autenticazione (se necessaria)
        if (!$this->isAuthenticated($request)) {
            return $response->withStatus(401);
        }

        // 3. Rate Limiting
        if (!$this->checkRateLimit()) {
            return $response->withStatus(429);
        }

        // 4. Autorizzazione
        if (!$this->userCanAccess($resource)) {
            return $response->withStatus(403);
        }

        // 5. Prepared Statements per DB
        $stmt = $this->db->prepare('SELECT * FROM table WHERE id = ?');
        $stmt->execute([$sanitized]);

        // ... resto logica
    });
}
```

### 3. Error Handling

```php
public function registerRoutes($app): void
{
    $app->get('/api/mio-plugin/data', function ($request, $response) {
        try {
            $data = $this->getData();

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $data
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            // Log errore
            error_log('[MioPlugin] Error: ' . $e->getMessage());

            // Response generica (non esporre dettagli interni)
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    });
}
```

### 4. Documentazione Route

Documenta sempre le route nel `README.md` del plugin:

```markdown
## Endpoints API

### GET /api/mio-plugin/test
Test endpoint per verificare il plugin.

**Response**:
```json
{
  "success": true,
  "message": "Plugin funzionante!"
}
```

### POST /api/mio-plugin/action
Esegue un'azione sul plugin.

**Request Body**:
```json
{
  "param": "valore"
}
```

**Response**:
```json
{
  "success": true,
  "data": {...}
}
```
```

---

## Conclusione

Il sistema di auto-registrazione route semplifica drasticamente lo sviluppo e manutenzione dei plugin per Pinakes:

- ✅ Installazione zero-friction
- ✅ Code isolato e manutenibile
- ✅ Scalabilità garantita
- ✅ Compatibilità futura

Per domande o supporto, consulta la documentazione dei plugin esistenti (`z39-server`, `open-library`) come riferimento.
