<?php
declare(strict_types=1);

use App\Controllers\CollaneController;
use App\Controllers\AuthController;
use App\Controllers\LibraryThingImportController;
use App\Controllers\LibriApiController;
use App\Controllers\ProfileController;
use App\Controllers\PublicApiController;
use App\Middleware\RememberMeMiddleware;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function loadEnvFile(string $path): void
{
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function getDb(): mysqli
{
    $cfg = require __DIR__ . '/../../config/settings.php';
    $dbCfg = $cfg['db'];

    $db = new mysqli(
        $dbCfg['hostname'],
        $dbCfg['username'],
        $dbCfg['password'],
        $dbCfg['database'],
        $dbCfg['port'],
        $dbCfg['socket'] ?: null
    );
    $db->set_charset('utf8mb4');

    return $db;
}

function randomRunId(): string
{
    return 'codex_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
}

function deleteBook(mysqli $db, int $bookId): void
{
    $db->query('DELETE FROM copie WHERE libro_id = ' . $bookId);
    $db->query('DELETE FROM libri WHERE id = ' . $bookId);
}

function deleteUser(mysqli $db, int $userId): void
{
    $db->query('DELETE FROM user_sessions WHERE utente_id = ' . $userId);
    $db->query('DELETE FROM utenti WHERE id = ' . $userId);
}

function createTestUser(mysqli $db, string $locale = 'en_US'): array
{
    $runId = randomRunId();
    $email = $runId . '@test.local';
    $password = 'Test1234!Aa';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $card = 'CDX' . substr(buildDigitString($runId, 12), 0, 12);

    $stmt = $db->prepare(
        "INSERT INTO utenti (
            codice_tessera, nome, cognome, email, password,
            privacy_accettata, data_accettazione_privacy,
            tipo_utente, stato, email_verificata, locale
        ) VALUES (?, 'Codex', 'Tester', ?, ?, 1, NOW(), 'admin', 'attivo', 1, ?)"
    );
    $stmt->bind_param('ssss', $card, $email, $hash, $locale);
    $stmt->execute();
    $userId = (int) $db->insert_id;
    $stmt->close();

    return [
        'id' => $userId,
        'email' => $email,
        'password' => $password,
        'locale' => $locale,
    ];
}

function fetchUrl(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('HTTP fetch failed: ' . $error);
        }
        return $body;
    }

    $body = @file_get_contents($url);
    if ($body === false) {
        throw new RuntimeException('HTTP fetch failed: ' . $url);
    }

    return $body;
}

function buildDigitString(string $seed, int $length): string
{
    $digits = '';
    $material = hash('sha256', $seed);

    while (strlen($digits) < $length) {
        for ($i = 0, $max = strlen($material); $i < $max && strlen($digits) < $length; $i++) {
            $digits .= (string) (hexdec($material[$i]) % 10);
        }
        $material = hash('sha256', $material . $seed . strlen($digits));
    }

    return substr($digits, 0, $length);
}

/**
 * @return array{0: string, 1: string}
 */
function buildUniqueIsbns(string $seed): array
{
    $digits = buildDigitString($seed, 20);
    return [
        '979' . substr($digits, 0, 10),
        strrev(substr($digits, 10, 10)),
    ];
}

function extractBookSchema(string $html): ?array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    foreach ($dom->getElementsByTagName('script') as $script) {
        if ($script->getAttribute('type') !== 'application/ld+json') {
            continue;
        }

        $decoded = json_decode($script->textContent, true);
        if (!is_array($decoded)) {
            continue;
        }

        $candidates = array_is_list($decoded) ? $decoded : [$decoded];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && ($candidate['@type'] ?? null) === 'Book') {
                return $candidate;
            }
        }
    }

    return null;
}

