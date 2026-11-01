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
    /**
     * Error-report entries kept in the session (and in import_logs): a file
     * of thousands of bad rows must not grow the session without bound. The
     * failed/duplicate counters keep counting past it; one `truncated` entry
     * after the last says so.
     */
    public const MAX_STORED_ERRORS = 500;
    /**
     * A started run whose last request is older than this is abandoned (the
     * tab was closed, the answer to start was lost): another upload may then
     * replace it and "Annulla import" may close it. A live run sends a chunk
     * every few seconds, and PHP's session lock already serialises requests
     * of the same session, so a run in progress is never cut short.
     */
    public const IDLE_SECONDS = 300;
    /** Upload form values of the separator choice; anything else means "detect". */
    private const DELIMITERS = [';' => ';', ',' => ',', 'tab' => "\t"];

    public function page(Request $request, Response $response, mysqli $db): Response
    {
        if (!\App\Support\Permissions::can('users.manage')) {
            return $response->withStatus(403);
        }
        // Coming back to the page abandons a preview that was never started
        // ("Annulla" reloads it); an import in progress is left alone.
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($state) && empty($state['started'])) {
            $this->discardSessionFile();
            unset($_SESSION[self::SESSION_KEY]);
        }
        // The session's own interrupted run keeps its staged file while its
        // page is visited; a file swept meanwhile (the session outlived it)
        // closes the run into the import history instead of offering a
        // resume that could only fail.
        $this->touchSessionFile();
        UserImport::sweepStaleUploads(self::uploadDir());
        $expiredImport = $this->closeVanishedImport($db);
        $patronTypes = (new PatronTypeImport($db))->available();
        $resumeImport = self::resumable($_SESSION[self::SESSION_KEY] ?? null);
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
        // A run still under way (another tab) is never replaced: its next
        // block would find the session gone and lose its position.
        $current = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($current) && self::isRunning($current)) {
            return $this->json($response, [
                'success' => false,
                'error' => __('Un import è già in corso: attendi la fine o riprendilo da questa pagina.'),
                'processed' => (int) ($current['next'] ?? 0),
                'total_rows' => (int) ($current['total'] ?? 0),
            ], 409);
        }
        // The staged file of a run already in the session is replaced only
        // once the new upload proved valid (below): a refused upload leaves
        // the import in progress intact.
        UserImport::sweepStaleUploads(self::uploadDir());

        $file = $request->getUploadedFiles()['csv_file'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['success' => false, 'error' => __('Nessun file caricato')], 400);
        }
        $name = (string) $file->getClientFilename();
        if (!preg_match('/\.(csv|txt|tsv)$/i', $name)) {
            return $this->json($response, ['success' => false, 'error' => __('Il file deve avere estensione .tsv, .csv o .txt')], 400);
        }
        if ((int) $file->getSize() > UserImport::MAX_BYTES) {
            return $this->json($response, ['success' => false, 'error' => __('Il file supera la dimensione massima di 5 MB.')], 400);
        }
        $tmp = $file->getStream()->getMetadata('uri');
        if (!is_string($tmp) || !is_file($tmp)) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile leggere il file caricato')], 400);
        }

        $bytes = @file_get_contents($tmp);
        if ($bytes === false) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile leggere il file caricato')], 400);
        }
        // The staged copy is always UTF-8 without BOM: a Windows-1252 export
        // is converted here, once, so every later step reads clean text.
        ['text' => $text, 'converted' => $converted] = UserImport::toUtf8($bytes);
        unset($bytes);
        // The separator chosen on the form wins; otherwise it is detected on
        // the first logical records (a quoted cell may span several lines).
        $chosen = (array) ($request->getParsedBody() ?? []);
        $delimiter = self::DELIMITERS[is_string($chosen['delimiter'] ?? null) ? $chosen['delimiter'] : ''] ?? UserImport::detectDelimiter(UserImport::sampleRecords($text, 5));
        if ($delimiter === null) {
            return $this->json($response, ['success' => false, 'error' => __('File CSV non valido: usa ";", "," o TAB come separatore.')], 400);
        }

        $dir = self::uploadDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $saved = $dir . '/user_import_' . bin2hex(random_bytes(12)) . '.csv';
        if (@file_put_contents($saved, $text) === false) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile salvare il file CSV caricato')], 500);
        }
        unset($text);

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
        // '' = the run this upload replaces was closed but its history entry
        // could not be written: said apart from the conversion notice.
        $closedId = $this->closeSessionImport($db);
        // Start, every block and "Annulla import" name the preview they come
        // from: an upload in another tab replaces this state, and a page
        // still showing the earlier preview must not apply its mapping to
        // the rows of the new file.
        $previewId = bin2hex(random_bytes(12));
        $_SESSION[self::SESSION_KEY] = [
            'preview_id' => $previewId,
            'file' => $saved,
            'name' => mb_substr($name, 0, 255),
            'delimiter' => $delimiter,
            'total' => $total,
            'columns' => count($headers),
            'started' => false,
        ];

        return $this->json($response, [
            'success' => true,
            'preview_id' => $previewId,
            'file_name' => $name,
            'total_rows' => $total,
            'headers' => $headers,
            'preview' => $preview,
            'fields' => $fields,
            'mapping' => UserImport::guessMapping($headers, $fields),
            'notice' => $converted ? __('Il file non era in UTF-8 ed è stato convertito da Windows-1252: controlla le lettere accentate nell\'anteprima.') : null,
            'history_warning' => $closedId === '' ? __('L\'import precedente è stato chiuso, ma lo storico import non è stato aggiornato: gli utenti già creati restano.') : null,
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
        if (!self::samePreview($state, $body)) {
            return $this->stale($response);
        }
        if (!empty($state['started'])) {
            // A replayed start (double click, a second tab, a lost answer)
            // would reset the counters and the position of a run already
            // under way: the answer carries that position, so the page
            // resumes the run instead.
            return $this->json($response, [
                'success' => false,
                'error' => __('Import già avviato: attendi la fine o ricarica il file.'),
                'processed' => (int) ($state['next'] ?? 0),
                'total_rows' => (int) ($state['total'] ?? 0),
            ], 409);
        }
        $check = UserImport::validateMapping($body['mapping'] ?? null, $this->targetFields($db), (int) $state['columns']);
        if ($check['error'] !== null) {
            return $this->json($response, ['success' => false, 'error' => $check['error']], 422);
        }
        $state['mapping'] = $check['mapping'];
        // A real boolean: the string "false" must not send invitations.
        $state['send_invites'] = filter_var($body['send_invites'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $state['started'] = true;
        $state['created'] = 0;
        $state['duplicates'] = 0;
        $state['failed'] = 0;
        $state['invited'] = 0;
        $state['invite_failed'] = 0;
        $state['errors'] = [];
        $state['next'] = 0;
        $state['active_at'] = time();
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
        $body = json_decode((string) $request->getBody(), true);
        if (is_array($_SESSION[self::SESSION_KEY] ?? null) && is_array($body) && !self::samePreview($_SESSION[self::SESSION_KEY], $body)) {
            // A block from a preview another upload has replaced: never
            // import the new file under the old mapping. No position in the
            // answer, so the page does not "resume" a run that is not its own.
            return $this->stale($response);
        }
        if (is_array($_SESSION[self::SESSION_KEY] ?? null) && !empty($_SESSION[self::SESSION_KEY]['finished']) && is_array($_SESSION[self::SESSION_KEY]['result'] ?? null)) {
            // The run is over: this is the retry of a last block whose answer
            // was lost (a gateway timeout while the invitations went out).
            // Replaying the final answer shows the summary instead of an
            // error that would invite uploading the same file again.
            return $this->json($response, $_SESSION[self::SESSION_KEY]['result']);
        }
        if (!is_array($_SESSION[self::SESSION_KEY] ?? null) || empty($_SESSION[self::SESSION_KEY]['started']) || !is_array($body)) {
            return $this->json($response, ['success' => false, 'error' => __('Sessione import scaduta o dati non validi')], 400);
        }
        // Every change below lands in the session at once, row by row: when
        // a request dies mid-chunk (timeout, fatal error), PHP still saves
        // the session at shutdown, so the position survives and the page
        // resumes from it instead of replaying the rows already written.
        $state = &$_SESSION[self::SESSION_KEY];
        $start = max(0, (int) ($body['start'] ?? 0));
        // Chunks are processed in order exactly once: a replayed request (a
        // retry after a timeout) must not insert the same rows again. The
        // answer carries the position to resume from.
        if ($start !== (int) $state['next']) {
            return $this->json($response, ['success' => false, 'error' => __('Blocco di righe non valido.'), 'processed' => (int) $state['next']], 409);
        }
        $total = (int) $state['total'];
        if (!is_file((string) $state['file'])) {
            // Swept or deleted meanwhile: touch() would recreate it empty and
            // the run would end as if the remaining rows did not exist. The
            // rows already done are written to the import history first.
            unset($state);
            $importId = $this->closeSessionImport($db);
            return $this->json($response, [
                'success' => false,
                'error' => $importId === ''
                    ? __('Il file dell\'import non è più disponibile e lo storico import non è stato aggiornato: gli utenti già creati restano. Carica di nuovo il file per completarlo.')
                    : __('Il file dell\'import non è più disponibile: le righe già elaborate sono state registrate nello storico import. Carica di nuovo il file per completarlo.'),
                'import_id' => $importId,
            ], 409);
        }
        @touch((string) $state['file']); // in use: not stale for sweepStaleUploads()
        $state['active_at'] = time(); // driven by a page: not abandoned (isRunning())

        $handle = @fopen((string) $state['file'], 'rb');
        if ($handle === false) {
            return $this->json($response, ['success' => false, 'error' => __('Impossibile aprire il file CSV')], 500);
        }
        // Each block resumes at the byte where the previous one stopped, so
        // a large file is read once instead of again from the top for every
        // block. Only the first block (or a run started before the offset
        // was kept) skips the header and the rows already done by counting.
        $delimiter = (string) $state['delimiter'];
        if (is_int($state['offset'] ?? null)) {
            fseek($handle, $state['offset']);
        } else {
            for ($skip = 1 + $start; $skip > 0; $skip--) {
                if (self::nextRecord($handle, $delimiter) === null) {
                    break;
                }
            }
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
        $processed = 0;
        while ($processed < UserImport::CHUNK_SIZE && ($row = self::nextRecord($handle, $delimiter)) !== null) {
            $offset = (int) ftell($handle);
            $processed++;
            $label = '';
            try {
                $parsed = UserImport::normalizeRow($row, $state['mapping'], $customDefinitions, $locales, $visible);
                $record = $parsed['record'];
                $label = $this->text(trim((string) ($record['nome'] ?? '') . ' ' . (string) ($record['cognome'] ?? '')) ?: (string) ($record['email'] ?? ''));
                if ($parsed['error'] !== null) {
                    $this->rowError($state, $line, $this->rowLabel($row, $state['mapping']), $parsed['error'], 'validation');
                    continue;
                }
                $email = (string) $record['email'];
                $code = (string) ($record['codice_tessera'] ?? '');
                // The rows inserted by earlier blocks are in the table already:
                // exists() catches a repeat within the file too, without a
                // per-row set in the session that grows with every import.
                if ($this->exists($db, 'email', $email)) {
                    $this->duplicate($state, $line, $label, sprintf(__('Email già registrata: %s'), $email));
                    continue;
                }
                if ($code !== '' && $this->exists($db, 'codice_tessera', $code)) {
                    $this->duplicate($state, $line, $label, sprintf(__('Codice tessera già assegnato: %s'), $code));
                    continue;
                }
                if ($code === '') {
                    $record['codice_tessera'] = $this->generateCardCode($db);
                }
                $record['locale'] ??= $installLocale;

                $userId = $this->insertUser($db, $record);
                $state['created']++;
                // The account exists from here on: what follows completes it, so
                // a failure is a warning on a created row, never a failed row
                // (that would count it twice and invite a duplicate re-import).
                $this->completeImportedUser($db, $state, $userId, $line, $label, $parsed, $patronTypes, $notifier);
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
                // Position and byte offset move together, row by row.
                $state['next'] = $start + $processed;
                $state['offset'] = $offset;
                $state['active_at'] = time();
            }
        }
        fclose($handle);

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
            if ($payload['import_id'] === '') {
                // The accounts are created and counted either way; only the
                // history entry (and the downloadable report) is missing.
                $payload['log_error'] = __('Import completato, ma lo storico non è stato salvato: il report degli errori non è scaricabile.');
            }
            $payload['invite_failed'] = (int) $state['invite_failed'];
            $payload['errors'] = array_slice($state['errors'], 0, 50);
            $summary = ['created' => $state['created'], 'duplicates' => $state['duplicates'], 'failed' => $state['failed']];
            $this->discardSessionFile();
            unset($state);
            // Only the final answer is kept, for a retry of this request;
            // the next upload or a visit to the page replaces it.
            $_SESSION[self::SESSION_KEY] = ['finished' => true, 'preview_id' => (string) ($_SESSION[self::SESSION_KEY]['preview_id'] ?? ''), 'result' => $payload];
            SecureLogger::info('Users imported from CSV', $summary + ['by' => $_SESSION['user']['id'] ?? null]);
        }
        return $this->json($response, $payload);
    }

    /**
     * The next non-blank record of an open CSV, or null at its end. Split
     * with the same controls as Csv::readerFromPath() (double-quote
     * enclosure, no escape character, blank lines skipped), so the rows match
     * the ones the league Reader counted and previewed at upload.
     *
     * @param resource $handle
     * @return list<?string>|null
     */
    private static function nextRecord($handle, string $delimiter): ?array
    {
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($row !== [null]) {
                return $row;
            }
        }
        return null;
    }

    /**
     * "Annulla import" on an interrupted run: the rows already written stay
     * (they are real accounts now) and go into the import history as a
     * partial run; the staged file and the session state are released. A run
     * still active is refused, so a second tab cannot cut it short.
     */
    public function cancel(Request $request, Response $response, mysqli $db): Response
    {
        @ini_set('display_errors', '0');
        if (!\App\Support\Permissions::can('users.manage')) {
            return $this->json($response, ['success' => false, 'error' => __('Accesso negato')], 403);
        }
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        $body = json_decode((string) $request->getBody(), true);
        if (is_array($state) && !self::samePreview($state, is_array($body) ? $body : [])) {
            // A page left over from an earlier preview: the import now in the
            // session belongs to another upload and is left alone.
            return $this->json($response, ['success' => true, 'stale' => true, 'import_id' => null]);
        }
        if (!is_array($state) || empty($state['started']) || !empty($state['finished'])) {
            unset($_SESSION[self::SESSION_KEY]);
            return $this->json($response, ['success' => true, 'import_id' => null]);
        }
        if (self::isRunning($state)) {
            return $this->json($response, [
                'success' => false,
                'error' => sprintf(__('L\'import è ancora attivo: riprendilo, oppure riprova tra %d minuti.'), (int) ceil(self::IDLE_SECONDS / 60)),
                'processed' => (int) ($state['next'] ?? 0),
            ], 409);
        }
        $importId = $this->closeSessionImport($db);
        // The run is closed either way; when its history entry could not be
        // written the page says so instead of moving on as if it were.
        return $this->json($response, [
            'success' => true,
            'import_id' => $importId,
            'history_warning' => $importId === '' ? __('L\'import precedente è stato chiuso, ma lo storico import non è stato aggiornato: gli utenti già creati restano.') : null,
        ]);
    }

    /**
     * Position of an interrupted run the page can resume, or null.
     *
     * @return array{preview_id:string, name:string, processed:int, total:int}|null
     */
    public static function resumable(mixed $state): ?array
    {
        if (!is_array($state) || empty($state['started']) || !empty($state['finished'])) {
            return null;
        }
        return [
            'preview_id' => (string) ($state['preview_id'] ?? ''),
            'name' => (string) ($state['name'] ?? ''),
            'processed' => (int) ($state['next'] ?? 0),
            'total' => (int) ($state['total'] ?? 0),
        ];
    }

    /**
     * Whether a request names the preview held in the session. A missing or
     * empty id never matches: every page that can start, drive or cancel an
     * import received the id with its preview.
     *
     * @param array<mixed> $state
     * @param array<mixed> $body
     */
    private static function samePreview(array $state, array $body): bool
    {
        $current = (string) ($state['preview_id'] ?? '');
        $given = $body['preview_id'] ?? null;
        return $current !== '' && is_string($given) && $given !== '' && hash_equals($current, $given);
    }

    /** 409 for a request from a preview another upload has replaced. */
    private function stale(Response $response): Response
    {
        return $this->json($response, [
            'success' => false,
            'stale' => true,
            'error' => __('Questa anteprima è stata sostituita da un altro file caricato: carica di nuovo il file per importarlo.'),
        ], 409);
    }

    /** A started run whose last request is recent enough to be still driven by a page. */
    private static function isRunning(array $state): bool
    {
        return !empty($state['started']) && empty($state['finished'])
            && (int) ($state['active_at'] ?? 0) > time() - self::IDLE_SECONDS;
    }

    /**
     * Release the session's import: a started run is written to the import
     * history first (as far as it got), so the accounts it created are not
     * left without a trace. Returns that run's import_id, '' when its history
     * entry could not be written, or null when no started run was closed.
     */
    private function closeSessionImport(mysqli $db): ?string
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        $importId = null;
        if (is_array($state) && !empty($state['started']) && empty($state['finished'])) {
            $importId = $this->finish($db, $state, (int) ($state['next'] ?? 0));
            SecureLogger::info('User import closed before its end', [
                'processed' => (int) ($state['next'] ?? 0),
                'total' => (int) ($state['total'] ?? 0),
                'created' => (int) ($state['created'] ?? 0),
                'by' => $_SESSION['user']['id'] ?? null,
            ]);
        }
        $this->discardSessionFile();
        unset($_SESSION[self::SESSION_KEY]);
        return $importId;
    }

    /** Mark the staged file of the session's interrupted run as in use, so the sweep keeps it. */
    private function touchSessionFile(): void
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_array($state) && !empty($state['started']) && empty($state['finished'])
            && is_string($state['file'] ?? null) && is_file($state['file'])) {
            @touch($state['file']);
        }
    }

    /**
     * Close the session's interrupted run when its staged file is gone: it
     * can no longer be resumed, so it is written to the import history as far
     * as it got. Returns what the page tells the user, or null.
     *
     * @return array{name:string, processed:int, total:int, import_id:?string}|null
     */
    public function closeVanishedImport(mysqli $db): ?array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state) || empty($state['started']) || !empty($state['finished'])
            || is_file((string) ($state['file'] ?? ''))) {
            return null;
        }
        $importId = $this->closeSessionImport($db);
        return [
            'name' => (string) ($state['name'] ?? ''),
            'processed' => (int) ($state['next'] ?? 0),
            'total' => (int) ($state['total'] ?? 0),
            'import_id' => $importId,
        ];
    }

    /** Write the run into import_logs; returns its import_id ('' when logging failed). */
    private function finish(mysqli $db, array $state, ?int $rows = null): string
    {
        try {
            $logger = new ImportLogger($db, 'users', (string) $state['name'], isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null);
            $logger->setStats(['imported' => (int) $state['created'], 'failed' => (int) $state['failed']]);
            foreach ($state['errors'] as $error) {
                $logger->addError((int) $error['line'], (string) $error['title'], (string) $error['message'], (string) $error['type']);
            }
            // complete() reports a failed write by returning false: the id of
            // a log that was never completed must not reach the page.
            return $logger->complete($rows ?? (int) $state['total']) ? $logger->getImportId() : '';
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
        self::report($state, ['line' => $line, 'title' => $label, 'message' => $message, 'type' => $type]);
    }

    private function duplicate(array &$state, int $line, string $label, string $message): void
    {
        $state['duplicates']++;
        self::report($state, ['line' => $line, 'title' => $label, 'message' => $message, 'type' => 'duplicate']);
    }

    /**
     * Add an entry to the run's error report, up to MAX_STORED_ERRORS. The
     * first entry past the cap becomes one `truncated` notice, so the report
     * says it is incomplete instead of silently disagreeing with the counters.
     *
     * @param array<string,mixed> $state
     * @param array{line:int, title:string, message:string, type:string} $entry
     */
    public static function report(array &$state, array $entry): void
    {
        $count = count((array) ($state['errors'] ?? []));
        if ($count < self::MAX_STORED_ERRORS) {
            $state['errors'][] = $entry;
        } elseif ($count === self::MAX_STORED_ERRORS) {
            $state['errors'][] = [
                'line' => $entry['line'],
                'title' => '',
                'message' => sprintf(__('Report limitato a %d voci: le righe successive non sono elencate, ma sono incluse nei conteggi.'), self::MAX_STORED_ERRORS),
                'type' => 'truncated',
            ];
        }
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
        return $this->text(implode(' ', $parts));
    }

    /** A label for the error report: valid UTF-8 (whatever the cells held) and at most 200 characters. */
    private function text(string $value): string
    {
        return mb_substr(mb_scrub($value, 'UTF-8'), 0, 200);
    }

    /**
     * Custom fields, patron type and invitation of a user already inserted.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $parsed
     */
    private function completeImportedUser(mysqli $db, array &$state, int $userId, int $line, string $label, array $parsed, PatronTypeImport $patronTypes, ?NotificationService $notifier): void
    {
        $warn = static function (string $message, \Throwable $e) use (&$state, $line, $label, $userId): void {
            SecureLogger::error('[UserImport] post-insert step failed', ['line' => $line, 'user_id' => $userId, 'error' => $e->getMessage()]);
            self::report($state, ['line' => $line, 'title' => $label, 'message' => $message, 'type' => 'warning']);
        };
        if ($parsed['custom'] !== []) {
            try {
                RegistrationFields::saveValues($db, $userId, $parsed['custom']);
            } catch (\Throwable $e) {
                $warn(__('Utente importato, ma i campi personalizzati non sono stati salvati.'), $e);
            }
        }
        try {
            if ($patronTypes->assign($userId, $parsed['tipologia']) === 'unknown') {
                self::report($state, ['line' => $line, 'title' => $label, 'message' => sprintf(__('Tipologia lettore sconosciuta: %s (assegnata quella predefinita)'), (string) $parsed['tipologia']), 'type' => 'warning']);
            }
        } catch (\Throwable $e) {
            $warn(__('Utente importato, ma la tipologia lettore non è stata assegnata.'), $e);
        }
        if ($notifier !== null) {
            try {
                $token = PasswordSetupToken::issue($db, $userId);
                $sent = $token !== null && $notifier->sendUserPasswordSetup($userId, $token);
            } catch (\Throwable $e) {
                SecureLogger::error('[UserImport] invitation failed', ['line' => $line, 'user_id' => $userId, 'error' => $e->getMessage()]);
                $sent = false;
            }
            if ($sent) {
                $state['invited']++;
            } else {
                $state['invite_failed']++;
                self::report($state, ['line' => $line, 'title' => $label, 'message' => __('Utente importato, ma l\'invito non è stato inviato.'), 'type' => 'email']);
            }
        }
    }

    /**
     * The error report of a user import, for whoever may import users: the
     * generic one under /admin/imports belongs to the catalogue permission.
     */
    public function downloadErrors(Request $request, Response $response, mysqli $db): Response
    {
        if (!\App\Support\Permissions::can('users.manage')) {
            return $response->withStatus(403);
        }
        return (new ImportHistoryController())->downloadErrors($request, $response, $db, 'users');
    }

    private static function uploadDir(): string
    {
        return dirname(__DIR__, 2) . '/writable/uploads';
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
        // A stray invalid byte becomes U+FFFD rather than an empty body the page
        // cannot parse.
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
