<?php
declare(strict_types=1);

/**
 * Coerenza email 0.7.81 (report review email):
 *
 *  A. Il nuovo template 'loan_picked_up' (conferma ritiro, buco B1) esiste in
 *     base it_IT + 4 override locale + 5 seed installer con lo STESSO set di
 *     placeholder.
 *  B. Le nuove stringhe __() sono presenti in tutti e 5 i locale JSON.
 *  C. I 5 sender privi di guard (approved, rejectedDirect, renewed,
 *     pickupExpired, pickupCancelled) rifiutano un utente senza email: return
 *     false E nessuna riga outbox con destinatario vuoto (DB reale, dati zz_*).
 *  D. Sanzione 0 in loan_copy_outcome → {{sanzione}} = 'Nessun addebito'
 *     (localizzato), importo > 0 → cifra formattata con valuta.
 *  E. Il template orfano 'reservation_book_available' è fuori da registry e
 *     DEDICATED_RETRY_TEMPLATES, e il sender morto è stato rimosso.
 *
 * Run: php tests/mail-coherence-0781.unit.php
 * Fail duro senza DB: la parte comportamentale è obbligatoria.
 */

use App\Support\DateHelper;
use App\Support\Mailer;
use App\Support\NotificationService;
use App\Support\SettingsMailTemplates;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$passed = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

/** @return string[] sorted unique {{placeholder}} names found in the strings */
$placeholders = static function (string ...$texts): array {
    $found = [];
    foreach ($texts as $text) {
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $text, $m);
        foreach ($m[1] as $name) {
            $found[$name] = true;
        }
    }
    $names = array_keys($found);
    sort($names);
    return $names;
};

// ── A. loan_picked_up: base + 4 override + 5 seed, stesso set di placeholder ─
echo "A. loan_picked_up template coverage\n";

$base = SettingsMailTemplates::get('loan_picked_up', 'it_IT');
$check(is_array($base), 'base it_IT registry defines loan_picked_up');
$declared = $base['placeholders'] ?? [];
sort($declared);
$baseSet = $placeholders((string) ($base['subject'] ?? ''), (string) ($base['body'] ?? ''));
$check($baseSet === $declared, 'base declared placeholders match the ones used in subject+body');
$expected = ['data_prestito', 'data_scadenza', 'libro_titolo', 'utente_nome'];
$check($baseSet === $expected, 'placeholder set is exactly {utente_nome, libro_titolo, data_prestito, data_scadenza}');

foreach (['en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $overrides = require $root . '/app/Support/mail_templates/' . $locale . '.php';
    $entry = $overrides['loan_picked_up'] ?? null;
    $ok = is_array($entry)
        && trim((string) ($entry['subject'] ?? '')) !== ''
        && $placeholders((string) ($entry['subject'] ?? ''), (string) ($entry['body'] ?? '')) === $baseSet;
    $check($ok, "{$locale} override ships loan_picked_up with the same placeholder set");

    // Il registry risolto per-locale deve servire il testo tradotto, non l'italiano.
    $resolved = SettingsMailTemplates::get('loan_picked_up', $locale);
    $check(is_array($resolved) && $resolved['subject'] === ($entry['subject'] ?? null),
        "{$locale} resolved registry serves the translated subject");
}

foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $seed = (string) file_get_contents($root . '/installer/database/data_' . $locale . '.sql');
    $row = null;
    foreach (preg_split('/\n/', $seed) as $line) {
        if (str_contains($line, "'loan_picked_up'")) {
            $row = $line;
            break;
        }
    }
    $verb = in_array($locale, ['de_DE', 'fr_FR'], true) ? 'INSERT IGNORE INTO' : 'INSERT INTO';
    $ok = $row !== null
        && str_starts_with($row, $verb . ' `email_templates` VALUES (27,')
        && str_contains($row, "'{$locale}'")
        && $placeholders($row) === $baseSet;
    $check($ok, "seed data_{$locale}.sql has loan_picked_up as row 27 ({$verb}) with the same placeholder set");
}