function scenarioCollanaRenameRollback(mysqli $db): array
{
    $_SESSION = [];
    $factory = new ServerRequestFactory();
    $controller = new CollaneController();
    $runId = randomRunId();

    $source = 'E2E_SRC_' . $runId;
    $target = 'E2E_DST_' . $runId;
    $bookTitle = 'E2E Collana Rename ' . $runId;
    $bookId = 0;

    try {
        $stmt = $db->prepare('INSERT INTO collane (nome, descrizione) VALUES (?, ?)');
        $desc = 'source';
        $stmt->bind_param('ss', $source, $desc);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO collane (nome, descrizione) VALUES (?, ?)');
        $desc = 'target';
        $stmt->bind_param('ss', $target, $desc);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare(
            "INSERT INTO libri (titolo, collana, copie_totali, copie_disponibili, created_at, updated_at)
             VALUES (?, ?, 1, 1, NOW(), NOW())"
        );
        $stmt->bind_param('ss', $bookTitle, $source);
        $stmt->execute();
        $bookId = (int) $db->insert_id;
        $stmt->close();

        $request = $factory
            ->createServerRequest('POST', '/admin/collane/rinomina')
            ->withParsedBody([
                'old_name' => $source,
                'new_name' => $target,
            ]);

        $response = $controller->rename($request, new Response(), $db);

        $bookCollana = $db->query('SELECT collana FROM libri WHERE id = ' . $bookId)->fetch_assoc()['collana'] ?? null;
        $sourceExists = (int) ($db->query(
            "SELECT COUNT(*) AS c FROM collane WHERE nome = '" . $db->real_escape_string($source) . "'"
        )->fetch_assoc()['c'] ?? 0);
        $targetExists = (int) ($db->query(
            "SELECT COUNT(*) AS c FROM collane WHERE nome = '" . $db->real_escape_string($target) . "'"
        )->fetch_assoc()['c'] ?? 0);

        return [
            'ok' => $response->getStatusCode() === 302
                && $bookCollana === $source
                && $sourceExists === 1
                && $targetExists === 1,
            'statusCode' => $response->getStatusCode(),
            'bookCollana' => $bookCollana,
            'source' => $source,
            'target' => $target,
            'sourceExists' => $sourceExists,
            'targetExists' => $targetExists,
            'sessionError' => $_SESSION['error_message'] ?? null,
        ];
    } finally {
        if ($bookId > 0) {
            deleteBook($db, $bookId);
        }
        $db->query("DELETE FROM collane WHERE nome = '" . $db->real_escape_string($source) . "'");
        $db->query("DELETE FROM collane WHERE nome = '" . $db->real_escape_string($target) . "'");
    }
}

function getLibraryThingMethods(): array
{
    $controller = new LibraryThingImportController();
    $parse = new ReflectionMethod($controller, 'parseLibraryThingRow');
    $parse->setAccessible(true);
    $insert = new ReflectionMethod($controller, 'insertBook');
    $insert->setAccessible(true);
    $update = new ReflectionMethod($controller, 'updateBook');
    $update->setAccessible(true);

    return [$controller, $parse, $insert, $update];
}

function buildLibraryThingRow(string $runId): array
{
    [$isbn13, $isbn10] = buildUniqueIsbns($runId);

    return [
        'Book Id' => 'LT-' . $runId,
        'Title' => 'LT Branch ' . $runId,
        'Primary Author' => 'Orwell, George',
        'Secondary Author' => 'Pym, Barbara',
        'Secondary Author Roles' => 'Translator',
        'Publication' => 'Milano, Editore Branch, 2024',
        'Date' => '2024-05-01',
        'ISBNs' => $isbn13 . ', ' . $isbn10,
        'Languages' => 'English, German',
        'Page Count' => '321 pp.',
        'Summary' => '<p>Alpha &amp; <strong>Omega</strong></p>',
        'Media' => 'Paperback',
        'Subjects' => 'Romanzo, Test',
        'Tags' => 'tag1,tag2',
        'Series' => 'Branch Saga ; 7',
        'Copies' => '2',
        'ISSN' => '1234567x',
    ];
}

