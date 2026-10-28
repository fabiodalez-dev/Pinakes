<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Csv;
use App\Support\ImportLogger;
use App\Support\NotificationService;
use App\Support\PasswordSetupToken;
use App\Support\PatronTypeImport;
use App\Support\RegistrationFields;
use App\Support\SecureLogger;
use App\Support\UserImport;
use League\Csv\Statement;
use mysqli;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin → Users → Import CSV.
 *
 * Three steps, all in the browser page:
 *   1. upload: the file is saved, its header and first rows come back with a
 *      suggested column mapping (preview);
 *   2. start: the librarian confirms or corrects the mapping and chooses
 *      whether to send the password-setup invitation;
 *   3. chunk: the rows are processed CHUNK_SIZE at a time, like the book
 *      import, and the last chunk writes the run into import_logs (type
 *      `users`) so the errors can be downloaded from the import history.
 *
 * A row whose email, or card code, already belongs to an account — or already
 * appeared earlier in the same file — is a duplicate and is skipped, never
 * merged: an import must not overwrite a reader the library already manages.
 */
class UserImportController
{
    private const SESSION_KEY = 'user_import';

    public function page(Request $request, Response $response, mysqli $db): Response
    {
        if (!\App\Support\Permissions::can('users.manage')) {
            return $response->withStatus(403);
        }
        $patronTypes = (new PatronTypeImport($db))->available();
        ob_start();
        require __DIR__ . '/../Views/utenti/import.php';
        $content = ob_get_clean();
        ob_start();
        require __DIR__ . '/../Views/layout.php';
        $html = ob_get_clean();
        $response->getBody()->write((string) $html);
        return $response;
    }

    /** A sample file with every recognised column. */
    public function example(Request $request, Response $response, mysqli $db): Response
    {
        if (!\App\Support\Permissions::can('users.manage')) {
            return $response->withStatus(403);
        }
        $writer = Csv::writerToString(';', "'");
        $writer->insertOne(['nome', 'cognome', 'email', 'telefono', 'indirizzo', 'data_nascita', 'codice_tessera', 'data_scadenza_tessera', 'note']);
        $writer->insertOne(['Maria', 'Rossi', 'maria.rossi@example.org', '+39 333 1234567', 'Via Roma 1, Milano', '15/04/1990', 'T0001', '31/12/2027', '']);
        $writer->insertOne(['Luca', 'Bianchi', 'luca.bianchi@example.org', '', '', '1985-09-02', '', '', 'Socio sostenitore']);
        $response->getBody()->write("\xEF\xBB\xBF" . $writer->toString());
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="esempio_import_utenti.csv"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    public function upload(Request $request, Response $response, mysqli $db): Response
    {
        @ini_set('display_errors', '0');
        if (!\App\Support\Permissions::can('users.manage')) {
            return $this->json($response, ['success' => false, 'error' => __('Accesso negato')], 403);
        }
        $this->discardSessionFile();

        $file = $request->getUploadedFiles()['csv_file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['success' => false, 'error' => __('Nessun file caricato')], 400);
        }
        $name = (string) $file->getClientFilename();
        if (!preg_match('/\.(csv|txt|tsv)$/i', $name)) {
            return $this->json($response, ['success' => false, 'error' => __('Il file deve avere estensione .csv')], 400);
        }
        if ((int) $file->getSize() > UserImport::MAX_BYTES) {
            return $this->json($response, ['success' => false, 'error' => __('Il file supera la dimensione massima di 5 MB.')], 400);
        }
        $tmp = $file->getStream()->getMetadata('uri');
        if (!is_string($tmp) || !is_file($tmp)) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile leggere il file caricato')], 400);
        }

        $handle = fopen($tmp, 'r');
        if ($handle === false) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile leggere il file caricato')], 400);
        }
        $sample = [];
        for ($i = 0; $i < 5 && !feof($handle); $i++) {
            $sample[] = fgets($handle);
        }
        fclose($handle);
        $delimiter = UserImport::detectDelimiter($sample);
        if ($delimiter === null) {
            return $this->json($response, ['success' => false, 'error' => __('File CSV non valido: usa ";", "," o TAB come separatore.')], 400);
        }

