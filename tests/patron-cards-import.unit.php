<?php
declare(strict_types=1);
/**
 * Library cards, patron photo and user import (plan phase 7): the pure logic
 * (header recognition, row validation, card settings, PDF geometry) and the two
 * pieces that touch the database in isolation — PatronTypeImport against
 * sandbox tables, PatronPhoto against one scratch reader.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\PatronCardPdf;
use App\Support\PatronCardSettings;
use App\Support\PatronPhoto;
use App\Support\PatronTypeImport;
use App\Support\UserImport;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $label);
    }
    $checks++;
    echo "OK {$label}\n";
};

// ── UserImport: headers and mapping ─────────────────────────────────────
$fields = UserImport::targetFields([['id' => 7, 'etichetta' => 'Telegram', 'tipo' => 'text', 'obbligatorio' => false]], false);
$check(!isset($fields['tipologia']), 'the patron type is not offered while its schema is missing');
$check(!in_array('password', array_keys($fields), true), 'there is no password field to map');
$check(UserImport::normalizeHeader("\xEF\xBB\xBFE-Mail ") === 'email', 'a BOM, case and punctuation are ignored in headers');
$check(UserImport::normalizeHeader('Fødselsdato') === 'fodselsdato', 'Danish letters are transliterated');
// Under the "C" locale iconv's ASCII//TRANSLIT yields "?" (glibc) or nothing
// (musl) for an accented letter; the headers must not depend on it.
$savedLocale = setlocale(LC_CTYPE, '0');
setlocale(LC_CTYPE, 'C');
$check(UserImport::normalizeHeader('Prénom') === 'prenom' && UserImport::normalizeHeader('Téléphone') === 'telephone', 'French accents are removed whatever the locale');
$check(UserImport::normalizeHeader('Gültig bis') === 'gultigbis' && UserImport::normalizeHeader('GEBURTSDATUM ') === 'geburtsdatum', 'German umlauts and upper case are normalised whatever the locale');
$check(UserImport::normalizeHeader('Nº de carte') === 'ndecarte' && UserImport::normalizeHeader('Straße') === 'strasse' && UserImport::normalizeHeader('Cœur') === 'coeur', 'º is removed deterministically, ß and œ expand');
$check(UserImport::guessMapping(['Nº de carte'], $fields) === [0 => 'codice_tessera'], 'the abbreviated French card header is recognised under the "C" locale');
$check(UserImport::normalizeGender('Männlich') === 'M' && UserImport::normalizeGender('Féminin') === 'F' && UserImport::normalizeGender('Autre') === 'Altro', 'gender words with accents are recognised whatever the locale');
$check(UserImport::guessMapping(['Prénom', 'Nom', 'Courriel', 'Téléphone', 'Date de naissance', 'Numéro de carte', "Date d'expiration"], $fields) === [0 => 'nome', 1 => 'cognome', 2 => 'email', 3 => 'telefono', 4 => 'data_nascita', 5 => 'codice_tessera', 6 => 'data_scadenza_tessera'], 'French headers are recognised under the "C" locale');
setlocale(LC_CTYPE, $savedLocale === false ? 'C' : $savedLocale);

$guess = UserImport::guessMapping(['Nome', 'Cognome', 'E-mail', 'Codice tessera', 'Password', 'Telegram', 'Nome'], $fields);
$check($guess === [0 => 'nome', 1 => 'cognome', 2 => 'email', 3 => 'codice_tessera', 4 => '', 5 => 'custom:7', 6 => ''], 'Italian headers, a custom field and a repeated column map as expected; password is ignored');
$guess = UserImport::guessMapping(['First name', 'Last name', 'Email', 'Card number', 'Date of birth', 'Expiry'], $fields);
$check($guess === [0 => 'nome', 1 => 'cognome', 2 => 'email', 3 => 'codice_tessera', 4 => 'data_nascita', 5 => 'data_scadenza_tessera'], 'English headers are recognised');
$guess = UserImport::guessMapping(['Vorname', 'Nachname', 'E-Mail', 'Geburtsdatum', 'Geschlecht'], $fields);
$check($guess === [0 => 'nome', 1 => 'cognome', 2 => 'email', 3 => 'data_nascita', 4 => 'sesso'], 'German headers are recognised');
$withTypes = UserImport::targetFields([], true);
$check(UserImport::guessMapping(['Patron type'], $withTypes) === [0 => 'tipologia'], 'a patron type column is recognised once the schema exists');

$v = UserImport::validateMapping(['0' => 'nome', '2' => 'email', '1' => ''], $fields, 3);
$check($v['error'] === null && $v['mapping'] === [0 => 'nome', 2 => 'email'], 'a valid mapping is kept, ignored columns dropped');
$check(UserImport::validateMapping([0 => 'nome'], $fields, 3)['error'] !== null, 'email must be mapped');
$check(UserImport::validateMapping([0 => 'nome', 1 => 'email', 2 => 'email'], $fields, 3)['error'] !== null, 'a field mapped twice is refused');
$check(UserImport::validateMapping([0 => 'nome', 1 => 'email', 2 => 'password'], $fields, 3)['error'] !== null, 'an unknown target (password) is refused');
$check(UserImport::validateMapping([0 => 'nome', 1 => 'email', 9 => 'cognome'], $fields, 3)['mapping'] === [0 => 'nome', 1 => 'email'], 'a column index outside the file is ignored');
$check(UserImport::validateMapping('x', $fields, 3)['error'] !== null, 'a non-array mapping is refused');

// ── UserImport: rows ────────────────────────────────────────────────────
$map = [0 => 'nome', 1 => 'cognome', 2 => 'email', 3 => 'data_nascita', 4 => 'sesso', 5 => 'locale', 6 => 'codice_tessera', 7 => 'custom:7', 8 => 'note_utente'];
$defs = [['id' => 7, 'etichetta' => 'Telegram', 'tipo' => 'text', 'obbligatorio' => true]];
$locales = ['it_IT', 'en_US', 'de_DE'];
$r = UserImport::normalizeRow([' Anna ', 'Verdi', 'Anna.Verdi@Example.org', '15/04/1990', 'female', 'en', 'T-77', '@anna', "'=SUM(A1)"], $map, $defs, $locales);
$check($r['error'] === null, 'a complete row is valid');
$check($r['record']['email'] === 'anna.verdi@example.org', 'the email is lowercased');
$check($r['record']['nome'] === 'Anna' && $r['record']['data_nascita'] === '1990-04-15', 'cells are trimmed and dates normalised');
$check($r['record']['sesso'] === 'F' && $r['record']['locale'] === 'en_US', 'gender and language are normalised');
$check($r['custom'] === [7 => '@anna'], 'the custom field value is collected');
$check($r['record']['note_utente'] === '=SUM(A1)', 'the export formula escape is undone');
$check(!array_key_exists('password', $r['record']), 'a record never carries a password');
$r = UserImport::normalizeRow(['Bruno', '', 'bruno@example.org', '', '', 'xx', '', '', ''], $map, $defs, $locales);
$check($r['error'] === null && $r['record']['cognome'] === '' && $r['record']['locale'] === null, 'optional cells may be empty and an unknown language falls back; a required custom field is not enforced on import');
$check(UserImport::normalizeRow(['', 'X', 'x@example.org'], $map, $defs, $locales)['error'] !== null, 'a row without a name fails');
$check(UserImport::normalizeRow(['X', 'Y', 'not-an-email'], $map, $defs, $locales)['error'] !== null, 'a row with an invalid email fails');
$check(UserImport::normalizeRow(['X', 'Y', 'x@example.org', '31/02/2001'], $map, $defs, $locales)['error'] !== null, 'an impossible date fails');
$check(UserImport::normalizeRow(['X', 'Y', 'x@example.org', '', '', '', str_repeat('9', 21)], $map, $defs, $locales)['error'] !== null, 'a card code longer than the column fails');
$check(UserImport::normalizeRow(['X', 'Y', 'x@example.org', '', 'M'], $map, $defs, $locales, ['sesso' => false, 'cod_fiscale' => false])['record']['sesso'] === null, 'a hidden profile field is not collected');
$check(UserImport::parseDate('2026-12-31') === '2026-12-31' && UserImport::parseDate('31.12.2026') === '2026-12-31' && UserImport::parseDate('12/31/2026') === null, 'date spellings: ISO, dotted, day-first only');
$check(UserImport::detectDelimiter(["a;b;c\n", "1;2;3\n"]) === ';' && UserImport::detectDelimiter(["a,b\n"]) === ',' && UserImport::detectDelimiter(["abc\n"]) === null, 'delimiter detection');
$check(UserImport::detectDelimiter(["Nome;Cognome;Indirizzo\n", "Mario;Rossi;Via Roma 1, int. 3, scala B, piano 2, 00100 Roma\n", "Anna;Bianchi;Piazza Dante 5, int. 1, scala A, 20100 Milano, MI\n"]) === ';', 'a ";" file whose addresses hold more commas than there are separators is still a ";" file');
$check(UserImport::detectDelimiter(["Nome,Cognome,Note\n", "\"Rossi, Mario\",x,\"a;b;c\"\n", "\"Bianchi; Anna\",y,\"d;e\"\n"]) === ',', 'a "," file with quoted fields full of semicolons is a "," file: a separator inside quotes never counts');
$check(UserImport::detectDelimiter(["Nome\tCognome\tNote\n", "a\tb\tc, d; e\n"]) === "\t", 'a TAB file is a TAB file whatever its cells hold');
$multiline = "nome;cognome;indirizzo\r\nMaria;Rossi;\"Via Roma 1,\nint. 3, scala B,\n00100 Roma\"\r\nLuca;Bianchi;\"Piazza \"\"Dante\"\", 5\"\r\nAnna;Verdi;x\r\n";
$records = UserImport::sampleRecords($multiline, 5);
$check(count($records) === 4 && str_contains($records[1], "int. 3, scala B,\n00100 Roma") && str_starts_with($records[2], 'Luca;'), 'the delimiter sample is made of logical records: a quoted cell spanning lines stays one record, a doubled quote keeps the balance');
$check(count(UserImport::sampleRecords($multiline, 2)) === 2 && UserImport::detectDelimiter($records) === ';', 'the sample stops at the requested count and every record splits like the header');
$check(count(UserImport::sampleRecords("a;b\n\"open\n" . str_repeat("x\n", 200), 5)) === 2, 'an unbalanced quote reads at most MAX_SAMPLE_LINES lines');
$capped = ['errors' => []];
for ($i = 0; $i < \App\Controllers\UserImportController::MAX_STORED_ERRORS + 20; $i++) {
    \App\Controllers\UserImportController::report($capped, ['line' => $i, 'title' => '', 'message' => 'x', 'type' => 'validation']);
}
$check(count($capped['errors']) === \App\Controllers\UserImportController::MAX_STORED_ERRORS, 'the error report kept in the session is capped');
$check(UserImport::detectDelimiter(["Nome\n", "Mario\n", "Anna\n"]) === null && UserImport::detectDelimiter([]) === null && UserImport::detectDelimiter([false]) === null, 'one column, or nothing, is no delimiter');
$check(UserImport::detectDelimiter(["Nome;Cognome;E-mail\n"]) === ';' && UserImport::detectDelimiter(["Nome;Cognome;E-mail\n", ""]) === ';', 'a header alone tells the delimiter when it has more than one column');

// ── UserImport: file encoding ───────────────────────────────────────────
$u = UserImport::toUtf8("\xEF\xBB\xBFNome;Cognome\nCaff\xC3\xA8;Rossi\n");
$check($u['converted'] === false && $u['text'] === "Nome;Cognome\nCaff\xC3\xA8;Rossi\n", 'a UTF-8 file loses its BOM and is otherwise untouched');
$u = UserImport::toUtf8("Nome;Cognome\nCaff\xE8;M\xFCller \x80\n");
$check($u['converted'] === true && $u['text'] === "Nome;Cognome\nCaffè;Müller €\n", 'a Windows-1252 file (Excel on Windows) is converted: è, ü and the euro sign survive');
$check(mb_check_encoding(UserImport::toUtf8("ok\xFF\xFE\xC0")['text'], 'UTF-8'), 'whatever the bytes, the result is valid UTF-8');
$check(UserImport::toUtf8('plain ascii')['converted'] === false, 'ASCII is UTF-8 already: nothing to report');

// ── Card settings ───────────────────────────────────────────────────────
$d = PatronCardSettings::sanitize([]);
$check($d['layout'] === 'classic' && $d['code_type'] === 'code128' && $d['format'] === 'single', 'an empty form falls back to the default layout, code and format');
$check($d['show_photo'] === false, 'an absent checkbox means off');
$s = PatronCardSettings::sanitize(['card_layout' => 'PORTRAIT', 'card_code_type' => 'ean', 'card_format' => 'sheet', 'card_title' => " <b>Biblioteca</b>\n  Civica ", 'card_back_text' => "Riga 1\r\n\r\n\r\n\r\nRiga 2<script>x</script>" . str_repeat('a', 700), 'card_show_logo' => '1']);
$check($s['layout'] === 'portrait' && $s['code_type'] === 'code128' && $s['format'] === 'sheet', 'enum values are case-insensitive and unknown ones fall back');
$check($s['title'] === 'Biblioteca Civica', 'the title is stripped of tags and whitespace');
$check(str_starts_with($s['back_text'], "Riga 1\n\nRiga 2x") && mb_strlen($s['back_text']) === PatronCardSettings::BACK_TEXT_MAX, 'the back text keeps paragraphs, loses tags and is capped');
$check($s['show_logo'] === true && $s['show_expiry'] === false, 'checkboxes are read individually');

// ── Card PDF geometry ───────────────────────────────────────────────────
$cards = [];
for ($i = 1; $i <= 12; $i++) {
    $cards[] = ['name' => "Lettore {$i}", 'code' => sprintf('ZC%04d', $i), 'expiry' => '31/12/2027', 'photo' => null];
}
$pageCount = static fn (string $pdf): int => preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf);
$base = PatronCardSettings::sanitize(['card_show_photo' => 1, 'card_show_expiry' => 1]);
$single = new PatronCardPdf($base, 'Biblioteca', null);
$check($single->cardSize() === [PatronCardPdf::CARD_W, PatronCardPdf::CARD_H], 'the card is CR80 landscape');
$check($single->sheetGrid()[0] * $single->sheetGrid()[1] === 10, 'an A4 sheet holds 10 landscape cards');
$pdf = $single->render($cards, 'single');
$check(str_starts_with($pdf, '%PDF') && $pageCount($pdf) === 12, 'single format: one page per card');
$check($pageCount($single->render($cards, 'sheet')) === 2, 'sheet format: 12 cards on two A4 sheets');
$withBack = new PatronCardPdf(PatronCardSettings::sanitize(['card_back_text' => 'Regolamento']), 'Biblioteca', null);
$check($pageCount($withBack->render($cards, 'single')) === 24 && $pageCount($withBack->render($cards, 'sheet')) === 4, 'a back text adds one back page per front page');
$portrait = new PatronCardPdf(PatronCardSettings::sanitize(['card_layout' => 'portrait', 'card_code_type' => 'qr']), 'Biblioteca', null);
$check($portrait->cardSize() === [PatronCardPdf::CARD_H, PatronCardPdf::CARD_W] && $portrait->sheetGrid()[0] * $portrait->sheetGrid()[1] === 9, 'the portrait card is vertical, 9 per sheet');
$check($pageCount($portrait->render($cards, 'sheet')) === 2, 'portrait sheet: 12 cards on two sheets');
$check($pageCount($single->render([['name' => 'Ünïcødé 名前', 'code' => 'CODE-é', 'expiry' => '', 'photo' => '/nonexistent.jpg']], 'single')) === 1, 'a non-ASCII code and a missing photo still render');

// ── Database pieces ─────────────────────────────────────────────────────
$env = Dotenv\Dotenv::parse((string) file_get_contents(dirname(__DIR__) . '/.env'));
$db = new mysqli($env['DB_HOST'] ?? '127.0.0.1', $env['DB_USER'], $env['DB_PASS'] ?? '', $env['DB_NAME'], (int) ($env['DB_PORT'] ?? 3306), getenv('E2E_DB_SOCKET') ?: ($env['DB_SOCKET'] ?? null));
$suffix = bin2hex(random_bytes(4));
$types = 'zz_pci_types_' . $suffix;
$users = 'zz_pci_users_' . $suffix;
$userId = 0;
$photoFiles = [];
try {
    $db->query("CREATE TABLE {$users} (id INT NOT NULL PRIMARY KEY, nome VARCHAR(100)) ENGINE=InnoDB");
    $db->query("INSERT INTO {$users} (id, nome) VALUES (1, 'A')");
    $absent = new PatronTypeImport($db, $types, $users);
    $check($absent->available() === false && $absent->assign(1, 'studente') === 'skipped', 'without the patron types schema the assignment is a no-op');
    $db->query("CREATE TABLE {$types} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, codice VARCHAR(40) NOT NULL UNIQUE) ENGINE=InnoDB");
    $db->query("INSERT INTO {$types} (codice) VALUES ('standard'), ('studente')");
    $check((new PatronTypeImport($db, $types, $users))->available() === false, 'the type table alone is not enough: utenti.tipologia_id must exist too');
    $db->query("ALTER TABLE {$users} ADD COLUMN tipologia_id INT NULL");
    $present = new PatronTypeImport($db, $types, $users);
    $check($present->available() === true, 'with table and column the patron types are available');
    $check($present->assign(1, 'STUDENTE') === 'assigned', 'a known code is assigned, case-insensitively');
    $studentId = (int) $db->query("SELECT id FROM {$types} WHERE codice = 'studente'")->fetch_row()[0];
    $check((int) $db->query("SELECT tipologia_id FROM {$users} WHERE id = 1")->fetch_row()[0] === $studentId, 'the reader carries the type id');
    $check($present->assign(1, 'docente') === 'unknown' && $present->assign(1, '') === 'skipped', 'an unknown code is reported, an empty one skipped');

    // Patron photo against one scratch reader.
    $email = 'zz-pci-' . $suffix . '@example.invalid';
    $code = 'ZZPCI' . strtoupper($suffix);
    $hash = password_hash('x', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO utenti (nome, cognome, email, password, codice_tessera) VALUES ('Zz', 'Photo', ?, ?, ?)");
    $stmt->bind_param('sss', $email, $hash, $code);
    $stmt->execute();
    $userId = (int) $stmt->insert_id;
    $stmt->close();

    $img = imagecreatetruecolor(1600, 1200);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 200, 30, 30));
    // Rename the reserved temp file rather than append to its name: tempnam()
    // creates it, and the suffixed copy alone would be cleaned up.
    $reserved = tempnam(sys_get_temp_dir(), 'pci');
    $src = $reserved . '.png';
    rename($reserved, $src);
    imagepng($img, $src);
    $check(PatronPhoto::store($db, $userId, $src) === null, 'a PNG is accepted');
    $stored = PatronPhoto::storedFor($db, $userId);
    $path = PatronPhoto::absolutePath($stored);
    $photoFiles[] = $path;
    $info = $path !== null ? getimagesize($path) : false;
    $check($info !== false && $info[2] === IMAGETYPE_JPEG && max($info[0], $info[1]) === PatronPhoto::MAX_EDGE, 'it is stored as a JPEG bounded to MAX_EDGE');
    $check($path !== null && str_starts_with($path, dirname(__DIR__) . '/storage/patron_photos/'), 'the file lives outside the web root');
    $check(PatronPhoto::store($db, $userId, $src) === null && PatronPhoto::absolutePath($stored) === null, 'a new photo replaces the old file');
    $photoFiles[] = PatronPhoto::absolutePath(PatronPhoto::storedFor($db, $userId));
    // A small file declaring more than MAX_PIXELS is refused before GD decodes it.
    $huge = imagecreate(4001, 4001);
    imagecolorallocate($huge, 255, 255, 255);
    imagepng($huge, $src, 9);
    $check(filesize($src) < PatronPhoto::MAX_BYTES && PatronPhoto::store($db, $userId, $src) === 'invalid_image', 'an image above MAX_PIXELS is refused, whatever its file size');
    // A portrait phone photo: landscape pixels with EXIF Orientation 6.
    $land = imagecreatetruecolor(800, 400);
    imagefill($land, 0, 0, (int) imagecolorallocate($land, 30, 120, 200));
    ob_start();
    imagejpeg($land, null, 90);
    $jpeg = (string) ob_get_clean();
    $tiff = "MM\x00\x2a\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
    $app1 = "Exif\x00\x00" . $tiff;
    $withOrientation = static function (string $jpeg, int $orientation): string {
        $tiff = "MM\x00\x2a\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    };
    file_put_contents($src, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));
    // Read without the exif extension too, so the rotation never depends on it.
    $check(PatronPhoto::jpegOrientation((string) file_get_contents($src)) === 6 && PatronPhoto::jpegOrientation($jpeg) === null && PatronPhoto::jpegOrientation('not a jpeg') === null, 'the EXIF Orientation is read from the APP1 block directly');
    $check(PatronPhoto::orientationTransform(5) === ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => 90] && PatronPhoto::orientationTransform(7) === ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => -90]
        && PatronPhoto::orientationTransform(2) === ['flip' => IMG_FLIP_HORIZONTAL, 'angle' => 0] && PatronPhoto::orientationTransform(4) === ['flip' => IMG_FLIP_VERTICAL, 'angle' => 0]
        && PatronPhoto::orientationTransform(1) === ['flip' => null, 'angle' => 0] && PatronPhoto::orientationTransform(null) === ['flip' => null, 'angle' => 0], 'every EXIF orientation maps to a mirror and a rotation');
    $check(PatronPhoto::store($db, $userId, $src) === null, 'a JPEG with EXIF orientation is accepted');
    $rotatedPath = PatronPhoto::absolutePath(PatronPhoto::storedFor($db, $userId));
    $photoFiles[] = $rotatedPath;
    $rotatedInfo = $rotatedPath !== null ? getimagesize($rotatedPath) : false;
    $check($rotatedInfo !== false && $rotatedInfo[1] > $rotatedInfo[0], 'EXIF Orientation 6 is applied: the stored photo is upright (portrait)');
    // Orientation 5 (transpose) and 7 (transverse) on a landscape image whose
    // left half is red: transposed the red half is on top, transversed at the bottom.
    $halves = imagecreatetruecolor(400, 200);
    imagefill($halves, 0, 0, (int) imagecolorallocate($halves, 20, 20, 220));
    imagefilledrectangle($halves, 0, 0, 199, 199, (int) imagecolorallocate($halves, 220, 20, 20));
    ob_start();
    imagejpeg($halves, null, 95);
    $halvesJpeg = (string) ob_get_clean();
    $isRed = static function (\GdImage $img, int $x, int $y): bool {
        $c = imagecolorat($img, $x, $y);
        return (($c >> 16) & 0xFF) > 150 && ($c & 0xFF) < 100;
    };
    foreach ([5 => true, 7 => false] as $orientation => $redOnTop) {
        file_put_contents($src, $withOrientation($halvesJpeg, $orientation));
        $ok = PatronPhoto::store($db, $userId, $src) === null;
        $out = PatronPhoto::absolutePath(PatronPhoto::storedFor($db, $userId));
        $photoFiles[] = $out;
        $img = $out !== null ? imagecreatefromjpeg($out) : false;
        $check($ok && $img !== false && imagesx($img) === 200 && imagesy($img) === 400 && $isRed($img, 100, 50) === $redOnTop && $isRed($img, 100, 350) === !$redOnTop, "EXIF Orientation {$orientation} is mirrored and rotated upright");
    }
    file_put_contents($src, "<?php echo 'x';");
    $check(PatronPhoto::store($db, $userId, $src) === 'invalid_image', 'a non-image is refused');
    $check(PatronPhoto::absolutePath('patron_photos/../../.env') === null && PatronPhoto::absolutePath('/etc/passwd') === null, 'a tampered reference never resolves');
    // A photo for an account that does not exist leaves no file behind.
    file_put_contents($src, $halvesJpeg);
    $filesBefore = count(glob(PatronPhoto::directory() . '/*.jpg') ?: []);
    $check(PatronPhoto::store($db, 2147483000, $src) === 'db' && count(glob(PatronPhoto::directory() . '/*.jpg') ?: []) === $filesBefore, 'a photo for a missing account is refused and its file removed');
    $current = PatronPhoto::absolutePath(PatronPhoto::storedFor($db, $userId));
    // A file that cannot be removed keeps its reference, so it is never orphaned.
    $dirMode = fileperms(PatronPhoto::directory()) & 0777;
    @chmod(PatronPhoto::directory(), 0555);
    clearstatcache();
    if (!is_writable(PatronPhoto::directory())) {
        $kept = PatronPhoto::delete($db, $userId) === false && PatronPhoto::storedFor($db, $userId) !== null && $current !== null && is_file($current);
        @chmod(PatronPhoto::directory(), $dirMode);
        $check($kept, 'a photo file that cannot be removed keeps its reference');
    } else {
        @chmod(PatronPhoto::directory(), $dirMode); // running as root: permissions do not apply
    }
    $check(PatronPhoto::delete($db, $userId) && PatronPhoto::storedFor($db, $userId) === null && $current !== null && !is_file($current), 'delete removes the reference and the file');
    @unlink($src);

    $check(\App\Support\ContentSecurityPolicy::permissionsPolicy() === 'geolocation=(), microphone=(), camera=(self)', 'the camera is allowed to the same origin only, nothing else is loosened');

    // Uploaded CSV copies (patrons' personal data) do not outlive their use.
    $sweepDir = sys_get_temp_dir() . '/pci_sweep_' . bin2hex(random_bytes(4));
    mkdir($sweepDir);
    $staleCopy = $sweepDir . '/user_import_' . str_repeat('a', 24) . '.csv';
    $freshCopy = $sweepDir . '/user_import_' . str_repeat('b', 24) . '.csv';
    $otherFile = $sweepDir . '/import_keep.csv';
    foreach ([$staleCopy, $freshCopy, $otherFile] as $f) {
        file_put_contents($f, 'x');
    }
    $now = time();
    touch($staleCopy, $now - UserImport::STALE_UPLOAD_SECONDS - 5);
    touch($otherFile, $now - 10 * UserImport::STALE_UPLOAD_SECONDS);
    $check(UserImport::sweepStaleUploads($sweepDir, UserImport::STALE_UPLOAD_SECONDS, $now) === 1, 'the sweep removes exactly one stale copy');
    $check(!is_file($staleCopy) && is_file($freshCopy) && is_file($otherFile), 'a recent copy and files of other imports are kept');
    foreach ([$freshCopy, $otherFile] as $f) {
        @unlink($f);
    }
    @rmdir($sweepDir);

    // The chunk endpoint: the position to resume from, a vanished staged file,
    // and an upload refused before it replaces the import in progress.
    $_SESSION = ['user' => ['id' => 1, 'tipo_utente' => 'admin', 'stato' => 'attivo']];
    $importer = new \App\Controllers\UserImportController();
    $requests = new \Slim\Psr7\Factory\ServerRequestFactory();
    $responses = new \Slim\Psr7\Factory\ResponseFactory();
    $streams = new \Slim\Psr7\Factory\StreamFactory();
    $call = static function (string $action, array $json) use ($importer, $requests, $responses, $streams, $db): array {
        $request = $requests->createServerRequest('POST', '/admin/users/import/' . $action)->withBody($streams->createStream((string) json_encode($json)));
        $response = $importer->{$action}($request, $responses->createResponse(), $db);
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    };
    $uploads = dirname(__DIR__) . '/writable/uploads';
    if (!is_dir($uploads)) {
        mkdir($uploads, 0755, true);
    }
    $staged = $uploads . '/user_import_' . bin2hex(random_bytes(12)) . '.csv';
    file_put_contents($staged, "nome;cognome;email\nZz;Import;zz-import@example.invalid\n");
    $running = ['file' => $staged, 'name' => 't.csv', 'delimiter' => ';', 'total' => 1, 'columns' => 3, 'started' => true, 'next' => 0,
        'mapping' => [0 => 'nome', 1 => 'cognome', 2 => 'email'], 'send_invites' => false, 'created' => 0, 'duplicates' => 0, 'failed' => 0,
        'invited' => 0, 'invite_failed' => 0, 'errors' => [], 'seen_emails' => [], 'seen_codes' => []];
    $_SESSION['user_import'] = $running;
    [$status, $json] = $call('chunk', ['start' => 10]);
    $check($status === 409 && ($json['processed'] ?? null) === 0 && ($_SESSION['user_import']['next'] ?? null) === 0, 'a block out of sequence is refused with the position to resume from');
    [$status] = $call('upload', []);
    $check($status === 400 && is_file($staged) && ($_SESSION['user_import'] ?? null) === $running, 'a refused upload leaves the staged file and the import in progress intact');
    unlink($staged);
    [$status, $json] = $call('chunk', ['start' => 0]);
    $check($status === 409 && !isset($json['processed']) && !is_file($staged) && !isset($_SESSION['user_import']), 'a vanished staged file ends the import with 409 instead of being recreated empty');
    $_SESSION = [];

    // The user import's error report is served under the users permission.
    $check(\App\Support\Permissions::permissionForPath('/admin/users/import/errors') === 'users.manage', 'the user import error report needs users.manage, not catalog.manage');

    echo "SUCCESS {$checks} checks\n";
} finally {
    $db->query("DROP TABLE IF EXISTS {$types}");
    $db->query("DROP TABLE IF EXISTS {$users}");
    if ($userId > 0) {
        $db->query("DELETE FROM utenti WHERE id = {$userId}");
    }
    foreach ($photoFiles as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
}