function scenarioLibraryThingParseAndPersist(mysqli $db): array
{
    // Ensure LibraryThing plugin schema is installed so LT-specific columns exist
    if (!\App\Support\LibraryThingInstaller::isInstalled($db)) {
        $installer = new \App\Support\LibraryThingInstaller($db);
        $installer->install();
    }

    [$controller, $parse, $insert, $update] = getLibraryThingMethods();
    $runId = randomRunId();
    $bookId = 0;
    $softDeletedId = 0;

    try {
        $parsed = $parse->invoke($controller, buildLibraryThingRow($runId));

        $bookId = (int) $insert->invoke($controller, $db, $parsed, null, null);
        $created = $db->query(
            'SELECT titolo, descrizione_plain, collana, numero_serie, traduttore, issn, copie_totali, copie_disponibili
             FROM libri WHERE id = ' . $bookId
        )->fetch_assoc();

        $parsedUpdate = $parsed;
        $parsedUpdate['titolo'] = 'LT Branch Updated ' . $runId;
        $parsedUpdate['descrizione'] = '<div>Delta &amp; Echo</div>';
        $parsedUpdate['descrizione_plain'] = 'Delta & Echo';
        $parsedUpdate['collana'] = 'Branch Saga Updated';
        $parsedUpdate['numero_serie'] = '8';
        $update->invoke($controller, $db, $bookId, $parsedUpdate, null, null);

        $updated = $db->query(
            'SELECT titolo, descrizione_plain, collana, numero_serie FROM libri WHERE id = ' . $bookId
        )->fetch_assoc();

        $stmt = $db->prepare(
            "INSERT INTO libri (titolo, descrizione_plain, deleted_at, copie_totali, copie_disponibili, created_at, updated_at)
             VALUES (?, 'before soft delete', NOW(), 1, 1, NOW(), NOW())"
        );
        $softTitle = 'LT Soft Deleted ' . $runId;
        $stmt->bind_param('s', $softTitle);
        $stmt->execute();
        $softDeletedId = (int) $db->insert_id;
        $stmt->close();

        $softUpdate = $parsed;
        $softUpdate['titolo'] = 'SHOULD NOT UPDATE';
        $softUpdate['descrizione_plain'] = 'after';
        $update->invoke($controller, $db, $softDeletedId, $softUpdate, null, null);
        $softDeleted = $db->query(
            'SELECT titolo, descrizione_plain FROM libri WHERE id = ' . $softDeletedId
        )->fetch_assoc();

        return [
            'ok' =>
                ($parsed['autori'] ?? null) === 'George Orwell'
                && ($parsed['traduttore'] ?? null) === 'Barbara Pym'
                && ($parsed['descrizione_plain'] ?? null) === 'Alpha & Omega'
                && ($parsed['collana'] ?? null) === 'Branch Saga'
                && ($parsed['numero_serie'] ?? null) === '7'
                && ($parsed['issn'] ?? null) === '1234-567X'
                && ($created['descrizione_plain'] ?? null) === 'Alpha & Omega'
                && ($created['collana'] ?? null) === 'Branch Saga'
                && ($created['traduttore'] ?? null) === 'Barbara Pym'
                && ($created['issn'] ?? null) === '1234-567X'
                && ($updated['titolo'] ?? null) === 'LT Branch Updated ' . $runId
                && ($updated['descrizione_plain'] ?? null) === 'Delta & Echo'
                && ($updated['collana'] ?? null) === 'Branch Saga Updated'
                && ($updated['numero_serie'] ?? null) === '8'
                && ($softDeleted['titolo'] ?? null) === $softTitle
                && ($softDeleted['descrizione_plain'] ?? null) === 'before soft delete',
            'parsed' => [
                'autori' => $parsed['autori'] ?? null,
                'traduttore' => $parsed['traduttore'] ?? null,
                'descrizione_plain' => $parsed['descrizione_plain'] ?? null,
                'collana' => $parsed['collana'] ?? null,
                'numero_serie' => $parsed['numero_serie'] ?? null,
                'issn' => $parsed['issn'] ?? null,
            ],
            'created' => $created,
            'updated' => $updated,
            'softDeleted' => $softDeleted,
        ];
    } finally {
        if ($softDeletedId > 0) {
            deleteBook($db, $softDeletedId);
        }
        if ($bookId > 0) {
            deleteBook($db, $bookId);
        }
    }
}

function scenarioDescrizionePlainSearch(mysqli $db): array
{
    $_SESSION = [];
    $factory = new ServerRequestFactory();
    $controller = new LibriApiController();
    $runId = randomRunId();
    $title = 'DescPlain ' . $runId;
    $bookId = 0;
    $baseUrl = rtrim(getenv('BASE_URL') ?: 'http://localhost:8081', '/');

    try {
        $stmt = $db->prepare(
            "INSERT INTO libri (titolo, descrizione, descrizione_plain, copie_totali, copie_disponibili, created_at, updated_at)
             VALUES (?, '<p>Markup only</p>', 'NeedleToken search branch', 1, 1, NOW(), NOW())"
        );
        $stmt->bind_param('s', $title);
        $stmt->execute();
        $bookId = (int) $db->insert_id;
        $stmt->close();

        $request = $factory
            ->createServerRequest('GET', '/api/libri')
            ->withQueryParams([
                'draw' => '1',
                'start' => '0',
                'length' => '10',
                'search_text' => 'NeedleToken',
            ]);

        $response = $controller->list($request, new Response(), $db);
        $payload = json_decode((string) $response->getBody(), true);
        $apiTitles = array_map(
            static fn(array $row): string => (string) ($row['titolo'] ?? ''),
            $payload['data'] ?? []
        );

        $catalogHtml = fetchUrl($baseUrl . '/catalogo?q=NeedleToken');

        return [
            'ok' => in_array($title, $apiTitles, true) && str_contains($catalogHtml, $title),
            'title' => $title,
            'apiTitles' => $apiTitles,
            'catalogFound' => str_contains($catalogHtml, $title),
        ];
    } finally {
        if ($bookId > 0) {
            deleteBook($db, $bookId);
        }
    }
}