// ── B. Parità delle nuove chiavi nei 5 locale JSON ───────────────────────────
echo "B. locale key parity for new strings\n";
$newKeys = [
    'Ritiro confermato',
    'Inviata quando il lettore ritira fisicamente il libro e il prestito inizia.',
    'Nessun addebito',
];
foreach (['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'] as $locale) {
    $catalog = json_decode((string) file_get_contents($root . '/locale/' . $locale . '.json'), true);
    $ok = is_array($catalog);
    foreach ($newKeys as $key) {
        $ok = $ok && isset($catalog[$key]) && trim((string) $catalog[$key]) !== '';
    }
    $check($ok, "{$locale}.json carries the three new strings");
}

// ── E. Template orfano e sender morto rimossi ────────────────────────────────
echo "E. orphan template removal\n";
$registry = SettingsMailTemplates::all('it_IT');
$check(!isset($registry['reservation_book_available']), 'reservation_book_available is no longer in the registry');
$notifSource = (string) file_get_contents($root . '/app/Support/NotificationService.php');
preg_match('/DEDICATED_RETRY_TEMPLATES\s*=\s*\[(.*?)\]/s', $notifSource, $m);
$check(isset($m[1]) && !str_contains($m[1], 'reservation_book_available'),
    'reservation_book_available is out of DEDICATED_RETRY_TEMPLATES');
$check(!str_contains($notifSource, 'function sendLoanRejectedNotification('),
    'dead loan-id based sendLoanRejectedNotification(int) is removed');
$check(str_contains($notifSource, 'function sendLoanRejectedNotificationDirect('),
    'sendLoanRejectedNotificationDirect (the live path) is still present');
$check(str_contains($notifSource, 'function sendLoanPickedUpNotification('),
    'sendLoanPickedUpNotification sender exists');

// ── DB: parte comportamentale (C + D). Fail duro se il DB non c'è. ──────────
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
        ? new mysqli(null, getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), 0, $socket)
        : new mysqli(getenv('E2E_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'), getenv('E2E_DB_USER') ?: ($env['DB_USER'] ?? ''), getenv('E2E_DB_PASS') ?: ($env['DB_PASS'] ?? ($env['DB_PASSWORD'] ?? '')), getenv('E2E_DB_NAME') ?: ($env['DB_NAME'] ?? ''), (int) (getenv('E2E_DB_PORT') ?: ($env['DB_PORT'] ?? 3306)));
    $db->set_charset('utf8mb4');
    DateHelper::synchronizeDatabaseSession($db);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: database unreachable — the empty-email guard and zero-penalty checks are mandatory: ' . $e->getMessage() . "\n");
    exit(1);
}

$run = bin2hex(random_bytes(6));
$title = "ZZ_MAILCOH_{$run}";
$emailWith = "zz-mailcoh-{$run}@test.local";
$inventory = "ZZMC-{$run}";
$bookId = 0;
$noMailUserId = 0;
$mailUserId = 0;
$cleanup = static function () use ($db, &$bookId, &$noMailUserId, &$mailUserId, $emailWith): void {
    try {
        if ($bookId > 0) {
            $db->query("DELETE FROM prestiti WHERE libro_id = {$bookId}");
            $db->query("DELETE FROM copie WHERE libro_id = {$bookId}");
            $db->query("DELETE FROM libri WHERE id = {$bookId}");
        }
        if ($noMailUserId > 0) {
            $db->query("DELETE FROM utenti WHERE id = {$noMailUserId}");
        }
        if ($mailUserId > 0) {
            $db->query("DELETE FROM utenti WHERE id = {$mailUserId}");
        }
        $stmt = $db->prepare("DELETE FROM email_delivery_outbox WHERE recipient_email IN ('', ?)");
        $stmt->bind_param('s', $emailWith);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable) {
    }
};
register_shutdown_function($cleanup);
set_exception_handler(static function (Throwable $e) use ($cleanup, $db): void {
    try { $cleanup(); } catch (Throwable) {}
    fwrite(STDERR, 'FAIL: uncaught ' . $e->getMessage() . PHP_EOL);
    $db->close();
    exit(1);
});

