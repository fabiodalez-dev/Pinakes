<?php
declare(strict_types=1);

/**
 * Issue #374 — activity feed storage, field diffs and feed filters.
 *
 * Exercises ActivityLog against the real schema inside a rolled-back
 * transaction, so the test proves that the existing log_modifiche table is
 * sufficient and leaves the developer/CI database unchanged.
 */

use App\Support\ActivityLog;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = [];
foreach (preg_split('/\r?\n/', (string) @file_get_contents($root . '/.env')) as $line) {
    if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim(trim($value), "\"'");
}

$socket = getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? '');
try {
    $db = $socket !== '' && file_exists($socket)
        ? new mysqli(
            null,
            getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''),
            getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')),
            getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''),
            0,
            $socket
        )
        : new mysqli(
            getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'),
            getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''),
            getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')),
            getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''),
            (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306))
        );
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: database unreachable — mandatory for this test: {$e->getMessage()}\n");
    exit(1);
}

$passed = 0;
$check = static function (bool $ok, string $label) use (&$passed): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $passed++;
    echo "  OK  {$label}\n";
};

// The decoder remains compatible with pre-#374 rows and does not expose its
// internal metadata as a field change.
$decoded = ActivityLog::decodeRow([
    'id' => 1,
    'azione' => 'aggiornamento',
    'dati_precedenti' => json_encode(['titolo' => 'Prima', 'updated_at' => '2026-01-01']),
    'dati_nuovi' => json_encode([
        'titolo' => 'Dopo',
        'updated_at' => '2026-01-02',
        '_activity' => ['type' => 'edit', 'event' => 'book.updated', 'book_title' => 'Dopo'],
    ]),
]);
$check($decoded['type'] === 'edit' && $decoded['event'] === 'book.updated', 'activity metadata is decoded');
$check($decoded['book_title'] === 'Dopo', 'metadata supplies the book title when no join is present');
$check(
    $decoded['changes'] === [['field' => 'titolo', 'before' => 'Prima', 'after' => 'Dopo']],
    'field diff excludes metadata and technical timestamps'
);

$legacy = ActivityLog::decodeRow([
    'azione' => 'aggiornamento',
    'dati_precedenti' => '{invalid',
    'dati_nuovi' => null,
]);
$check($legacy['type'] === 'edit' && $legacy['changes'] === [], 'malformed legacy snapshots fail safely');

// Labels returned dynamically by ActivityLog are invisible to the literal
// __() scanner, so guard their presence in every shipped locale explicitly.
$activityReflection = new ReflectionClass(ActivityLog::class);
$eventConstant = $activityReflection->getReflectionConstant('EVENT_LABELS');
$fieldConstant = $activityReflection->getReflectionConstant('FIELD_LABELS');
$eventLabels = $eventConstant !== false ? $eventConstant->getValue() : [];
$fieldLabels = $fieldConstant !== false ? $fieldConstant->getValue() : [];
$dynamicLabels = array_unique(array_merge(
    is_array($eventLabels) ? array_values($eventLabels) : [],
    is_array($fieldLabels) ? array_values($fieldLabels) : [],
    array_map(static fn(string $type): string => ActivityLog::typeLabel($type), ActivityLog::TYPES),
    ['Inserimento', 'Cancellazione', 'Aggiornamento'],
    array_filter(array_map(
        static fn(string $value): ?string => ActivityLog::valueLabel('stato', $value),
        ['disponibile', 'non_disponibile', 'prestato', 'prenotato', 'pendente', 'da_ritirare', 'in_corso', 'in_ritardo', 'restituito', 'perso', 'danneggiato', 'annullato', 'scaduto', 'manutenzione', 'in_restauro', 'in_trasferimento', 'attiva', 'completata', 'annullata']
    )),
    array_filter(array_map(
        static fn(string $value): ?string => ActivityLog::valueLabel('origine', $value),
        ['richiesta', 'prenotazione', 'diretto', 'ncip']
    ))
));
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $translations = json_decode((string) file_get_contents($root . '/locale/' . $locale . '.json'), true);
    $missing = array_filter(
        $dynamicLabels,
        static fn(string $label): bool => !is_array($translations)
            || !array_key_exists($label, $translations)
            || !is_string($translations[$label])
            || trim($translations[$label]) === ''
    );
    $check($missing === [], "all dynamic activity labels are translated in {$locale}");
}

$run = substr(hash('sha256', uniqid((string) getmypid(), true)), 0, 10);
$card = 'ZZ374-' . $run;
$email = strtolower($card) . '@activity.test.local';
$title = 'ZZACTIVITY374_' . $run;

