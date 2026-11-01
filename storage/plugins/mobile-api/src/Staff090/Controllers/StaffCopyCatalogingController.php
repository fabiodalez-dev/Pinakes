<?php

declare(strict_types=1);

namespace App\Plugins\MobileApi\Staff090\Controllers;

use App\Plugins\MobileApi\Staff090\Support\CopyCatalogingMapper;
use App\Plugins\MobileApi\Support\AppAuthMiddleware;
use App\Plugins\MobileApi\Support\CirculationOutcome;
use App\Plugins\MobileApi\Support\Delegation;
use App\Plugins\MobileApi\Support\ImportJobStore;
use App\Plugins\MobileApi\Support\JsonBody;
use App\Plugins\MobileApi\Support\ResponseEnvelope;
use App\Plugins\MobileApi\Support\StaffIdempotency;
use App\Plugins\MobileApi\Support\StaffPaging;
use App\Support\SecureLogger;
use mysqli;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response as SlimResponse;

/**
 * Copy cataloguing (Pinakes 0.9.0, module `marc`, z39-server plugin): search
 * other catalogues over SRU / SBN and import the chosen records.
 *
 * Search and import are the plugin's own actions (copyCatalogingSearchAction,
 * copyCatalogingImportAction), run in-process. The web keeps the records of
 * the last search in the session, so the import works on what the catalogue
 * sent rather than on what the client sends back; here every search becomes
 * a job of kind `copy_cataloging` in ImportJobStore (24 h, the latest
 * KEEP_SEARCHES per operator) and the import names it by `search_id`.
 */
final class StaffCopyCatalogingController
{
    public const KIND = 'copy_cataloging';
    public const INDEXES = ['title', 'author', 'isbn', 'issn', 'any'];
    private const RESULTS_KEY = 'copy_cataloging_results';
    private const LAST_IMPORT_KEY = 'copy_cataloging_last_import';
    private const KEEP_SEARCHES = 5;
    private const MAX_IDS = 100;

    private ImportJobStore $store;

    public function __construct(private mysqli $db)
    {
        $this->store = new ImportJobStore($db);
    }

    /**
     * Whether copy cataloguing can run here: the z39-server plugin is active
     * (the module itself is checked by the route).
     *
     * @return array{available: bool, reason: ?string}
     */
    public static function availability(mysqli $db): array
    {
        return self::pluginActive($db)
            ? ['available' => true, 'reason' => null]
            : ['available' => false, 'reason' => 'plugin_inactive'];
    }

    public function servers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $plugin = $this->plugin();
            if ($plugin === null) {
                return $this->unavailable($response);
            }
            $servers = [];
            foreach ($plugin->copyCataloging()->servers() as $key => $server) {
                $servers[] = CopyCatalogingMapper::server((string) $key, $server);
            }