// Fixture: libro + copia + utente SENZA email + utente CON email + prestiti.
$stmt = $db->prepare("INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES (?, 1, 0, 'prestato')");
$stmt->bind_param('s', $title);
$stmt->execute();
$bookId = (int) $db->insert_id;
$stmt->close();

$stmt = $db->prepare("INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (?, ?, 'prestato')");
$stmt->bind_param('is', $bookId, $inventory);
$stmt->execute();
$copyId = (int) $db->insert_id;
$stmt->close();

$password = password_hash('MailCoherence!1', PASSWORD_DEFAULT);
$card1 = 'ZZMC' . strtoupper($run);
$stmt = $db->prepare(
    "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
     VALUES (?, 'NoMail', 'Coherence', '', ?, 'standard', 'attivo', 1)"
);
$stmt->bind_param('ss', $card1, $password);
$stmt->execute();
$noMailUserId = (int) $db->insert_id;
$stmt->close();

$card2 = 'ZZMD' . strtoupper($run);
$stmt = $db->prepare(
    "INSERT INTO utenti (codice_tessera, nome, cognome, email, password, tipo_utente, stato, email_verificata)
     VALUES (?, 'HasMail', 'Coherence', ?, ?, 'standard', 'attivo', 1)"
);
$stmt->bind_param('sss', $card2, $emailWith, $password);
$stmt->execute();
$mailUserId = (int) $db->insert_id;
$stmt->close();

$start = DateHelper::today();
$end = (new DateTimeImmutable($start))->modify('+14 days')->format('Y-m-d');
$mkLoan = static function (int $userId, string $stato, string $sanzione) use ($db, $bookId, $copyId, $start, $end): int {
    $stmt = $db->prepare(
        "INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, data_restituzione, stato, origine, attivo, sanzione)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'diretto', 0, ?)"
    );
    $stmt->bind_param('iiissssd', $bookId, $copyId, $userId, $start, $end, $end, $stato, $sanzione);
    $stmt->execute();
    $id = (int) $db->insert_id;
    $stmt->close();
    return $id;
};

// Trasporto forzato irraggiungibile: nessun invio reale, ma sendWithRetry
// persiste comunque nell'outbox — così un guard MANCANTE lascerebbe una riga
// con destinatario vuoto (segnale discriminante).
$smtpProbe = new ReflectionProperty(Mailer::class, 'smtpReachable');
$smtpProbe->setValue(null, false);
$service = new NotificationService($db);

echo "C. empty-email guards on the five fixed senders\n";
$noMailLoan = $mkLoan($noMailUserId, 'restituito', '0.00');
$check($service->sendLoanApprovedNotification($noMailLoan) === false, 'sendLoanApprovedNotification refuses a borrower without email');
$check($service->sendLoanRenewedNotification($noMailLoan, 3) === false, 'sendLoanRenewedNotification refuses a borrower without email');
$check($service->sendLoanRejectedNotificationDirect('', 'NoMail Coherence', $title) === false, 'sendLoanRejectedNotificationDirect refuses an empty address');
$check($service->sendPickupExpiredNotification($noMailLoan) === false, 'sendPickupExpiredNotification refuses a borrower without email');
$check($service->sendPickupCancelledNotification($noMailLoan, 'test') === false, 'sendPickupCancelledNotification refuses a borrower without email');
$check($service->sendLoanPickedUpNotification($noMailLoan) === false, 'sendLoanPickedUpNotification refuses a borrower without email');

$emptyRows = (int) $db->query("SELECT COUNT(*) FROM email_delivery_outbox WHERE recipient_email = ''")->fetch_row()[0];
$check($emptyRows === 0, 'no outbox row was queued for the empty address (guard fires BEFORE persistence)');