function scenarioPublicApiAndFrontendIssn(mysqli $db): array
{
    $_SESSION = [];
    $factory = new ServerRequestFactory();
    $publicApi = new PublicApiController();
    [$controller, $parse, $insert] = getLibraryThingMethods();
    $runId = randomRunId();
    $bookId = 0;
    $baseUrl = rtrim(getenv('BASE_URL') ?: 'http://localhost:8081', '/');

    try {
        $parsed = $parse->invoke($controller, buildLibraryThingRow($runId));
        $bookId = (int) $insert->invoke($controller, $db, $parsed, null, null);

        $request = $factory
            ->createServerRequest('GET', '/api/public/books/search')
            ->withQueryParams(['isbn13' => $parsed['isbn13']])
            ->withAttribute('api_key_data', ['name' => 'Codex Test']);

        $response = $publicApi->searchBooks($request, new Response(), $db);
        $payload = json_decode((string) $response->getBody(), true);
        $apiIssn = $payload['results'][0]['issn'] ?? null;

        $detailHtml = fetchUrl($baseUrl . '/libro/' . $bookId);
        $bookSchema = extractBookSchema($detailHtml);
        $identifier = is_array($bookSchema) ? ($bookSchema['identifier'] ?? null) : null;
        $schemaHasIdentifier = is_array($identifier)
            && ($identifier['@type'] ?? null) === 'PropertyValue'
            && ($identifier['propertyID'] ?? null) === 'ISSN'
            && ($identifier['value'] ?? null) === '1234-567X';

        return [
            'ok' => $apiIssn === '1234-567X'
                && str_contains($detailHtml, '1234-567X')
                && $schemaHasIdentifier,
            'bookId' => $bookId,
            'apiIssn' => $apiIssn,
            'detailHasIssn' => str_contains($detailHtml, '1234-567X'),
            'detailHasSchemaIdentifier' => $schemaHasIdentifier,
        ];
    } finally {
        if ($bookId > 0) {
            deleteBook($db, $bookId);
        }
    }
}

function scenarioLibraryThingExportTranslatorRoundtrip(mysqli $db): array
{
    [$controller, $parse] = getLibraryThingMethods();
    $format = new ReflectionMethod($controller, 'formatLibraryThingRow');
    $format->setAccessible(true);
    $headersMethod = new ReflectionMethod($controller, 'getLibraryThingHeaders');
    $headersMethod->setAccessible(true);

    $row = $format->invoke($controller, [
        'titolo' => 'Roundtrip Test',
        'autori_nomi' => 'George Orwell',
        'traduttore' => 'Barbara Pym',
        'editore_nome' => 'Editore Branch',
        'anno_pubblicazione' => 2024,
        'lingua' => 'English',
        'formato' => 'cartaceo',
    ]);
    $headers = $headersMethod->invoke($controller);
    $mappedRow = array_combine($headers, $row);
    $parsed = $parse->invoke($controller, $mappedRow);

    return [
        'ok' =>
            ($mappedRow['Secondary Author'] ?? null) === 'Barbara Pym'
            && ($mappedRow['Secondary Author Roles'] ?? null) === 'Translator'
            && ($parsed['autori'] ?? null) === 'George Orwell'
            && ($parsed['traduttore'] ?? null) === 'Barbara Pym',
        'row' => [
            'secondaryAuthor' => $mappedRow['Secondary Author'] ?? null,
            'secondaryAuthorRoles' => $mappedRow['Secondary Author Roles'] ?? null,
        ],
        'parsed' => [
            'autori' => $parsed['autori'] ?? null,
            'traduttore' => $parsed['traduttore'] ?? null,
        ],
    ];
}