$db->begin_transaction();
try {
    $stmt = $db->prepare(
        "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato) "
        . "VALUES (?, 'Audit', 'Operator', ?, '', 'staff', 'attivo')"
    );
    $stmt->bind_param('ss', $card, $email);
    $stmt->execute();
    $operatorId = (int) $db->insert_id;
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO libri (titolo, stato, copie_totali, copie_disponibili) VALUES (?, 'disponibile', 1, 1)");
    $stmt->bind_param('s', $title);
    $stmt->execute();
    $bookId = (int) $db->insert_id;
    $stmt->close();

    $_SESSION['user']['id'] = $operatorId;
    $check(
        ActivityLog::recordBookEvent(
            $db,
            $bookId,
            'aggiornamento',
            'edit',
            'book.updated',
            ['titolo' => $title, 'descrizione' => 'Prima'],
            ['titolo' => $title, 'descrizione' => 'Dopo'],
            bookTitle: $title,
            source: 'manual'
        ),
        'book edit is written to the existing audit table'
    );
    $check(
        ActivityLog::recordBookEvent(
            $db,
            $bookId,
            'aggiornamento',
            'enrich',
            'enrich.updated',
            ['numero_pagine' => null],
            ['numero_pagine' => 320],
            bookTitle: $title,
            source: 'bulk'
        ),
        'enrichment event is written with its own type'
    );

    $bookFeed = ActivityLog::forBook($db, $bookId, 1, 20);
    $check($bookFeed['total'] === 2 && count($bookFeed['items']) === 2, 'per-book timeline returns both events');
    $check($bookFeed['items'][0]['type'] === 'enrich', 'timeline is newest-first');
    $check($bookFeed['items'][0]['book_title'] === $title, 'timeline resolves the joined book title');
    $check($bookFeed['items'][0]['operator_name'] === 'Audit Operator', 'timeline resolves the operator');

    $enrichFeed = ActivityLog::recent($db, 1, 12, 'enrich', $operatorId);
    $check($enrichFeed['total'] === 1 && $enrichFeed['items'][0]['record_id'] === $bookId, 'type and operator filters compose');

    $operators = ActivityLog::operators($db);
    $matchingOperators = array_filter($operators, static fn(array $operator): bool => $operator['id'] === $operatorId);
    $check(count($matchingOperators) === 1, 'operator filter options are read from audit rows');

    $stmt = $db->prepare('DELETE FROM utenti WHERE id = ?');
    $stmt->bind_param('i', $operatorId);
    $stmt->execute();
    $stmt->close();
    $detachedFeed = ActivityLog::forBook($db, $bookId, 1, 20);
    $check($detachedFeed['items'][0]['operator_name'] === 'Audit Operator', 'operator name survives account deletion in event metadata');

    // SYSTEM_OPERATOR sentinel: an automatic action running inside a reader's
    // HTTP session must not be attributed to that session user.
    $check(
        ActivityLog::recordBookEvent(
            $db,
            $bookId,
            'aggiornamento',
            'loan',
            'loan.approved',
            [],
            ['stato' => 'da_ritirare'],
            operatorId: ActivityLog::SYSTEM_OPERATOR,
            bookTitle: $title,
            source: 'approval'
        ),
        'system event is written with the SYSTEM_OPERATOR sentinel'
    );
    $systemRow = $db->query(
        "SELECT utente_id FROM log_modifiche WHERE tabella='libri' AND record_id={$bookId} ORDER BY id DESC LIMIT 1"
    )->fetch_assoc();
    $check($systemRow !== null && $systemRow['utente_id'] === null, 'system event stores a NULL operator despite an active session user');
    $systemFeed = ActivityLog::forBook($db, $bookId, 1, 20);
    $check(($systemFeed['items'][0]['operator_name'] ?? '') === '', 'system event renders without an operator name (feed shows "Sistema")');

    // forBook type filter mirrors the dashboard allow-list behaviour.
    $loanOnly = ActivityLog::forBook($db, $bookId, 1, 20, null, 'loan');
    $check($loanOnly['total'] === 1 && $loanOnly['items'][0]['type'] === 'loan', 'forBook type filter narrows to the requested type');
    $ignoredType = ActivityLog::forBook($db, $bookId, 1, 20, null, 'not-a-type');
    $check($ignoredType['total'] === 3, 'forBook ignores out-of-allow-list types');
} catch (Throwable $e) {
    $db->rollback();
    $db->close();
    fwrite(STDERR, "FAIL: {$e->getMessage()}\n");
    exit(1);
}

$db->rollback();
$db->close();
unset($_SESSION['user']);

echo PHP_EOL . "Passed: {$passed}, Failed: 0" . PHP_EOL;