echo "D. zero penalty renders as localized 'Nessun addebito'\n";
$zeroLoan = $mkLoan($mailUserId, 'perso', '0.00');
$check($service->sendLoanCopyOutcomeNotification($zeroLoan) === false, 'unreachable transport reports failure (zero-penalty send attempt)');
$stmt = $db->prepare("SELECT variables_json FROM email_delivery_outbox WHERE recipient_email = ? AND template_name = 'loan_copy_outcome' ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $emailWith);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$vars = json_decode((string) ($row['variables_json'] ?? ''), true);
// Valore atteso risolto nel VERO locale del destinatario (stessa via del
// sender), non pinnato sull'italiano: su un install non-it il catalogo
// traduce e il confronto letterale fallirebbe pur essendo tutto corretto.
$expectedNoCharge = $service->translateInLocale('Nessun addebito', $service->resolveRecipientLocale($emailWith));
$check(is_array($vars) && ($vars['sanzione'] ?? null) === $expectedNoCharge,
    "zero penalty produces sanzione = '{$expectedNoCharge}' (recipient locale)");
$check(is_array($vars) && !preg_match('/\d/', (string) ($vars['sanzione'] ?? '')),
    'zero penalty never renders as a numeric amount');

$stmt = $db->prepare("DELETE FROM email_delivery_outbox WHERE recipient_email = ?");
$stmt->bind_param('s', $emailWith);
$stmt->execute();
$stmt->close();

$paidLoan = $mkLoan($mailUserId, 'danneggiato', '12.50');
$check($service->sendLoanCopyOutcomeNotification($paidLoan) === false, 'unreachable transport reports failure (positive-penalty send attempt)');
$stmt = $db->prepare("SELECT variables_json FROM email_delivery_outbox WHERE recipient_email = ? AND template_name = 'loan_copy_outcome' ORDER BY id DESC LIMIT 1");
$stmt->bind_param('s', $emailWith);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$vars = json_decode((string) ($row['variables_json'] ?? ''), true);
// Separatore decimale dipendente dal locale del destinatario: 12,50 (it/de/fr)
// oppure 12.50 (en) sono entrambi formati corretti dello stesso importo.
$check(is_array($vars) && preg_match('/12[.,]50/', (string) ($vars['sanzione'] ?? '')) === 1,
    'positive penalty still renders the formatted amount');

// ── F. picked_up raggiunge il lettore anche a titolo archiviato (review L6) ──
// La copia è già nelle mani dell'utente: il soft-delete governa la
// prestabilità, non i prestiti in corso — stessa convenzione di
// sendLoanRenewedNotification (LEFT JOIN esente) e il titolo resta REALE.
echo "F. picked_up survives an archived title with the real title\n";
$db->query("DELETE FROM email_delivery_outbox WHERE recipient_email = '" . $db->real_escape_string($emailWith) . "'");
$archivedLoan = $mkLoan($mailUserId, 'in_corso', '0.00');
$db->query("UPDATE libri SET deleted_at = NOW() WHERE id = {$bookId}");
try {
    $check($service->sendLoanPickedUpNotification($archivedLoan) === false,
        'unreachable transport reports failure (archived-title pickup attempt)');
    $stmt = $db->prepare("SELECT variables_json FROM email_delivery_outbox WHERE recipient_email = ? AND template_name = 'loan_picked_up' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $emailWith);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $vars = json_decode((string) ($row['variables_json'] ?? ''), true);
    $check(is_array($vars), 'the pickup email is still queued for the archived title (no silent drop)');
    $check(is_array($vars) && ($vars['libro_titolo'] ?? null) === $title,
        'the queued pickup email carries the REAL title, not a fallback');
} finally {
    $db->query("UPDATE libri SET deleted_at = NULL WHERE id = {$bookId}");
}

$cleanup();
$db->close();

echo "\nPassed: {$passed}   Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