function scenarioLibraryThingSecondaryAuthorRolePairing(mysqli $db): array
{
    [$controller, $parse] = getLibraryThingMethods();
    $format = new ReflectionMethod($controller, 'formatLibraryThingRow');
    $format->setAccessible(true);
    $headersMethod = new ReflectionMethod($controller, 'getLibraryThingHeaders');
    $headersMethod->setAccessible(true);

    $row = $format->invoke($controller, [
        'titolo' => 'Roundtrip Pairing Test',
        'autori_nomi' => 'George Orwell; Jane Austen',
        'traduttore' => 'Barbara Pym',
        'editore_nome' => 'Editore Branch',
        'anno_pubblicazione' => 2024,
        'lingua' => 'English',
        'formato' => 'cartaceo',
    ]);
    $headers = $headersMethod->invoke($controller);
    $mappedRow = array_combine($headers, $row);
    $parsed = $parse->invoke($controller, $mappedRow);

    return [
        'ok' =>
            ($mappedRow['Secondary Author'] ?? null) === 'Jane Austen; Barbara Pym'
            && ($mappedRow['Secondary Author Roles'] ?? null) === '; Translator'
            && ($parsed['autori'] ?? null) === 'George Orwell|Jane Austen'
            && ($parsed['traduttore'] ?? null) === 'Barbara Pym',
        'row' => [
            'secondaryAuthor' => $mappedRow['Secondary Author'] ?? null,
            'secondaryAuthorRoles' => $mappedRow['Secondary Author Roles'] ?? null,
        ],
        'parsed' => [
            'autori' => $parsed['autori'] ?? null,
            'traduttore' => $parsed['traduttore'] ?? null,
        ],
    ];
}

function scenarioAuthLoginLoadsLocale(mysqli $db): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_id('codex-auth-' . substr(bin2hex(random_bytes(6)), 0, 12));
        session_start();
    }
    $_SESSION = [];
    \App\Support\I18n::bootstrap($db);
    \App\Support\I18n::setLocale('it_IT');

    $factory = new ServerRequestFactory();
    $controller = new AuthController();
    $user = createTestUser($db, 'en_US');

    try {
        $request = $factory
            ->createServerRequest('POST', '/accedi')
            ->withParsedBody([
                'email' => $user['email'],
                'password' => $user['password'],
            ]);

        $response = $controller->login($request, new Response(), $db);

        return [
            'ok' =>
                $response->getStatusCode() === 302
                && $response->getHeaderLine('Location') === '/admin/dashboard'
                && ($_SESSION['locale'] ?? null) === 'en_US'
                && \App\Support\I18n::getLocale() === 'en_US',
            'statusCode' => $response->getStatusCode(),
            'location' => $response->getHeaderLine('Location'),
            'sessionLocale' => $_SESSION['locale'] ?? null,
            'currentLocale' => \App\Support\I18n::getLocale(),
        ];
    } finally {
        deleteUser($db, $user['id']);
    }
}

function scenarioProfileLocaleUpdate(mysqli $db): array
{
    $_SESSION = [];
    \App\Support\I18n::bootstrap($db);
    \App\Support\I18n::setLocale('en_US');

    $factory = new ServerRequestFactory();
    $controller = new ProfileController();
    $user = createTestUser($db, 'en_US');
    $_SESSION['user'] = [
        'id' => $user['id'],
        'email' => $user['email'],
        'tipo_utente' => 'admin',
        'name' => 'Codex Tester',
    ];
    $_SESSION['locale'] = 'en_US';

    try {
        $request = $factory
            ->createServerRequest('POST', '/profilo/aggiorna')
            ->withParsedBody([
                'nome' => 'Codex',
                'cognome' => 'Tester',
                'telefono' => '',
                'data_nascita' => '',
                'cod_fiscale' => '',
                'sesso' => '',
                'indirizzo' => '',
                'locale' => 'it_IT',
            ]);

        $response = $controller->update($request, new Response(), $db);
        $dbLocale = $db->query('SELECT locale FROM utenti WHERE id = ' . $user['id'])->fetch_assoc()['locale'] ?? null;

        return [
            'ok' =>
                $response->getStatusCode() === 302
                && $dbLocale === 'it_IT'
                && ($_SESSION['locale'] ?? null) === 'it_IT'
                && \App\Support\I18n::getLocale() === 'it_IT',
            'statusCode' => $response->getStatusCode(),
            'dbLocale' => $dbLocale,
            'sessionLocale' => $_SESSION['locale'] ?? null,
            'currentLocale' => \App\Support\I18n::getLocale(),
            'successMessage' => $_SESSION['success_message'] ?? null,
        ];
    } finally {
        deleteUser($db, $user['id']);
    }
}

