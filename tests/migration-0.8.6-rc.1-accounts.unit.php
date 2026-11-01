<?php
declare(strict_types=1);

/**
 * Issue #480 — the 0.8.6 roles migration (migrate_0.8.6-rc.1.sql: an RC sorts below its final,
 * so the file carries the first version that ships it) changes nothing an existing account has.
 *
 * The real migrate_0.8.6-rc.1.sql runs on a sandbox copy of the REAL pre-0.8.6 `utenti`
 * table (today's schema.sql definition without the two columns 0.8.6 adds), holding one
 * account of every type in every state with every column filled in, NULLs and accented
 * text included. Every column of every account, created_at and updated_at included, is
 * compared before and after, after a first run and after a second one; the new columns
 * hold nothing (no role, no digital grant) for anyone.
 *
 * Run:  php tests/migration-0.8.6-rc.1-accounts.unit.php
 *       (E2E_DB_HOST / E2E_DB_PORT / E2E_DB_USER / E2E_DB_PASS / E2E_DB_NAME select another server,
 *        e.g. the MariaDB one)
 */

require dirname(__DIR__) . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$env = Dotenv\Dotenv::parse((string) @file_get_contents(dirname(__DIR__) . '/.env'));
$setting = static function (string $key, string $default = '') use ($env): string {
    $process = getenv('E2E_' . $key);
    return $process === false ? ($env[$key] ?? $default) : $process; // set but empty counts (a passwordless server)
};
if ($setting('DB_USER') === '' || $setting('DB_NAME') === '') {
    echo "SKIP: no database credentials\n";
    exit(0);
}
$host = $setting('DB_HOST', '127.0.0.1');
$socket = $setting('DB_SOCKET');
$db = ($host === 'localhost' && $socket !== '' && file_exists($socket) && getenv('E2E_DB_PORT') === false)
    ? new mysqli(null, $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), 0, $socket)
    : new mysqli($host === 'localhost' ? '127.0.0.1' : $host, $setting('DB_USER'), $setting('DB_PASS'), $setting('DB_NAME'), (int) $setting('DB_PORT', '3306'));
$db->set_charset('utf8mb4');
$server = (string) $db->query('SELECT VERSION()')->fetch_row()[0];