            return ResponseEnvelope::success($response, [
                'servers' => $servers,
                'indexes' => [
                    ['value' => 'title', 'label' => __('Titolo')],
                    ['value' => 'author', 'label' => __('Autore')],
                    ['value' => 'isbn', 'label' => __('ISBN')],
                    ['value' => 'issn', 'label' => __('ISSN')],
                    ['value' => 'any', 'label' => __('Tutti i campi')],
                ],
                'duplicate_modes' => StaffMarcController::duplicateModes(),
                'max_results_per_server' => 25,
                'max_import_ids' => self::MAX_IDS,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($response, 'copy cataloging servers', $e);
        }
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        try {
            $plugin = $this->plugin();
            if ($plugin === null) {
                return $this->unavailable($response);
            }
            $known = array_map('strval', array_keys($plugin->copyCataloging()->servers()));
            $errors = [];
            $term = is_string($json['term'] ?? null) ? trim($json['term']) : '';
            if ($term === '') {
                $errors['term'] = __('Indica cosa cercare e almeno un catalogo');
            } elseif (mb_strlen($term) > 200) {
                $errors['term'] = sprintf(__('Valore troppo lungo (massimo %d caratteri).'), 200);
            }
            $index = $json['index'] ?? 'any';
            if (!is_string($index) || !in_array($index, self::INDEXES, true)) {
                $errors['index'] = __('Valore non valido.');
            }
            $servers = $json['servers'] ?? null;
            if (!is_array($servers) || !array_is_list($servers) || $servers === []) {
                $errors['servers'] = __('Indica cosa cercare e almeno un catalogo');
            } else {
                foreach ($servers as $key) {
                    if (!is_string($key) || !in_array($key, $known, true)) {
                        $errors['servers'] = __('Valore non valido.');
                        break;
                    }
                }
            }
            $max = $json['max'] ?? 10;
            if (!is_int($max) || $max < 1 || $max > 25) {
                $errors['max'] = __('Valore non valido.');
            }
            if ($errors !== []) {
                return $this->invalid($response, $errors);
            }
            /** @var list<string> $servers */
            $servers = array_values(array_unique($servers));
            $payload = ['servers' => $servers, 'index' => (string) $index, 'term' => $term, 'max' => (int) $max];
            $outcome = Delegation::run(static fn (): ResponseInterface => $plugin->copyCatalogingSearchAction(
                Delegation::request('POST', '/admin/books/copy-cataloging/search', [], [], (string) json_encode($payload)),
                new SlimResponse()
            ));
            if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                throw new \RuntimeException('copy cataloging search answered HTTP ' . $outcome->status);
            }
            $stored = $outcome->session[self::RESULTS_KEY] ?? [];
            $userId = (int) (StaffPaging::user($request)['id'] ?? 0);
            $tokenId = $request->getAttribute(AppAuthMiddleware::ATTR_TOKEN_ID);
            $this->store->purgeExpired();
            $id = $this->store->create(
                $userId,
                is_int($tokenId) ? $tokenId : null,
                self::KIND,
                mb_substr($index . ': ' . $term, 0, 255),
                is_array($stored) ? count($stored) : 0,
                1,
                false,
                ['results' => is_array($stored) ? $stored : [], 'last_import' => null, 'search' => $payload]
            );
            $this->prune($userId);
            $job = (array) $this->store->find($id, $userId, true);

            return ResponseEnvelope::success($response, [
                'search_id'  => $id,
                'expires_at' => (string) $job['expires_at'],
                'results'    => array_map([CopyCatalogingMapper::class, 'result'], is_array($outcome->json['results'] ?? null) ? array_values($outcome->json['results']) : []),
                'servers'    => array_map([CopyCatalogingMapper::class, 'report'], is_array($outcome->json['servers'] ?? null) ? array_values($outcome->json['servers']) : []),
            ], [], 201);
        } catch (\Throwable $e) {
            return $this->fail($response, 'copy cataloging search', $e);
        }
    }

    public function import(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $json = JsonBody::parse($request);
        $key = StaffIdempotency::keyFrom($request, $json);
        if ($key === false) {
            return $this->invalid($response, ['idempotency_key' => __('Chiave di idempotenza non valida.')]);
        }
        $errors = [];
        $searchId = is_string($json['search_id'] ?? null) ? $json['search_id'] : '';
        if ($searchId === '') {
            $errors['search_id'] = __('Valore non valido.');
        }
        $rawIds = $json['ids'] ?? null;
        $ids = [];
        if (is_array($rawIds) && array_is_list($rawIds)) {
            foreach ($rawIds as $raw) {
                if (!is_string($raw) || preg_match('/^[a-f0-9]{16}$/', $raw) !== 1) {
                    $errors['ids'] = __('Valore non valido.');
                    break;
                }
                $ids[] = $raw;
            }
        }
        if (!isset($errors['ids']) && $ids === []) {
            $errors['ids'] = __('Seleziona almeno un record da importare');
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAX_IDS) {
            $errors['ids'] = sprintf(__('Puoi importare al massimo %d record alla volta.'), self::MAX_IDS);
        }
        $mode = $json['duplicates'] ?? 'skip';
        if (!is_string($mode) || !in_array($mode, \App\Support\Marc\MarcBookImporter::MODES, true)) {
            $errors['duplicates'] = __('Valore non valido.');
        }
        $covers = $json['covers'] ?? false;
        if (!is_bool($covers)) {
            $errors['covers'] = __('Valore non valido.');
        }
        if ($errors !== []) {
            return $this->invalid($response, $errors);
        }
        $userId = (int) (StaffPaging::user($request)['id'] ?? 0);
        try {
            $plugin = $this->plugin();
            if ($plugin === null) {
                return $this->unavailable($response);
            }
            // Searches are personal (the web keeps them in the operator's session).
            $job = $this->store->find($searchId, $userId, false);
            if ($job === null || $job['kind'] !== self::KIND) {
                return ResponseEnvelope::error($response, 'not_found', __('Ricerca non trovata: ripeti la ricerca.'), 404);
            }
            if ($job['expired'] === true) {
                return ResponseEnvelope::error($response, 'gone', __('Risultato non più disponibile: ripeti la ricerca'), 410, ['status' => 'expired']);
            }
            $locks = new StaffIdempotency($this->db);
            $lock = $locks->lockName('copycat', (string) $job['id']);
            if (!$locks->lock($lock)) {
                return ResponseEnvelope::error($response, 'concurrent_retry', CirculationOutcome::message('concurrent_retry'), 409);
            }
            try {
                $job = (array) $this->store->find($searchId, $userId, false);
                $state = $job['state'];
                $last = is_array($state['last_import'] ?? null) ? $state['last_import'] : null;
                // The plugin's own replay contract: the page's import token is
                // the client's Idempotency-Key here (hashed to its alphabet).
                $token = $key !== null ? hash('sha256', $key) : '';
                $fingerprint = CopyCatalogingMapper::fingerprint($ids, (string) $mode, $covers);
                $replayed = false;
                if ($token !== '' && $last !== null && hash_equals((string) ($last['token'] ?? ''), $token)) {
                    if (!hash_equals((string) ($last['fingerprint'] ?? ''), $fingerprint)) {
                        return ResponseEnvelope::error($response, 'idempotency_conflict', __('Questa chiave di idempotenza è già stata usata per una richiesta diversa.'), 422, ['field' => 'idempotency_key', 'fields' => ['idempotency_key' => __('Questa chiave di idempotenza è già stata usata per una richiesta diversa.')]]);
                    }
                    $replayed = true;
                }
                $payload = ['ids' => $ids, 'duplicates' => $mode, 'covers' => $covers, 'import_token' => $token];
                $results = is_array($state['results'] ?? null) ? $state['results'] : [];
                $outcome = Delegation::run(static function () use ($plugin, $payload, $results, $last): ResponseInterface {
                    $_SESSION[self::RESULTS_KEY] = $results;
                    if ($last !== null) {
                        $_SESSION[self::LAST_IMPORT_KEY] = $last;
                    }

                    return $plugin->copyCatalogingImportAction(
                        Delegation::request('POST', '/admin/books/copy-cataloging/import', [], [], (string) json_encode($payload)),
                        new SlimResponse()
                    );
                });
                if ($outcome->status !== 200 || ($outcome->json['success'] ?? false) !== true) {
                    throw new \RuntimeException('copy cataloging import answered HTTP ' . $outcome->status);
                }
                $result = CopyCatalogingMapper::importResult($outcome->json);
                $newLast = $outcome->session[self::LAST_IMPORT_KEY] ?? null;
                if (is_array($newLast) && !$replayed) {
                    $state['last_import'] = $newLast;
                    $this->store->setStatus((string) $job['id'], 'running');
                    $this->store->saveChunk((string) $job['id'], $state, (int) $job['processed'] + count($ids), 'ready', [
                        'imported' => $result['imported'], 'updated' => $result['updated'], 'skipped' => $result['skipped'],
                        'failed' => $result['failed'], 'covers' => $result['covers'], 'import_id' => $result['import_id'],
                    ]);
                }

                return ResponseEnvelope::success($response, $result, $replayed ? ['replayed' => true] : []);
            } finally {
                $locks->unlock($lock);
            }
        } catch (\Throwable $e) {
            return $this->fail($response, 'copy cataloging import', $e);
        }
    }

    /**
     * Keep only the operator's latest searches: each one may hold up to
     * 4 MiB of records (CopyCataloging::MAX_STORED_BYTES).
     */
    private function prune(int $userId): void
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM mobile_import_jobs WHERE user_id = ? AND kind = ? ORDER BY created_at DESC, id DESC LIMIT 100 OFFSET ' . self::KEEP_SEARCHES
        );
        if ($stmt === false) {
            return;
        }
        $kind = self::KIND;
        $stmt->bind_param('is', $userId, $kind);
        $stmt->execute();
        $result = $stmt->get_result();
        $old = [];
        while ($result !== false && ($row = $result->fetch_row()) !== null) {
            $old[] = (string) $row[0];
        }
        $stmt->close();
        foreach ($old as $id) {
            $delete = $this->db->prepare('DELETE FROM mobile_import_jobs WHERE id = ? AND kind = ?');
            if ($delete !== false) {
                $delete->bind_param('ss', $id, $kind);
                $delete->execute();
                $delete->close();
            }
        }
    }

    private static function pluginActive(mysqli $db): bool
    {
        $result = $db->query("SELECT is_active FROM plugins WHERE name = 'z39-server' LIMIT 1");
        $row = $result instanceof \mysqli_result ? $result->fetch_row() : null;

        return (int) ($row[0] ?? 0) === 1;
    }

    /** The z39-server plugin, or null when it is not active. */
    private function plugin(): ?\Z39ServerPlugin
    {
        if (!self::pluginActive($this->db)) {
            return null;
        }
        if (!class_exists(\Z39ServerPlugin::class, false)) {
            $file = dirname(__DIR__, 4) . '/z39-server/Z39ServerPlugin.php';
            if (!is_file($file)) {
                return null;
            }
            require_once $file;
        }

        return new \Z39ServerPlugin($this->db, new \App\Support\HookManager($this->db));
    }

    private function unavailable(ResponseInterface $response): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'copy_cataloging_unavailable', __('La catalogazione derivata richiede il plugin Z39.50/SRU attivo.'), 404);
    }

    /** @param array<string, string> $fields */
    private function invalid(ResponseInterface $response, array $fields): ResponseInterface
    {
        return ResponseEnvelope::error($response, 'validation', (string) reset($fields), 422, ['fields' => $fields, 'field' => (string) array_key_first($fields)]);
    }

    private function fail(ResponseInterface $response, string $what, \Throwable $e): ResponseInterface
    {
        SecureLogger::error('[MobileApi] staff ' . $what . ' failed: ' . $e->getMessage());

        return ResponseEnvelope::error($response, 'internal_error', __('Errore interno.'), 500);
    }
}