function scenarioProfileLocaleOmittedKeepsValue(mysqli $db): array
{
    $_SESSION = [];
    \App\Support\I18n::bootstrap($db);
    \App\Support\I18n::setLocale('en_US');

    $factory = new ServerRequestFactory();
    $controller = new ProfileController();
    $user = createTestUser($db, 'en_US');
    $_SESSION['user'] = [
        'id' => $user['id'],
        'email' => $user['email'],
        'tipo_utente' => 'admin',
        'name' => 'Codex Tester',
    ];
    $_SESSION['locale'] = 'en_US';

    try {
        $request = $factory
            ->createServerRequest('POST', '/profilo/aggiorna')
            ->withParsedBody([
                'nome' => 'Codex',
                'cognome' => 'Tester',
                'telefono' => '',
                'data_nascita' => '',
                'cod_fiscale' => '',
                'sesso' => '',
                'indirizzo' => '',
            ]);

        $response = $controller->update($request, new Response(), $db);
        $dbLocale = $db->query('SELECT locale FROM utenti WHERE id = ' . $user['id'])->fetch_assoc()['locale'] ?? null;

        return [
            'ok' =>
                $response->getStatusCode() === 302
                && $dbLocale === 'en_US'
                && ($_SESSION['locale'] ?? null) === 'en_US'
                && \App\Support\I18n::getLocale() === 'en_US',
            'statusCode' => $response->getStatusCode(),
            'dbLocale' => $dbLocale,
            'sessionLocale' => $_SESSION['locale'] ?? null,
            'currentLocale' => \App\Support\I18n::getLocale(),
        ];
    } finally {
        deleteUser($db, $user['id']);
    }
}

function scenarioRememberMeLoadsLocale(mysqli $db): array
{
    $_SESSION = [];
    $_COOKIE = [];
    \App\Support\I18n::bootstrap($db);
    \App\Support\I18n::setLocale('it_IT');

    $factory = new ServerRequestFactory();
    $middleware = new RememberMeMiddleware($db);
    $user = createTestUser($db, 'de_DE');
    $token = bin2hex(random_bytes(64));
    $tokenHash = hash('sha256', $token);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));

    $stmt = $db->prepare(
        "INSERT INTO user_sessions (utente_id, token_hash, device_info, ip_address, user_agent, expires_at)
         VALUES (?, ?, 'Codex Browser', '127.0.0.1', 'Codex Test Agent', ?)"
    );
    $stmt->bind_param('iss', $user['id'], $tokenHash, $expiresAt);
    $stmt->execute();
    $stmt->close();

    $_COOKIE['remember_token'] = $token;

    try {
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new Response();
            }
        };

        $response = $middleware->process($factory->createServerRequest('GET', '/'), $handler);

        return [
            'ok' =>
                $response->getStatusCode() === 200
                && ($_SESSION['locale'] ?? null) === 'de_DE'
                && \App\Support\I18n::getLocale() === 'de_DE'
                && (int) ($_SESSION['user']['id'] ?? 0) === $user['id'],
            'statusCode' => $response->getStatusCode(),
            'sessionLocale' => $_SESSION['locale'] ?? null,
            'currentLocale' => \App\Support\I18n::getLocale(),
            'userId' => $_SESSION['user']['id'] ?? null,
        ];
    } finally {
        deleteUser($db, $user['id']);
    }
}

loadEnvFile(__DIR__ . '/../../.env');
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/helpers.php';

$scenario = $argv[1] ?? '';
$db = getDb();

try {
    $result = match ($scenario) {
        'collana-rename-rollback' => scenarioCollanaRenameRollback($db),
        'librarything-parse-and-persist' => scenarioLibraryThingParseAndPersist($db),
        'librarything-export-translator-roundtrip' => scenarioLibraryThingExportTranslatorRoundtrip($db),
        'librarything-secondary-author-role-pairing' => scenarioLibraryThingSecondaryAuthorRolePairing($db),
        'descrizione-plain-search' => scenarioDescrizionePlainSearch($db),
        'public-api-and-frontend-issn' => scenarioPublicApiAndFrontendIssn($db),
        'auth-login-loads-locale' => scenarioAuthLoginLoadsLocale($db),
        'profile-locale-update' => scenarioProfileLocaleUpdate($db),
        'profile-locale-omitted-keeps-value' => scenarioProfileLocaleOmittedKeepsValue($db),
        'remember-me-loads-locale' => scenarioRememberMeLoadsLocale($db),
        default => [
            'ok' => false,
            'error' => 'Unknown scenario: ' . $scenario,
        ],
    };

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'type' => $e::class,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(1);
}