$pass = 0;
$fail = 0;
$check = static function (bool $ok, string $label) use (&$pass, &$fail): void {
    echo ($ok ? '  OK  ' : '  FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$tag = 'zz_acc086_' . bin2hex(random_bytes(3));
$users = $tag . '_utenti';
$roles = $tag . '_ruoli';

// The pre-0.8.6 table: today's definition without what 0.8.6 adds.
$schema = (string) file_get_contents(dirname(__DIR__) . '/installer/database/schema.sql');
if (preg_match('/CREATE TABLE `utenti` \((.*?)\n\) ENGINE=InnoDB[^;]*;/s', $schema, $m) !== 1) {
    echo "FAIL: utenti not found in schema.sql\n";
    exit(1);
}
$lines = array_filter(explode("\n", $m[1]), static fn (string $line): bool =>
    !preg_match('/`ruolo_id`|`accesso_digitale`|idx_utenti_ruolo|fk_utenti_ruolo|`tipologia_id`|idx_utenti_tipologia|fk_utenti_tipologia|`card_expiry_warning_sent_for`/', $line));
$body = rtrim(implode("\n", $lines));
$body = rtrim($body, ',');
$db->query("CREATE TABLE `{$users}` ({$body}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$migration = (string) file_get_contents(dirname(__DIR__) . '/installer/database/migrations/migrate_0.8.6-rc.1.sql');
$migration = preg_replace('/^--.*$/m', '', $migration);
$migration = str_replace(['fk_utenti_ruolo', 'idx_utenti_ruolo', 'uk_ruoli_nome'], [$tag . '_fk', $tag . '_idx', $tag . '_uk'], (string) $migration);
$migration = preg_replace('/\butenti\b/', $users, $migration);
$migration = preg_replace('/\bruoli\b/', $roles, (string) $migration);
$run = static function () use ($db, $migration): void {
    foreach (explode(';', (string) $migration) as $sql) {
        if (trim($sql) !== '') {
            $db->query($sql);
        }
    }
};

try {
    echo "Server: {$server}\n";
    $n = 0;
    foreach (['standard', 'premium', 'staff', 'admin'] as $type) {
        foreach (['attivo', 'sospeso', 'scaduto'] as $state) {
            $n++;
            $full = $n % 2 === 0; // every other account with every optional column filled in
            $stmt = $db->prepare("INSERT INTO `{$users}` (codice_tessera, nome, cognome, email, password, telefono, indirizzo, data_nascita, sesso,
                foto_profilo_url, data_registrazione, data_scadenza_tessera, stato, tipo_utente, cod_fiscale, note_utente, email_verificata,
                privacy_accettata, data_accettazione_privacy, privacy_policy_version, locale, token_verifica_email, data_token_verifica,
                token_reset_password, data_token_reset, data_ultimo_accesso, created_at, updated_at)
                VALUES (?, ?, 'Àccèntò', ?, ?, ?, ?, ?, ?, ?, '2024-01-02 03:04:05', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2024-01-02 03:04:05', '2025-06-07 08:09:10')");
            $card = 'ACC' . $n;
            $name = "Prova {$type} {$state}";
            $email = "{$tag}-{$n}@example.test";
            $hash = password_hash("secret-{$n}", PASSWORD_DEFAULT);
            $phone = $full ? '+39 333 0000' . $n : null;
            $address = $full ? "Via Ünïcode {$n}, Città" : null;
            $born = $full ? '1980-0' . (($n % 9) + 1) . '-15' : null;
            $sex = $full ? ['M', 'F', 'Altro'][$n % 3] : null;
            $photo = $full ? "/uploads/profili/{$n}.jpg" : null;
            $expiry = $state === 'scaduto' ? '2024-12-31' : '2027-12-31';
            $fiscal = $full ? 'RSSMRA80A01H50' . sprintf('%02d', $n) : null;
            $note = $full ? "Nota «{$n}» — con a capo\nseconda riga" : null;
            $verified = $n % 2;
            $privacy = $full ? 1 : 0;
            $privacyAt = $full ? '2024-02-03 04:05:06' : null;
            $privacyVersion = $full ? '1.2' : null;
            $locale = ['it_IT', 'en_US', 'de_DE', 'fr_FR', 'da_DK'][$n % 5];
            $verifyToken = $full ? bin2hex(random_bytes(16)) : null;
            $verifyAt = $full ? '2024-03-04 05:06:07' : null;
            $resetToken = $full ? bin2hex(random_bytes(16)) : null;
            $resetAt = $full ? '2024-04-05 06:07:08' : null;
            $lastLogin = $full ? '2025-05-06 07:08:09' : null;
            $stmt->bind_param('ssssssssssssssiissssssss', $card, $name, $email, $hash, $phone, $address, $born, $sex, $photo, $expiry,
                $state, $type, $fiscal, $note, $verified, $privacy, $privacyAt, $privacyVersion, $locale, $verifyToken, $verifyAt,
                $resetToken, $resetAt, $lastLogin);
            $stmt->execute();
            $stmt->close();
        }
    }
    $columns = [];
    foreach ($db->query("SHOW COLUMNS FROM `{$users}`")->fetch_all(MYSQLI_ASSOC) as $column) {
        $columns[] = '`' . $column['Field'] . '`';
    }
    $snapshot = static fn (): array => $db->query('SELECT ' . implode(', ', $columns) . " FROM `{$users}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $before = $snapshot();
    $check(count($before) === 12 && count($columns) >= 29, sprintf('%d accounts (4 types × 3 states) with %d columns on the pre-0.8.6 table', count($before), count($columns)));

    $run();
    $check($snapshot() === $before, 'after the migration every column of every account is identical (updated_at included)');
    $added = $db->query("SELECT COUNT(*) FROM `{$users}` WHERE ruolo_id IS NULL AND accesso_digitale = 0")->fetch_row()[0];
    $check((int) $added === 12, 'no account received a role or the digital grant');
    $check((int) $db->query("SELECT COUNT(*) FROM `{$roles}`")->fetch_row()[0] === 3, 'the three preset roles exist, assigned to nobody');

    $run();
    $check($snapshot() === $before, 'a second run (an interrupted upgrade retried) changes nothing either');
    $check((int) $db->query("SELECT COUNT(*) FROM `{$roles}`")->fetch_row()[0] === 3, 'and seeds no duplicate role');

    // Logging in still works for every account: the password hashes are untouched.
    $ok = true;
    foreach ($db->query("SELECT id, password FROM `{$users}` ORDER BY id")->fetch_all(MYSQLI_ASSOC) as $i => $row) {
        $ok = $ok && password_verify('secret-' . ($i + 1), $row['password']);
    }
    $check($ok, 'every account still signs in with its own password');
} finally {
    $db->query("DROP TABLE IF EXISTS `{$users}`");
    $db->query("DROP TABLE IF EXISTS `{$roles}`");
}
echo "\n" . ($fail === 0 ? "ALL {$pass} PASS" : "{$fail} FAILED, {$pass} passed") . "\n";
exit($fail > 0 ? 1 : 0);