        $dir = dirname(__DIR__, 2) . '/writable/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $saved = $dir . '/user_import_' . bin2hex(random_bytes(12)) . '.csv';
        if (!@copy($tmp, $saved)) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile salvare il file CSV caricato')], 500);
        }

        try {
            $reader = Csv::readerFromPath($saved, $delimiter);
            $headers = array_map(static fn ($h): string => trim((string) $h), (array) $reader->nth(0));
            $total = max(0, count($reader) - 1);
            $preview = [];
            foreach ((new Statement())->offset(1)->limit(UserImport::PREVIEW_ROWS)->process($reader) as $row) {
                $preview[] = array_map(static fn ($v): string => mb_substr(trim((string) $v), 0, 120), array_values((array) $row));
            }
        } catch (\Throwable $e) {
            @unlink($saved);
            return $this->json($response, ['success' => false, 'error' => __('File CSV vuoto o formato non valido')], 400);
        }
        if ($headers === [] || $total === 0) {
            @unlink($saved);
            return $this->json($response, ['success' => false, 'error' => __('File CSV vuoto o formato non valido')], 400);
        }

        $fields = $this->targetFields($db);
        $_SESSION[self::SESSION_KEY] = [
            'file' => $saved,
            'name' => mb_substr($name, 0, 255),
            'delimiter' => $delimiter,
            'total' => $total,
            'columns' => count($headers),
            'started' => false,
        ];

        return $this->json($response, [
            'success' => true,
            'file_name' => $name,
            'total_rows' => $total,
            'headers' => $headers,
            'preview' => $preview,
            'fields' => $fields,
            'mapping' => UserImport::guessMapping($headers, $fields),
        ]);
    }

    public function start(Request $request, Response $response, mysqli $db): Response
    {
        @ini_set('display_errors', '0');
        if (!\App\Support\Permissions::can('users.manage')) {
            return $this->json($response, ['success' => false, 'error' => __('Accesso negato')], 403);
        }
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($state) || !is_array($body) || !is_file((string) ($state['file'] ?? ''))) {
            return $this->json($response, ['success' => false, 'error' => __('Sessione import scaduta o dati non validi')], 400);
        }
        $check = UserImport::validateMapping($body['mapping'] ?? null, $this->targetFields($db), (int) $state['columns']);
        if ($check['error'] !== null) {
            return $this->json($response, ['success' => false, 'error' => $check['error']], 422);
        }
        $state['mapping'] = $check['mapping'];
        $state['send_invites'] = !empty($body['send_invites']);
        $state['started'] = true;
        $state['created'] = 0;
        $state['duplicates'] = 0;
        $state['failed'] = 0;
        $state['invited'] = 0;
        $state['invite_failed'] = 0;
        $state['errors'] = [];
        $state['seen_emails'] = [];
        $state['seen_codes'] = [];
        $state['next'] = 0;
        $_SESSION[self::SESSION_KEY] = $state;

        return $this->json($response, [
            'success' => true,
            'total_rows' => (int) $state['total'],
            'chunk_size' => UserImport::CHUNK_SIZE,
        ]);
    }

    public function chunk(Request $request, Response $response, mysqli $db): Response
    {
        @set_time_limit(300);
        @ini_set('display_errors', '0');
        if (!\App\Support\Permissions::can('users.manage')) {
            return $this->json($response, ['success' => false, 'error' => __('Accesso negato')], 403);
        }
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($state) || empty($state['started']) || !is_array($body)) {
            return $this->json($response, ['success' => false, 'error' => __('Sessione import scaduta o dati non validi')], 400);
        }
        $start = max(0, (int) ($body['start'] ?? 0));
        // Chunks are processed in order exactly once: a replayed request (a
        // retry after a timeout) must not insert the same rows again.
        if ($start !== (int) $state['next']) {
            return $this->json($response, ['success' => false, 'error' => __('Blocco di righe non valido.')], 409);
        }
        $total = (int) $state['total'];

        try {
            $reader = Csv::readerFromPath((string) $state['file'], (string) $state['delimiter']);
        } catch (\Throwable) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile aprire il file CSV')], 500);
        }

        $customDefinitions = RegistrationFields::definitions($db);
        $locales = array_map('strval', array_keys(\App\Support\I18n::getAvailableLocales()));
        $visible = [
            'sesso' => RegistrationFields::isVisible('sesso'),
            'cod_fiscale' => RegistrationFields::isVisible('cod_fiscale'),
        ];
        $installLocale = \App\Support\I18n::normalizeLocaleCode(\App\Support\I18n::getInstallationLocale());
        if (!in_array($installLocale, $locales, true)) {
            $installLocale = 'it_IT';
        }
        $patronTypes = new PatronTypeImport($db);
        $notifier = $state['send_invites'] ? new NotificationService($db) : null;

        $line = $start + 2; // header is line 1
        $records = (new Statement())->offset(1 + $start)->limit(UserImport::CHUNK_SIZE)->process($reader);
        $processed = 0;
        foreach ($records as $row) {
            $processed++;
            $row = array_values((array) $row);
            $label = '';
            try {
                $parsed = UserImport::normalizeRow($row, $state['mapping'], $customDefinitions, $locales, $visible);
                $record = $parsed['record'];
                $label = trim((string) ($record['nome'] ?? '') . ' ' . (string) ($record['cognome'] ?? '')) ?: (string) ($record['email'] ?? '');
                if ($parsed['error'] !== null) {
                    $this->rowError($state, $line, $this->rowLabel($row, $state['mapping']), $parsed['error'], 'validation');
                    continue;
                }
                $email = (string) $record['email'];
                $code = (string) ($record['codice_tessera'] ?? '');
                if (isset($state['seen_emails'][$email]) || $this->exists($db, 'email', $email)) {
                    $this->duplicate($state, $line, $label, sprintf(__('Email già registrata: %s'), $email));
                    continue;
                }
                if ($code !== '' && (isset($state['seen_codes'][mb_strtolower($code)]) || $this->exists($db, 'codice_tessera', $code))) {
                    $this->duplicate($state, $line, $label, sprintf(__('Codice tessera già assegnato: %s'), $code));
                    continue;
                }
                if ($code === '') {
                    $record['codice_tessera'] = $this->generateCardCode($db);
                }
                $record['locale'] ??= $installLocale;

                $userId = $this->insertUser($db, $record);
                $state['seen_emails'][$email] = true;
                $state['seen_codes'][mb_strtolower((string) $record['codice_tessera'])] = true;
                $state['created']++;

                if ($parsed['custom'] !== []) {
                    RegistrationFields::saveValues($db, $userId, $parsed['custom']);
                }
                if ($patronTypes->assign($userId, $parsed['tipologia']) === 'unknown') {
                    $state['errors'][] = ['line' => $line, 'title' => $label, 'message' => sprintf(__('Tipologia lettore sconosciuta: %s (assegnata quella predefinita)'), (string) $parsed['tipologia']), 'type' => 'warning'];
                }
                if ($notifier !== null) {
                    $token = PasswordSetupToken::issue($db, $userId);
                    if ($token !== null && $notifier->sendUserPasswordSetup($userId, $token)) {
                        $state['invited']++;
                    } else {
                        $state['invite_failed']++;
                        $state['errors'][] = ['line' => $line, 'title' => $label, 'message' => __('Utente importato, ma l\'invito non è stato inviato.'), 'type' => 'email'];
                    }
                }
            } catch (\Throwable $e) {
                $field = $e instanceof \mysqli_sql_exception ? \App\Support\UniqueViolation::fieldFor($e) : 'other';
                if ($field === 'email' || $field === 'codice_tessera' || $field === 'cod_fiscale') {
                    $this->duplicate($state, $line, $label, match ($field) {
                        'email' => __('Email già registrata'),
                        'codice_tessera' => __('Codice tessera già assegnato'),
                        default => __('Codice fiscale già registrato'),
                    });
                } else {
                    SecureLogger::error('[UserImport] row failed', ['line' => $line, 'error' => $e->getMessage()]);
                    $this->rowError($state, $line, $label, __('Errore del database'), 'database');
                }
            } finally {
                $line++;
            }
        }

        $state['next'] = $start + $processed;
        $done = $processed === 0 || $state['next'] >= $total;
        $payload = [
            'success' => true,
            'processed' => (int) $state['next'],
            'total_rows' => $total,
            'created' => (int) $state['created'],
            'duplicates' => (int) $state['duplicates'],
            'failed' => (int) $state['failed'],
            'invited' => (int) $state['invited'],
            'done' => $done,
        ];

        if ($done) {
            $payload['import_id'] = $this->finish($db, $state);
            $payload['invite_failed'] = (int) $state['invite_failed'];
            $payload['errors'] = array_slice($state['errors'], 0, 50);
            $this->discardSessionFile();
            unset($_SESSION[self::SESSION_KEY]);
            SecureLogger::info('Users imported from CSV', [
                'created' => $state['created'],
                'duplicates' => $state['duplicates'],
                'failed' => $state['failed'],
                'by' => $_SESSION['user']['id'] ?? null,
            ]);
        } else {
            $_SESSION[self::SESSION_KEY] = $state;
        }
        return $this->json($response, $payload);
    }

    /** Write the run into import_logs; returns its import_id ('' when logging failed). */
    private function finish(mysqli $db, array $state): string
    {
        try {
            $logger = new ImportLogger($db, 'users', (string) $state['name'], isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null);
            $logger->setStats(['imported' => (int) $state['created'], 'failed' => (int) $state['failed']]);
            foreach ($state['errors'] as $error) {
                $logger->addError((int) $error['line'], (string) $error['title'], (string) $error['message'], (string) $error['type']);
            }
            $logger->complete((int) $state['total']);
            return $logger->getImportId();
        } catch (\Throwable $e) {
            SecureLogger::error('[UserImport] could not write the import log: ' . $e->getMessage());
            return '';
        }
    }

    /** @param array<string,?string> $record */
    private function insertUser(mysqli $db, array $record): int
    {
        // Never imported: the password is a random secret nobody knows, the
        // reader sets a real one through the setup invitation.
        $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stato = 'attivo';
        $tipo = 'standard';
        $verified = 1;
        $stmt = $db->prepare('INSERT INTO utenti (
                nome, cognome, email, telefono, password, indirizzo, cod_fiscale, data_nascita, sesso,
                codice_tessera, data_scadenza_tessera, stato, tipo_utente, note_utente, locale, email_verificata
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        if ($stmt === false) {
            throw new \RuntimeException($db->error);
        }
        $stmt->bind_param(
            str_repeat('s', 15) . 'i',
            $record['nome'],
            $record['cognome'],
            $record['email'],
            $record['telefono'],
            $password,
            $record['indirizzo'],
            $record['cod_fiscale'],
            $record['data_nascita'],
            $record['sesso'],
            $record['codice_tessera'],
            $record['data_scadenza_tessera'],
            $stato,
            $tipo,
            $record['note_utente'],
            $record['locale'],
            $verified
        );
        try {
            $stmt->execute();
            return (int) $stmt->insert_id;
        } finally {
            $stmt->close();
        }
    }

    private function exists(mysqli $db, string $column, string $value): bool
    {
        $sql = $column === 'email'
            ? 'SELECT 1 FROM utenti WHERE LOWER(email) = LOWER(?) LIMIT 1'
            : 'SELECT 1 FROM utenti WHERE codice_tessera = ? LIMIT 1';
        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $found = $stmt->get_result()->fetch_row() !== null;
        $stmt->close();
        return $found;
    }

    /** Same shape as the codes UsersController generates for new readers. */
    private function generateCardCode(mysqli $db): string
    {
        do {
            $code = 'T' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
        } while ($this->exists($db, 'codice_tessera', $code));
        return $code;
    }

    private function rowError(array &$state, int $line, string $label, string $message, string $type): void
    {
        $state['failed']++;
        $state['errors'][] = ['line' => $line, 'title' => $label, 'message' => $message, 'type' => $type];
    }

    private function duplicate(array &$state, int $line, string $label, string $message): void
    {
        $state['duplicates']++;
        $state['errors'][] = ['line' => $line, 'title' => $label, 'message' => $message, 'type' => 'duplicate'];
    }

    /**
     * A readable name for a row that failed validation (nome/cognome/email cells).
     *
     * @param list<mixed> $row
     * @param array<int,string> $mapping
     */
    private function rowLabel(array $row, array $mapping): string
    {
        $parts = [];
        foreach (['nome', 'cognome', 'email'] as $field) {
            $index = array_search($field, $mapping, true);
            if ($index !== false && trim((string) ($row[$index] ?? '')) !== '') {
                $parts[] = trim((string) $row[$index]);
            }
        }
        return mb_substr(implode(' ', $parts), 0, 200);
    }

    /** @return array<string,string> */
    private function targetFields(mysqli $db): array
    {
        return UserImport::targetFields(RegistrationFields::definitions($db), (new PatronTypeImport($db))->available());
    }

    private function discardSessionFile(): void
    {
        $file = $_SESSION[self::SESSION_KEY]['file'] ?? null;
        if (is_string($file) && str_contains($file, '/writable/uploads/user_import_') && is_file($file)) {
            @unlink($file);
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
