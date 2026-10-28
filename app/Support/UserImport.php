<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Pure logic of the user CSV import: which columns exist, how a header is
 * recognised, how one row becomes a valid account record.
 *
 * Nothing here touches the database, so it is unit-tested directly
 * (tests/user-import.unit.php); UserImportController does the I/O.
 *
 * Passwords are never imported: there is no target field for them, and a
 * column called "password" is never recognised. Imported readers choose their
 * password through the setup invitation (PasswordSetupToken).
 */
final class UserImport
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const CHUNK_SIZE = 10;
    public const PREVIEW_ROWS = 5;
    /**
     * An uploaded CSV holds patrons' personal data. One that no import has
     * read for this long (the tab was closed, the session expired, the
     * preview was abandoned) is deleted by the next visit to the import page.
     */
    public const STALE_UPLOAD_SECONDS = 3600;

    /** Field key => [max length or 0, required]. */
    private const FIELDS = [
        'nome' => [100, true],
        'cognome' => [100, false],
        'email' => [255, true],
        'telefono' => [20, false],
        'indirizzo' => [500, false],
        'data_nascita' => [0, false],
        'sesso' => [0, false],
        'cod_fiscale' => [16, false],
        'codice_tessera' => [20, false],
        'data_scadenza_tessera' => [0, false],
        'note_utente' => [2000, false],
        'locale' => [0, false],
        'tipologia' => [40, false],
    ];

    /** Header spellings recognised for each field (normalised with normalizeHeader()). */
    private const ALIASES = [
        'nome' => ['nome', 'name', 'firstname', 'first', 'givenname', 'vorname', 'prenom', 'fornavn'],
        'cognome' => ['cognome', 'surname', 'lastname', 'last', 'familyname', 'nachname', 'nom', 'nomdefamille', 'efternavn'],
        'email' => ['email', 'mail', 'emailaddress', 'indirizzoemail', 'posta', 'courriel', 'adressemail'],
        'telefono' => ['telefono', 'phone', 'tel', 'telephone', 'telefon', 'cellulare', 'mobile', 'telefonnummer'],
        'indirizzo' => ['indirizzo', 'address', 'adresse', 'indirizzocompleto'],
        'data_nascita' => ['datanascita', 'datadinascita', 'birthdate', 'dateofbirth', 'birthday', 'geburtsdatum', 'datedenaissance', 'fodselsdato'],
        'sesso' => ['sesso', 'gender', 'sex', 'geschlecht', 'sexe', 'kon'],
        'cod_fiscale' => ['codfiscale', 'codicefiscale', 'cf', 'taxcode', 'fiscalcode', 'steuernummer', 'codefiscal'],
        'codice_tessera' => ['codicetessera', 'tessera', 'card', 'cardnumber', 'cardcode', 'librarycard', 'barcode', 'ausweisnummer', 'ausweis', 'numerodecarte', 'kortnummer', 'lanerkort'],
        'data_scadenza_tessera' => ['datascadenzatessera', 'scadenzatessera', 'scadenza', 'expiry', 'expirydate', 'cardexpiry', 'expires', 'ablaufdatum', 'gultigbis', 'datedexpiration', 'udlobsdato'],
        'note_utente' => ['noteutente', 'note', 'notes', 'notizen', 'noter', 'noteinterne'],
        'locale' => ['locale', 'lingua', 'language', 'sprache', 'langue', 'sprog'],
        'tipologia' => ['tipologia', 'tipologialettore', 'patrontype', 'membertype', 'readertype', 'lesertyp', 'leserkategorie', 'typedelecteur', 'categoriedelecteur', 'lanertype', 'lanerkategori'],
    ];

    /**
     * Header spellings that name the patron type CODE column of the users CSV
     * export ("Codice tipologia lettore" in the five languages). They map to
     * `tipologia` like the name column, but guessMapping() prefers them: an
     * export carries both columns and must re-import by code, which is exact
     * even when two types share a name.
     */
    private const PREFERRED_ALIASES = [
        'tipologia' => ['codicetipologia', 'codicetipologialettore', 'tipologiacodice', 'tipologialettorecodice', 'patrontypecode', 'typecode', 'membertypecode', 'readertypecode', 'lesertypcode', 'codederleserkategorie', 'leserkategoriecode', 'codetypedelecteur', 'codedelacategoriedelecteur', 'lanertypekode', 'lanerkategorikode', 'kodeforlanerkategori'],
    ];

    /**
     * Target fields shown in the mapping step, in order, as key => label.
     * Custom registration fields follow as `custom:<id>`; `tipologia` is offered
     * only when the patron types exist (PatronTypeImport::available()).
     *
     * @param list<array{id:int, etichetta:string, tipo:string, obbligatorio:bool}> $customDefinitions
     * @return array<string,string>
     */
    public static function targetFields(array $customDefinitions, bool $withPatronType): array
    {
        $fields = [
            'nome' => __('Nome'),
            'cognome' => __('Cognome'),
            'email' => __('Email'),
            'telefono' => __('Telefono'),
            'indirizzo' => __('Indirizzo'),
            'data_nascita' => __('Data di nascita'),
            'sesso' => __('Sesso'),
            'cod_fiscale' => __('Codice Fiscale'),
            'codice_tessera' => __('Codice tessera'),
            'data_scadenza_tessera' => __('Scadenza tessera'),
            'note_utente' => __('Note interne'),
            'locale' => __('Lingua dell\'interfaccia'),
        ];
        if ($withPatronType) {
            $fields['tipologia'] = __('Tipologia lettore (codice o nome)');
        }
        foreach ($customDefinitions as $def) {
            $fields['custom:' . $def['id']] = $def['etichetta'];
        }
        return $fields;
    }

    /**
     * Accented letters of the five languages' headers and gender words, to
     * their ASCII base. iconv's ASCII//TRANSLIT depends on LC_CTYPE (under
     * the "C" locale glibc gives "?", musl gives nothing) and would turn
     * "Prénom" into "prnom"; this table is applied first, iconv only mops up.
     */
    private const TRANSLIT = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ß' => 'ss',
        'À' => 'a', 'Á' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a', 'Æ' => 'ae',
        'Ç' => 'c',
        'È' => 'e', 'É' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'Ì' => 'i', 'Í' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'Ñ' => 'n',
        'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Õ' => 'o', 'Ø' => 'o', 'Œ' => 'oe',
        'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'Ý' => 'y', 'Ÿ' => 'y',
    ];

    /** Lowercase, accents removed, only letters and digits. */
    public static function normalizeHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtr(mb_strtolower(trim($header), 'UTF-8'), self::TRANSLIT);
        if (!preg_match('/^[\x00-\x7F]*$/', $header)) {
            // Letters outside the table (a Polish ł, a Czech č): iconv's
            // locale-dependent best effort, or nothing — never a wrong match.
            $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header) : false;
            if (is_string($ascii) && $ascii !== '') {
                $header = strtolower($ascii);
            }
        }
        return (string) preg_replace('/[^a-z0-9]/', '', $header);
    }

    /**
     * Suggested mapping: column index => field key ('' = ignore). Each field is
     * proposed for one column at most: the first that matches, except that a
     * PREFERRED_ALIASES spelling wins over any other wherever it stands (the
     * users export has the patron type name and then its code; the code is
     * the column that re-imports exactly).
     *
     * @param list<string> $headers
     * @param array<string,string> $targetFields from targetFields()
     * @return array<int,string>
     */
    public static function guessMapping(array $headers, array $targetFields): array
    {
        $byAlias = [];
        foreach (self::ALIASES as $field => $aliases) {
            if (!isset($targetFields[$field])) {
                continue;
            }
            foreach ($aliases as $alias) {
                $byAlias[$alias] ??= $field;
            }
            // The label in the current language too (an export from this
            // installation carries localized headers).
            $byAlias[self::normalizeHeader($targetFields[$field])] ??= $field;
        }
        $preferred = [];
        foreach (self::PREFERRED_ALIASES as $field => $aliases) {
            if (!isset($targetFields[$field])) {
                continue;
            }
            foreach ($aliases as $alias) {
                $byAlias[$alias] ??= $field;
                if ($byAlias[$alias] === $field) {
                    $preferred[$alias] = true;
                }
            }
        }
        foreach ($targetFields as $key => $label) {
            if (str_starts_with($key, 'custom:')) {
                $byAlias[self::normalizeHeader($label)] ??= $key;
            }
        }
        $mapping = array_fill_keys(array_keys($headers), '');
        $taken = [];
        // Two passes: the preferred spellings take their field first, then
        // the other headers take what is left, in file order.
        foreach ([true, false] as $preferredPass) {
            foreach ($headers as $i => $header) {
                $norm = self::normalizeHeader((string) $header);
                if (isset($preferred[$norm]) !== $preferredPass) {
                    continue;
                }
                $field = $byAlias[$norm] ?? '';
                if ($field !== '' && !isset($taken[$field])) {
                    $mapping[$i] = $field;
                    $taken[$field] = true;
                }
            }
        }
        return $mapping;
    }

    /**
     * Validate a submitted mapping. Returns the clean mapping, or an error
     * message when a field is mapped twice or nome/email are missing.
     *
     * @param mixed $mapping column index => field key, as sent by the browser
     * @param array<string,string> $targetFields
     * @return array{mapping: array<int,string>, error: ?string}
     */
    public static function validateMapping(mixed $mapping, array $targetFields, int $columnCount): array
    {
        if (!is_array($mapping)) {
            return ['mapping' => [], 'error' => __('Mappatura delle colonne non valida.')];
        }
        $clean = [];
        $used = [];
        foreach ($mapping as $index => $field) {
            $index = (int) $index;
            $field = is_string($field) ? $field : '';
            if ($index < 0 || $index >= $columnCount || $field === '') {
                continue;
            }
            if (!isset($targetFields[$field])) {
                return ['mapping' => [], 'error' => __('Mappatura delle colonne non valida.')];
            }
            if (isset($used[$field])) {
                return ['mapping' => [], 'error' => sprintf(__('Il campo "%s" è associato a più di una colonna.'), $targetFields[$field])];
            }
            $used[$field] = true;
            $clean[$index] = $field;
        }
        foreach (['nome', 'email'] as $required) {
            if (!isset($used[$required])) {
                return ['mapping' => [], 'error' => sprintf(__('Associa una colonna al campo obbligatorio "%s".'), $targetFields[$required])];
            }
        }
        ksort($clean);
        return ['mapping' => $clean, 'error' => null];
    }

    /**
     * Turn one CSV row into an account record.
     *
     * @param list<string|null> $row raw cells
     * @param array<int,string> $mapping column index => field key
     * @param list<array{id:int, etichetta:string, tipo:string, obbligatorio:bool}> $customDefinitions
     * @param list<string> $locales valid interface locale codes
     * @param array{sesso:bool, cod_fiscale:bool} $visible whether the optional profile fields are collected
     * @return array{record: array<string,?string>, custom: array<int,string>, tipologia: ?string, error: ?string}
     */
    public static function normalizeRow(array $row, array $mapping, array $customDefinitions, array $locales, array $visible = ['sesso' => true, 'cod_fiscale' => true]): array
    {
        $fail = static fn (string $message): array => ['record' => [], 'custom' => [], 'tipologia' => null, 'error' => $message];
        $raw = [];
        $customRaw = [];
        foreach ($mapping as $index => $field) {
            $value = self::cell($row[$index] ?? null);
            if (str_starts_with($field, 'custom:')) {
                $customRaw[(int) substr($field, 7)] = $value;
            } else {
                $raw[$field] = $value;
            }
        }

        $record = [];
        foreach (self::FIELDS as $field => [$max]) {
            $value = $raw[$field] ?? '';
            if ($max > 0 && mb_strlen($value, 'UTF-8') > $max) {
                return $fail(sprintf(__('Il valore di "%s" è troppo lungo (massimo %d caratteri).'), $field, $max));
            }
            $record[$field] = $value === '' ? null : $value;
        }

        if ($record['nome'] === null) {
            return $fail(__('Nome mancante.'));
        }
        if ($record['email'] === null) {
            return $fail(__('Email mancante.'));
        }
        $email = mb_strtolower((string) $record['email'], 'UTF-8');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $fail(sprintf(__('Email non valida: %s'), (string) $record['email']));
        }
        $record['email'] = $email;
        $record['cognome'] ??= '';

        foreach (['data_nascita', 'data_scadenza_tessera'] as $dateField) {
            if ($record[$dateField] !== null) {
                $date = self::parseDate($record[$dateField]);
                if ($date === null) {
                    return $fail(sprintf(__('Data non valida: %s'), $record[$dateField]));
                }
                $record[$dateField] = $date;
            }
        }

        $record['sesso'] = $visible['sesso'] ? self::normalizeGender($record['sesso']) : null;
        $record['cod_fiscale'] = $visible['cod_fiscale'] && $record['cod_fiscale'] !== null ? strtoupper($record['cod_fiscale']) : null;

        if ($record['locale'] !== null) {
            $code = str_replace('-', '_', $record['locale']);
            $match = null;
            foreach ($locales as $locale) {
                if (strcasecmp($locale, $code) === 0 || strcasecmp(substr($locale, 0, 2), $code) === 0) {
                    $match = $locale;
                    break;
                }
            }
            $record['locale'] = $match; // an unknown language falls back to the installation one
        }

        $tipologia = $record['tipologia'];
        unset($record['tipologia']);

        $custom = [];
        if ($customDefinitions !== [] && $customRaw !== []) {
            $mapped = array_values(array_filter($customDefinitions, static fn (array $d): bool => array_key_exists($d['id'], $customRaw)));
            $result = RegistrationFields::validate($mapped, ['custom_field' => $customRaw], false);
            if ($result['error'] !== null) {
                return $fail(sprintf(__('Valore non valido per il campo "%s".'), $result['error']));
            }
            $custom = array_filter($result['values'], static fn (string $v): bool => $v !== '');
        }

        return ['record' => $record, 'custom' => $custom, 'tipologia' => $tipologia, 'error' => null];
    }

    /** Y-m-d from the spellings a spreadsheet produces, or null. */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        $formats = ['Y-m-d', 'd/m/Y', 'd.m.Y', 'd-m-Y', 'Y/m/d', 'd/m/y', 'd.m.y'];
        foreach ($formats as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date !== false && $date->format($format) === $value) {
                $year = (int) $date->format('Y');
                if ($year >= 1900 && $year <= 2200) {
                    return $date->format('Y-m-d');
                }
            }
        }
        return null;
    }

    public static function normalizeGender(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = self::normalizeHeader($value);
        return match (true) {
            in_array($v, ['m', 'maschio', 'male', 'man', 'uomo', 'mannlich', 'homme', 'masculin', 'mand'], true) => 'M',
            in_array($v, ['f', 'femmina', 'female', 'woman', 'donna', 'w', 'weiblich', 'femme', 'feminin', 'kvinde', 'k'], true) => 'F',
            in_array($v, ['altro', 'other', 'x', 'divers', 'autre', 'andet', 'nonbinary', 'nb'], true) => 'Altro',
            default => null,
        };
    }

    /**
     * The uploaded bytes as UTF-8. A BOM is dropped. A file that is not valid
     * UTF-8 — a spreadsheet saved as "CSV" on Windows writes the system code
     * page — is read as Windows-1252, the superset of ISO-8859-1 such exports
     * fall under; "Caffè" then keeps its accent instead of breaking the JSON
     * preview. Whatever comes back is valid UTF-8.
     *
     * @return array{text: string, converted: bool}
     */
    public static function toUtf8(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return ['text' => $bytes, 'converted' => false];
        }
        $text = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($bytes, 'UTF-8');
        }
        return ['text' => $text, 'converted' => true];
    }

    /**
     * Delimiter of a CSV sample (the header and the first few lines): the one
     * of ";", "," and TAB that splits the lines into columns most consistently,
     * or null when none splits the header at all.
     *
     * Each candidate is judged by the structure it yields, not by how often it
     * occurs: a ";" file whose addresses hold commas ("Via Roma 1, int. 3,
     * 00100 Roma") has more commas than semicolons, yet only ";" gives every
     * line the header's column count. Quoted fields are respected as the
     * reader respects them (enclosure '"', no escape character), so a comma
     * inside "…" never counts. The candidate whose column count is shared by
     * the largest share of the data lines wins; the wider one on a tie.
     *
     * @param list<string|false> $lines
     */
    public static function detectDelimiter(array $lines): ?string
    {
        $sample = [];
        foreach ($lines as $line) {
            if (is_string($line) && trim($line) !== '') {
                $sample[] = rtrim($line, "\r\n");
            }
        }
        if ($sample === []) {
            return null;
        }
        $header = array_shift($sample);
        $best = null;
        $bestScore = [0.0, 1];
        foreach ([';', ',', "\t"] as $delimiter) {
            $columns = count(str_getcsv($header, $delimiter, '"', ''));
            if ($columns < 2) {
                continue;
            }
            $matching = 0;
            foreach ($sample as $line) {
                if (count(str_getcsv($line, $delimiter, '"', '')) === $columns) {
                    $matching++;
                }
            }
            $score = [$sample === [] ? 1.0 : $matching / count($sample), $columns];
            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /** A CSV cell as trimmed text, with the export's formula-escape apostrophe undone. */
    private static function cell(mixed $value): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        if (strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@'], true)) {
            $value = substr($value, 1);
        }
        return strip_tags($value);
    }

    /**
     * Delete user_import_*.csv files in $dir not modified for $maxAge seconds.
     *
     * @return int number of files removed
     */
    public static function sweepStaleUploads(string $dir, int $maxAge = self::STALE_UPLOAD_SECONDS, ?int $now = null): int
    {
        $now ??= time();
        $removed = 0;
        foreach (glob(rtrim($dir, '/') . '/user_import_*.csv') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $now - $mtime > $maxAge && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }
}
