<?php

declare(strict_types=1);

namespace App\Plugins\Emeroteca\Services;

require_once __DIR__ . '/../Support/IssnHelper.php';
use App\Plugins\Emeroteca\Support\IssnHelper;

/** Independent articles own their citation and survive removal of their host. */
final class ContributionService
{
    public const COLUMN_DEFINITIONS = [
        'id' => "INT NOT NULL AUTO_INCREMENT PRIMARY KEY",
        'reference_key' => "VARCHAR(191) NOT NULL",
        'titolo' => "VARCHAR(500) NOT NULL",
        'autori' => "VARCHAR(500) NULL",
        'tipo_contributo' => "VARCHAR(30) NOT NULL DEFAULT 'articolo'",
        'contenitore_tipo' => "VARCHAR(30) NULL",
        'contenitore_titolo' => "VARCHAR(255) NULL",
        'issn' => "VARCHAR(9) NULL",
        'data_pubblicazione_testo' => "VARCHAR(100) NULL",
        'anno_pubblicazione' => "SMALLINT UNSIGNED NULL",
        'volume' => "VARCHAR(50) NULL",
        'numero' => "VARCHAR(50) NULL",
        'pagine' => "VARCHAR(100) NULL",
        'doi' => "VARCHAR(255) NULL",
        'supporto' => "VARCHAR(20) NOT NULL DEFAULT 'cartaceo'",
        'keywords' => "VARCHAR(500) NULL",
        'abstract' => "TEXT NULL",
        'collocazione' => "VARCHAR(255) NULL",
        'note_private' => "TEXT NULL",
        'testata_id' => "INT NULL",
        'fascicolo_id' => "INT NULL",
        'pubblico' => "TINYINT(1) NOT NULL DEFAULT 0",
        'pdf_path' => "VARCHAR(500) NULL",
        'pdf_nome_originale' => "VARCHAR(255) NULL",
        'pdf_dimensione' => "BIGINT UNSIGNED NULL",
        'pdf_pubblico' => "TINYINT(1) NOT NULL DEFAULT 0",
        // 1.6.0 — the article's own image, so a result list of articles is not
        // a wall of text next to a catalogue of covers. Same managed-uploads
        // path as the issue and masthead images (/uploads/emeroteca/…), never
        // written from the form body: the controller sets it after validating
        // the file. NULL is the norm, and the views draw a placeholder.
        'copertina_url' => "VARCHAR(500) NULL",
        'revision' => "INT UNSIGNED NOT NULL DEFAULT 1",
        'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        // 1.7.0 — what turns a citation into an analytic (component-part)
        // record: the article's own subtitle, the 008 language and country of
        // the host publication, a classification carried WITH its scheme, the
        // holdings note that says the library owns a copy and not the run,
        // and the danMARC2 856 triple (address, link text, access conditions).
        //
        // Appended after updated_at, and with no AFTER clause, on purpose:
        // these same fragments are interpolated into CREATE TABLE by ddl(),
        // where AFTER is a syntax error, and an ALTER without AFTER appends —
        // so a fresh install and an upgraded one end with one column order.
        'sottotitolo' => "VARCHAR(500) NULL",
        'lingua' => "VARCHAR(10) NULL",
        'paese' => "VARCHAR(2) NULL",
        'classificazione_schema' => "VARCHAR(20) NULL",
        'classificazione' => "VARCHAR(100) NULL",
        'nota_possesso' => "VARCHAR(255) NULL",
        'risorsa_url' => "VARCHAR(500) NULL",
        'risorsa_testo' => "VARCHAR(255) NULL",
        'risorsa_accesso' => "VARCHAR(255) NULL",
        'risorsa_pubblica' => "TINYINT(1) NOT NULL DEFAULT 0",
        // 1.10.0 — a chapter in an anthology (#412): the host is a book, and a
        // chapter citation names its editors, publisher and place. Appended
        // after risorsa_pubblica for the same reason as the 1.7 block.
        'contenitore_curatori' => "VARCHAR(500) NULL",
        'contenitore_editore' => "VARCHAR(255) NULL",
        'contenitore_luogo' => "VARCHAR(255) NULL",
        'isbn' => "VARCHAR(17) NULL",
        // 1.12.0 — the genre, from the same tree as the books' (#455), so an
        // article is found under a genre in the catalogue next to the books
        // filed there. NULL is the norm; the FK (ON DELETE SET NULL) is added
        // by EmerotecaPlugin::ensureContributionForeignKeys().
        'genere_id' => "INT NULL",
    ];
    /**
     * The article form's "Other scheme" choice: the scheme's name is then
     * typed into classificazione_schema_altro and stored in its place.
     */
    public const OTHER_SCHEME = '__altro';
    public const TEXT_FIELDS = ['titolo' => 500,'autori' => 500,'tipo_contributo' => 30,'contenitore_tipo' => 30,
        'contenitore_titolo' => 255,'issn' => 9,'data_pubblicazione_testo' => 100,'volume' => 50,'numero' => 50,
        'pagine' => 100,'doi' => 255,'supporto' => 20,'keywords' => 500,'abstract' => 10000,'collocazione' => 255,'note_private' => 10000,
        // 1.7.0 — appended, never spliced: tests/emeroteca-412.unit.php asserts
        // the physical column order from this map's key order.
        'sottotitolo' => 500,'lingua' => 10,'paese' => 2,'classificazione_schema' => 20,
        'classificazione' => 100,'nota_possesso' => 255,'risorsa_url' => 500,
        'risorsa_testo' => 255,'risorsa_accesso' => 255,
        // 1.10.0 — the host volume of an anthology chapter.
        'contenitore_curatori' => 500,'contenitore_editore' => 255,'contenitore_luogo' => 255,'isbn' => 17];
    /** A reference_key the table accepts: shared by save() and the CSV preview. */
    public const REFERENCE_KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/D';

    public const CSV_FIELDS = ['reference_key','titolo','sottotitolo','autori','tipo_contributo','contenitore_tipo','contenitore_titolo',
        'issn','data_pubblicazione_testo','anno_pubblicazione','volume','numero','pagine','doi','supporto','keywords','abstract',
        'lingua','paese','classificazione_schema','classificazione','nota_possesso',
        'risorsa_url','risorsa_testo','risorsa_accesso','risorsa_pubblica','collocazione','note_private','pubblico',
        'contenitore_curatori','contenitore_editore','contenitore_luogo','isbn'];

    /**
     * The header the template and the export carry.
     *
     * record_type is not a column of emeroteca_contributi — the importer reads
     * it, derives contenitore_tipo from it and discards it. It is emitted all
     * the same because it is the only thing that tells the BOOK importer this
     * file is not a book: without it the guard reads nothing, accepts the file
     * and the articles land in the catalogue as monographs. A template that
     * cannot be refused by the importer it must never reach is not a template.
     */
    public const CSV_HEADER = ['record_type', ...self::CSV_FIELDS];
    private int $affectedRows = 0;
    /** @param \mysqli $db connection used for every query this service issues */
    public function __construct(private \mysqli $db)
    {
    }

    /**
     * CREATE TABLE IF NOT EXISTS for emeroteca_contributi, built from COLUMN_DEFINITIONS plus
     * its indexes and FKs. Idempotent, and the single source both the fresh-install schema and
     * the self-heal path consume.
     */
    public static function ddl(): string
    {
        $columns = [];
        foreach (self::COLUMN_DEFINITIONS as $name => $definition) {
            $columns[] = "$name $definition";
        }
        return "CREATE TABLE IF NOT EXISTS emeroteca_contributi (\n" . implode(",\n", $columns) . ",\n" . <<<'SQL'
 UNIQUE KEY uq_contributo_reference (reference_key),
 KEY idx_contributo_doi (doi), KEY idx_contributo_testata (testata_id), KEY idx_contributo_fascicolo (fascicolo_id),
 KEY idx_contributo_pubblico (pubblico, id),
 FULLTEXT KEY ft_contributo (titolo, autori, keywords, contenitore_titolo),
 CONSTRAINT fk_contributo_testata FOREIGN KEY (testata_id) REFERENCES emeroteca_testate(id) ON DELETE SET NULL,
 CONSTRAINT fk_contributo_fascicolo FOREIGN KEY (fascicolo_id) REFERENCES emeroteca_fascicoli(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    }

    public static function authorsDdl(): string
    {
        return <<<'SQL'
CREATE TABLE IF NOT EXISTS emeroteca_contributi_autori (
 contributo_id INT NOT NULL,
 ordine_credito SMALLINT UNSIGNED NOT NULL,
 autore_id INT NULL,
 nome_credito VARCHAR(255) NOT NULL,
 ruolo VARCHAR(20) NOT NULL DEFAULT 'co-autore',
 PRIMARY KEY (contributo_id, ordine_credito),
 UNIQUE KEY uq_contributo_autore (contributo_id, autore_id),
 KEY idx_contributo_autore (autore_id, contributo_id),
 CONSTRAINT fk_contributo_autori_record FOREIGN KEY (contributo_id) REFERENCES emeroteca_contributi(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException('Contribution query could not be prepared');
        }
        if ($params) {
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        }
        if (!$stmt->execute()) {
            throw new \RuntimeException('Contribution query failed');
        }
        $this->affectedRows = $stmt->affected_rows;
        $result = $stmt->get_result();
        $rows = $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        if ($this->affectedRows > 0 && preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $sql) === 1) {
            // Articles now participate in the main catalogue. Defer until the
            // caller's transaction finishes, including CSV batches and deletes.
            \App\Support\ContentCache::deferBooksChanged();
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public function hydrateAuthors(array $rows): array
    {
        return (new \App\Services\ArticleAuthorService($this->db))->hydrate($rows);
    }

    /**
     * Fetch one contribution by id, or null if it doesn't exist. With $publicOnly, also
     * requires pubblico=1 — used by every public-facing lookup so an unpublished article is
     * never returned outside the admin.
     */
    public function get(int $id, bool $publicOnly = false): ?array
    {
        // The placement (masthead, issue, year) travels with the row: the page
        // builds its breadcrumb and "In {testata}, n. X" line from it, and
        // coverUrl() reads the issue cover and the masthead logo from it.
        // LEFT JOINs: testata_id and fascicolo_id are both nullable — a
        // standalone article need not belong to anything — and an inner join
        // would make those articles vanish from their own page.
        $rows = $this->rows(
            'SELECT c.*, ' . self::PLACEMENT_COLUMNS . ' FROM emeroteca_contributi c'
            . self::PLACEMENT_JOINS
            . ' WHERE c.id = ?' . ($publicOnly ? ' AND c.pubblico = 1' : ''),
            [$id]
        );
        return $this->hydrateAuthors($rows)[0] ?? null;
    }

    /** The plugin's workflow mode ('simple' or 'complete'), defaulting to 'complete' when unset. */
    public function mode(): string
    {
        $row = $this->rows("SELECT s.setting_value FROM plugin_settings s JOIN plugins p ON p.id=s.plugin_id WHERE p.name='emeroteca' AND s.setting_key='mode'")[0] ?? [];
        return ($row['setting_value'] ?? '') === 'simple' ? 'simple' : 'complete';
    }

    /**
     * @throws \InvalidArgumentException if $mode is neither 'simple' nor 'complete'
     */
    public function setMode(string $mode): void
    {
        if (!in_array($mode, ['simple','complete'], true)) {
            throw new \InvalidArgumentException(__('Modalità non valida.'));
        }
        $this->rows("INSERT INTO plugin_settings (plugin_id,setting_key,setting_value) SELECT id,'mode',? FROM plugins WHERE name='emeroteca' ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$mode]);
    }

    /**
     * Validate and coerce raw form/import input into the shape save() persists: trims every
     * TEXT_FIELDS value and rejects any over its length limit, requires a non-empty titolo, checks tipo_contributo/
     * supporto/contenitore_tipo against their allowed enums, validates and normalizes ISSN
     * (checksum) and DOI (strips URL/prefix, requires the 10.xxxx/ shape), validates the year
     * range, lowercases the ISO 639 language code (two or three letters) and uppercases the ISO 3166
     * alpha-2 country code and rejects any other shape, refuses a line break in the holdings note and
     * the 856 triple (risorsa_url/risorsa_testo/risorsa_accesso), requires an http(s) risorsa_url to be
     * a valid URL (any other reference is kept verbatim as opaque text), and coerces the
     * pubblico/pdf_pubblico/risorsa_pubblica flags to 0/1 — risorsa_pubblica is forced to 0 when there
     * is no risorsa_url, because a public switch with nothing to publish would be inert.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException on the first field that fails validation
     */
    public static function normalize(array $input): array
    {
        if (($input['classificazione_schema'] ?? null) === self::OTHER_SCHEME) {
            $input['classificazione_schema'] = $input['classificazione_schema_altro'] ?? '';
        }
        $out = [];
        foreach (self::TEXT_FIELDS as $key => $max) {
            if (isset($input[$key]) && !is_scalar($input[$key])) {
                throw new \InvalidArgumentException(__('Valore non valido.') . ' ' . $key);
            }
            $v = trim((string) ($input[$key] ?? ''));
            if (mb_strlen($v) > $max) {
                throw new \InvalidArgumentException(__('Valore troppo lungo.') . ' ' . $key);
            }
            $out[$key] = $v === '' ? null : $v;
        }
        if ($out['titolo'] === null) {
            throw new \InvalidArgumentException(__('Il titolo è obbligatorio.'));
        }
        $out['tipo_contributo'] ??= 'articolo';
        $out['supporto'] ??= 'cartaceo';
        if (!in_array($out['tipo_contributo'], ['articolo','editoriale','recensione','intervista','dossier','rubrica'], true)
            || !in_array($out['supporto'], ['cartaceo','digitale','entrambi'], true)
            || ($out['contenitore_tipo'] !== null && !in_array($out['contenitore_tipo'], ['rivista','giornale','magazine','bollettino','fanzine','antologia'], true))) {
            throw new \InvalidArgumentException(__('Tipo non valido.'));
        }
        // Editors, publisher, place and ISBN describe the volume an anthology
        // chapter sits in. For any other container they mean nothing: the form
        // hides them, and a value left over from an anthology draft must not
        // be stored, shown publicly, or block the save with an invalid ISBN
        // the cataloguer can no longer see.
        if ($out['contenitore_tipo'] !== 'antologia') {
            foreach (['contenitore_curatori', 'contenitore_editore', 'contenitore_luogo', 'isbn'] as $hostField) {
                $out[$hostField] = null;
            }
        }
        if ($out['issn'] !== null) {
            if (!IssnHelper::isValidChecksum($out['issn'])) {
                throw new \InvalidArgumentException(__('ISSN non valido.'));
            }
            $out['issn'] = IssnHelper::normalize($out['issn']);
        }
        if ($out['isbn'] !== null) {
            $isbn = \App\Support\IsbnFormatter::clean($out['isbn']);
            if (!\App\Support\IsbnFormatter::isValid($isbn)) {
                throw new \InvalidArgumentException(__('ISBN non valido.'));
            }
            $out['isbn'] = $isbn;
        }
        if ($out['doi'] !== null) {
            $out['doi'] = strtolower(preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', $out['doi']) ?? '');
            if (!preg_match('~^10\.\d{4,9}/\S+$~', $out['doi'])) {
                throw new \InvalidArgumentException(__('DOI non valido.'));
            }
        }
        $year = $input['anno_pubblicazione'] ?? '';
        if (!is_scalar($year)) {
            throw new \InvalidArgumentException(__('Anno non valido.'));
        }
        $year = trim((string) $year);
        if ($year !== '' && (!ctype_digit($year) || (int)$year < 1 || (int)$year > 9999)) {
            throw new \InvalidArgumentException(__('Anno non valido.'));
        }
        $out['anno_pubblicazione'] = $year === '' ? null : (int) $year;

        // 008 language and country are stored as CODES, never as names. The
        // application is multilingual per user: "Dansk" typed into a box reads
        // as "Dansk" to an Italian reader, while `dan` can be rendered as
        // "danese" to them and "Danish" to someone else. Two or three letters
        // covers ISO 639-1 and 639-2 without making the librarian care which.
        // The stored code stays as entered (ICU and schema.org inLanguage read
        // it); ArticleMarcXml maps it to the MARC language code at export.
        if ($out['lingua'] !== null) {
            $out['lingua'] = strtolower($out['lingua']);
            if (preg_match('/^[a-z]{2,3}$/D', $out['lingua']) !== 1) {
                throw new \InvalidArgumentException(__('Codice lingua non valido.'));
            }
        }
        if ($out['paese'] !== null) {
            $out['paese'] = strtoupper($out['paese']);
            if (preg_match('/^[A-Z]{2}$/D', $out['paese']) !== 1) {
                throw new \InvalidArgumentException(__('Codice paese non valido.'));
            }
        }

        // The 856 triple. A line break inside any of the three would split a
        // RIS record in two at export time, so it is refused at the door
        // rather than escaped at every point of use.
        foreach (['nota_possesso','risorsa_url','risorsa_testo','risorsa_accesso'] as $key) {
            if ($out[$key] !== null && preg_match('/[\r\n]/', $out[$key]) === 1) {
                throw new \InvalidArgumentException(__('Il valore non può contenere a capo.'));
            }
        }
        // An http(s) address must be a real one, because it becomes an href.
        // Anything else — a UNC share, a file: URI, an identifier in a
        // document management system — is accepted verbatim as an opaque
        // reference and is NEVER rendered as a link. That asymmetry is what
        // makes accepting the opaque form safe.
        if ($out['risorsa_url'] !== null
            && preg_match('~^https?://~i', $out['risorsa_url']) === 1
            && filter_var($out['risorsa_url'], FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(__('Indirizzo della risorsa non valido.'));
        }

        foreach (['pubblico','pdf_pubblico','risorsa_pubblica'] as $key) {
            if (!in_array($input[$key] ?? 0, [0,1,'0','1',null,''], true)) {
                throw new \InvalidArgumentException(__('Visibilità non valida.'));
            }
            $out[$key] = (int) ($input[$key] ?? 0);
        }
        if ($out['risorsa_url'] === null) {
            // Nothing to publish: an inert "public" flag would silently turn
            // on whatever address is typed in later.
            $out['risorsa_pubblica'] = 0;
        }
        return $out;
    }

    /**
     * Full form or merged import snapshot; optimistic concurrency protects edits.
     *
     * @param array<string, mixed> $files the upload columns the caller has
     *        already validated and stored — PDF and cover image. They are
     *        applied from HERE and never from $data, so a crafted form body
     *        cannot point a row at a file of someone else's choosing; a key
     *        that is absent leaves the stored value alone, and an explicit
     *        null clears it.
     */
    public function save(array $data, int $id = 0, ?int $revision = null, array $files = []): int
    {
        $authors = new \App\Services\ArticleAuthorService($this->db);
        $ownsTransaction = !$this->hasActiveTransaction();
        $savepoint = 'article_save_' . bin2hex(random_bytes(6));
        if ($ownsTransaction) { $this->db->begin_transaction(); }
        $this->db->query("SAVEPOINT $savepoint");
        try {
            $credits = null;
            if (array_key_exists('credits_present', $data)) {
                if (!$authors->available()) { throw new \RuntimeException('Article author schema unavailable'); }
                $credits = $authors->resolve($data['credits'] ?? []);
                $data['autori'] = implode('; ', array_column($credits, 'nome_credito'));
            } elseif ($id > 0 && $authors->available()) {
                // The stored column and the hydrated projection are two forms
                // of the same credit: the text as last saved, and the linked
                // identities' current names. A partial import merged onto
                // either, or an export taken before a rename, sends one of
                // them back unchanged — only a string matching NEITHER is a
                // replacement, and only a replacement may drop identities.
                $posted = self::authorList(isset($data['autori']) ? (string)$data['autori'] : null);
                $raw = $this->rows('SELECT autori FROM emeroteca_contributi WHERE id=?', [$id])[0]['autori'] ?? null;
                $current = $this->get($id);
                if ($posted !== self::authorList($raw === null ? null : (string)$raw)
                    && $posted !== self::authorList(isset($current['autori']) ? (string)$current['autori'] : null)) {
                    // An import that replaces the credit string cannot silently keep old identities.
                    $credits = [];
                }
            }
            $values = self::normalize($data);
            // The form's masthead picker (#412). Imports and older clients do not
            // send it and leave the link alone. Moving to another masthead drops
            // an issue that belongs to the old one.
            if (array_key_exists('host_testata_present', $data)) {
                $rawHost = $data['testata_id'] ?? '';
                if (!is_scalar($rawHost) || ($rawHost !== '' && !ctype_digit((string) $rawHost))) {
                    throw new \InvalidArgumentException(__('Testata non valida.'));
                }
                $hostId = (int) $rawHost;
                if ($hostId > 0 && $this->rows('SELECT id FROM emeroteca_testate WHERE id=?', [$hostId]) === []) {
                    throw new \InvalidArgumentException(__('Testata non valida.'));
                }
                $values['testata_id'] = $hostId > 0 ? $hostId : null;
                $currentIssue = $id > 0 ? (int) ($this->rows('SELECT fascicolo_id FROM emeroteca_contributi WHERE id=?', [$id])[0]['fascicolo_id'] ?? 0) : 0;
                if ($currentIssue > 0) {
                    $issueHost = (int) ($this->rows('SELECT a.testata_id FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id WHERE f.id=?', [$currentIssue])[0]['testata_id'] ?? 0);
                    if ($issueHost !== $hostId) {
                        $values['fascicolo_id'] = null;
                    }
                }
            }
            // The genre picker (#455) is sent by the form only: imports and
            // older clients leave the stored genre alone.
            if (array_key_exists('genre_present', $data)) {
                $rawGenre = $data['genere_id'] ?? '';
                if (!is_scalar($rawGenre) || ($rawGenre !== '' && !ctype_digit((string) $rawGenre))) {
                    throw new \InvalidArgumentException(__('Genere non trovato.'));
                }
                $genreId = (int) $rawGenre;
                if ($genreId > 0 && $this->rows('SELECT id FROM generi WHERE id=?', [$genreId]) === []) {
                    throw new \InvalidArgumentException(__('Genere non trovato.'));
                }
                $values['genere_id'] = $genreId > 0 ? $genreId : null;
            }
            foreach (['pdf_path','pdf_nome_originale','pdf_dimensione','copertina_url'] as $field) {
                if (array_key_exists($field, $files)) {
                    $values[$field] = $files[$field];
                }
            }
            if ($id > 0) {
                if ($revision === null) {
                    throw new \InvalidArgumentException(__('Ricarica la scheda prima di salvare.'));
                }
                $sets = implode(',', array_map(static fn ($key) => "$key = ?", array_keys($values)));
                $this->rows("UPDATE emeroteca_contributi SET $sets, revision=revision+1 WHERE id=? AND revision=?", [...array_values($values),$id,$revision]);
                if ($this->affectedRows !== 1) {
                    throw new \InvalidArgumentException(__('La scheda è stata modificata. Ricarica prima di salvare.'));
                }
            } else {
                $key = $data['reference_key'] ?? bin2hex(random_bytes(16));
                if (!is_string($key) || !preg_match(self::REFERENCE_KEY_PATTERN, $key)) {
                    throw new \InvalidArgumentException(__('Identificatore non valido.'));
                }
                $values['reference_key'] = $key;
                $columns = implode(',', array_keys($values));
                $marks = implode(',', array_fill(0, count($values), '?'));
                $this->rows("INSERT INTO emeroteca_contributi ($columns) VALUES ($marks)", array_values($values));
                $id = (int)$this->db->insert_id;
            }
            if ($credits !== null) { $authors->replace($id, $credits); }
            $this->db->query("RELEASE SAVEPOINT $savepoint");
            if ($ownsTransaction) { $this->db->commit(); }
            return $id;
        } catch (\Throwable $e) {
            if ($ownsTransaction) { $this->db->rollback(); }
            else {
                $this->db->query("ROLLBACK TO SAVEPOINT $savepoint");
                $this->db->query("RELEASE SAVEPOINT $savepoint");
            }
            throw $e;
        }
    }

    /**
     * Every genre as a choice for the article form, labelled with its whole
     * path ("Storia › Storia sociale"), so any level of the books' genre tree
     * can be picked. null when the lookup fails: the form then leaves the
     * stored genre alone instead of offering an empty picker.
     *
     * @return list<array{id:int,label:string}>|null
     */
    public function genreOptions(): ?array
    {
        try {
            $rows = $this->rows('SELECT id, nome, parent_id FROM generi', []);
        } catch (\Throwable $e) {
            return null;
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $options = [];
        foreach ($byId as $id => $row) {
            $options[] = ['id' => $id, 'label' => implode(' › ', array_column(self::genrePath($byId, $id), 'nome'))];
        }
        usort($options, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        return $options;
    }

    /**
     * The genre and its ancestors, root first, as the catalogue's genre
     * breadcrumb shows them for a book.
     *
     * @return list<array{id:int,nome:string}>
     */
    public function genreTrail(int $genreId): array
    {
        if ($genreId <= 0) {
            return [];
        }
        $byId = [];
        // Three levels is the depth of the books' genre tree; the walk stops
        // at the root or at a row it has already seen.
        for ($next = $genreId, $i = 0; $next > 0 && $i < 10 && !isset($byId[$next]); $i++) {
            $row = $this->rows('SELECT id, nome, parent_id FROM generi WHERE id=?', [$next])[0] ?? null;
            if ($row === null) {
                break;
            }
            $byId[$next] = $row;
            $next = (int) ($row['parent_id'] ?? 0);
        }
        return isset($byId[$genreId]) ? self::genrePath($byId, $genreId) : [];
    }

    /**
     * @param array<int, array<string,mixed>> $byId
     * @return list<array{id:int,nome:string}>
     */
    private static function genrePath(array $byId, int $id): array
    {
        $path = [];
        $seen = [];
        while (isset($byId[$id]) && !isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($path, ['id' => $id, 'nome' => (string) $byId[$id]['nome']]);
            $id = (int) ($byId[$id]['parent_id'] ?? 0);
        }
        return $path;
    }

    /** @param array<string,mixed> $row @return list<array{name:string,id:?int}> */
    public static function authorLinks(array $row): array
    {
        if (!empty($row['author_credits'])) {
            // Link text is the reader-facing display form ("Pseudonimo (Nome
            // vero)"); nome_credito is the citation form, for citations only.
            return array_map(static fn($credit) => ['name'=>(string)($credit['display_name'] ?? $credit['nome_credito']), 'id'=>$credit['autore_id']], $row['author_credits']);
        }
        return array_map(static fn($name) => ['name'=>$name, 'id'=>null], self::authorList($row['autori'] ?? null));
    }

    /** Public search filters: linked names and legacy credits share author filtering. */
    public const FILTER_FIELDS = ['autori' => 'autore', 'contenitore_titolo' => 'pubblicazione', 'keywords' => 'keyword'];

    /**
     * Where an article sits, joined onto `c` by every public read path: the
     * masthead (title, logo) and, when it was placed in one, the issue (number,
     * cover, status) and that issue's year. Aliased so they never collide with
     * the article's own free-text `numero` / `volume` citation fields.
     */
    public const PLACEMENT_COLUMNS = 't.titolo testata_titolo, t.issn testata_issn, t.logo_url testata_logo_url,'
        . ' f.numero fascicolo_numero, f.titolo_fascicolo fascicolo_titolo, f.copertina_url fascicolo_copertina_url,'
        . ' f.stato fascicolo_stato, f.annata_id fascicolo_annata_id, fa.anno fascicolo_anno, fa.volume fascicolo_volume';

    /** The joins PLACEMENT_COLUMNS reads from; all LEFT, all on nullable keys. */
    public const PLACEMENT_JOINS = ' LEFT JOIN emeroteca_testate t ON t.id = c.testata_id'
        . ' LEFT JOIN emeroteca_fascicoli f ON f.id = c.fascicolo_id'
        . ' LEFT JOIN emeroteca_annate fa ON fa.id = f.annata_id';

    /**
     * The names credited by a free-text `autori` citation, in the order they
     * were written — one per narrowing link, because a filter value holding a
     * whole credit line can only ever match the article it came from.
     *
     * The separator is the SEMICOLON and nothing else. A comma is NOT a
     * separator here: a single name is routinely written inverted, and the
     * plugin's own documented reference value is "Schweissinger, Marc J."
     * (README.md, src/Views/article-import.php) — splitting on ',' would turn
     * one author into two half-names that look plausible and mean nothing.
     * ' and ' / ' & ' are excluded for the same reason: the CSV importer
     * accepts corporate authors such as "Institute of Science and Technology".
     *
     * A string with no semicolon comes back as a single element, so the whole
     * existing single-author corpus renders exactly as it did before.
     * A null, empty or whitespace-only field yields [] — never [''].
     *
     * This is a RENDER-TIME split: the stored citation is never rewritten
     * (ContributionCsv matches duplicates on exact equality of `autori`).
     *
     * @return list<string>
     */
    public static function authorList(?string $autori): array
    {
        if ($autori === null) {
            return [];
        }
        $parts = array_map(
            static fn (string $name): string => trim($name),
            explode(';', $autori)
        );
        return array_values(array_filter($parts, static fn (string $name): bool => $name !== ''));
    }

    /**
     * @param array<string, string> $filters subset of FILTER_FIELDS values ⇒ the
     *        text to match; an empty or unknown key is ignored
     * @param int $fascicolo only the articles placed in this issue (0 = any)
     * @param int $perPage page size; 50 is the historical default the mobile
     *        API depends on, the public pages ask for fewer
     * @return array{rows:array,total:int,page:int,pages:int}
     */
    public function search(string $term = '', int $testata = 0, bool $public = false, int $page = 1, array $filters = [], int $fascicolo = 0, int $perPage = 50): array
    {
        $perPage = max(1, min(200, $perPage));
        $where = ['1=1'];
        $linkedAuthors = (new \App\Services\ArticleAuthorService($this->db))->available();
        $authorMatch = "EXISTS (SELECT 1 FROM emeroteca_contributi_autori ca JOIN autori a ON a.id=ca.autore_id WHERE ca.contributo_id=c.id AND (a.nome LIKE ? ESCAPE '=' OR a.pseudonimo LIKE ? ESCAPE '='))";
        $params = [];
        if ($public) {
            $where[] = 'c.pubblico=1';
        }
        if ($testata > 0) {
            $where[] = 'c.testata_id=?';
            $params[] = $testata;
        }
        if ($fascicolo > 0) {
            $where[] = 'c.fascicolo_id=?';
            $params[] = $fascicolo;
        }
        foreach (self::FILTER_FIELDS as $column => $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            // Substring, not equality: keywords arrive as one comma-separated
            // string, and an author field holding two names must still answer
            // for each of them.
            $where[] = $column === 'autori' && $linkedAuthors ? "(c.$column LIKE ? ESCAPE '=' OR $authorMatch)" : "c.$column LIKE ? ESCAPE '='";
            $pattern = '%' . strtr(mb_substr($value, 0, 200), ['=' => '==','%' => '=%','_' => '=_']) . '%';
            $params[] = $pattern;
            if ($column === 'autori' && $linkedAuthors) { array_push($params, $pattern, $pattern); }
        }
        if ($term !== '') {
            $extraAuthors = $linkedAuthors ? " OR $authorMatch" : '';
            $where[] = "(c.titolo LIKE ? ESCAPE '=' OR c.sottotitolo LIKE ? ESCAPE '=' OR c.autori LIKE ? ESCAPE '=' OR c.contenitore_titolo LIKE ? ESCAPE '=' OR c.keywords LIKE ? ESCAPE '=' OR c.issn=?$extraAuthors)";
            $pattern = '%' . strtr(mb_substr($term, 0, 200), ['=' => '==','%' => '=%','_' => '=_']) . '%';
            array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern, $term);
            if ($linkedAuthors) { array_push($params, $pattern, $pattern); }
        }
        $sql = implode(' AND ', $where);
        $total = (int)$this->rows("SELECT COUNT(*) n FROM emeroteca_contributi c WHERE $sql", $params)[0]['n'];
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($pages, max(1, $page));
        $offset = ($page - 1) * $perPage;
        $rows = $this->rows("SELECT c.*, " . self::PLACEMENT_COLUMNS . " FROM emeroteca_contributi c" . self::PLACEMENT_JOINS . " WHERE $sql ORDER BY c.id DESC LIMIT $perPage OFFSET $offset", $params);
        $rows = $this->hydrateAuthors($rows);
        return compact('rows', 'total', 'page', 'pages');
    }

    /**
     * The published articles placed in one issue, in reading order: by the
     * first page number their `pagine` field names, then by id. Unnumbered
     * pieces go last. The sort runs in PHP because the page field is free text
     * ("pp. 45-67", "12") and the supported floor (MySQL 5.7) has no
     * REGEXP_SUBSTR; an issue holds tens of articles, never thousands.
     *
     * @return list<array<string,mixed>>
     */
    public function issueContents(int $fascicolo): array
    {
        if ($fascicolo <= 0) {
            return [];
        }
        $rows = $this->rows(
            'SELECT c.*, ' . self::PLACEMENT_COLUMNS . ' FROM emeroteca_contributi c' . self::PLACEMENT_JOINS
            . ' WHERE c.pubblico = 1 AND c.fascicolo_id = ? ORDER BY c.id LIMIT 500',
            [$fascicolo]
        );
        usort($rows, static function (array $a, array $b): int {
            $pa = self::firstPage($a);
            $pb = self::firstPage($b);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return (int) $a['id'] <=> (int) $b['id'];
        });
        return $this->hydrateAuthors($rows);
    }

    /** First page number named by a free-text `pagine` field; PHP_INT_MAX when none. */
    public static function firstPage(array $row): int
    {
        return preg_match('/\d+/', (string) ($row['pagine'] ?? ''), $m) === 1 ? (int) $m[0] : PHP_INT_MAX;
    }

    /**
     * The article before and after this one in its issue's reading order, so
     * the article page can be read like the issue it came from.
     *
     * @param array<string,mixed> $article a row carrying `id` and `fascicolo_id`
     * @return array{prev: ?array<string,mixed>, next: ?array<string,mixed>}
     */
    public function neighboursInIssue(array $article): array
    {
        $out = ['prev' => null, 'next' => null];
        $contents = $this->issueContents((int) ($article['fascicolo_id'] ?? 0));
        foreach ($contents as $i => $row) {
            if ((int) $row['id'] === (int) ($article['id'] ?? 0)) {
                $out['prev'] = $contents[$i - 1] ?? null;
                $out['next'] = $contents[$i + 1] ?? null;
                break;
            }
        }
        return $out;
    }

    /**
     * Other published articles of the same masthead, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function relatedInTestata(int $testata, int $excludeId, int $limit = 4): array
    {
        if ($testata <= 0) {
            return [];
        }
        $limit = max(1, min(24, $limit));
        $rows = $this->rows(
            'SELECT c.*, ' . self::PLACEMENT_COLUMNS . ' FROM emeroteca_contributi c' . self::PLACEMENT_JOINS
            . " WHERE c.pubblico = 1 AND c.testata_id = ? AND c.id <> ? ORDER BY c.id DESC LIMIT $limit",
            [$testata, $excludeId]
        );
        return $this->hydrateAuthors($rows);
    }

    /**
     * Other published articles credited to the same linked author (an
     * authority record, never a free-text name: homonyms are real).
     *
     * @return list<array<string,mixed>>
     */
    public function relatedByAuthor(int $autore, int $excludeId, int $limit = 4): array
    {
        if ($autore <= 0 || !(new \App\Services\ArticleAuthorService($this->db))->available()) {
            return [];
        }
        $limit = max(1, min(24, $limit));
        $rows = $this->rows(
            'SELECT c.*, ' . self::PLACEMENT_COLUMNS . ' FROM emeroteca_contributi c' . self::PLACEMENT_JOINS
            . ' WHERE c.pubblico = 1 AND c.id <> ? AND EXISTS (SELECT 1 FROM emeroteca_contributi_autori ca WHERE ca.contributo_id = c.id AND ca.autore_id = ?)'
            . " ORDER BY c.id DESC LIMIT $limit",
            [$excludeId, $autore]
        );
        return $this->hydrateAuthors($rows);
    }

    /** Existing issue indexes share the article list, but retain their issue-owned lifecycle. */
    public function indexedSearch(string $term = '', int $testata = 0, int $page = 1): array
    {
        $where = ['1=1'];
        $params = [];
        if ($testata > 0) {
            $where[] = 't.id=?';
            $params[] = $testata;
        }
        if ($term !== '') {
            $pattern = '%'.strtr(mb_substr($term, 0, 200), ['=' => '==','%' => '=%','_' => '=_']).'%';
            $where[] = "(ar.titolo LIKE ? ESCAPE '=' OR ar.autori LIKE ? ESCAPE '=' OR t.titolo LIKE ? ESCAPE '=' OR ar.keywords LIKE ? ESCAPE '=')";
            array_push($params, $pattern, $pattern, $pattern, $pattern);
        }
        $from = ' FROM emeroteca_articoli ar JOIN emeroteca_fascicoli f ON f.id=ar.fascicolo_id JOIN emeroteca_annate a ON a.id=f.annata_id JOIN emeroteca_testate t ON t.id=a.testata_id WHERE '.implode(' AND ', $where);
        $total = (int)$this->rows('SELECT COUNT(*) n'.$from, $params)[0]['n'];
        $pages = max(1, (int)ceil($total / 50));
        $page = min($pages, max(1, $page));
        $offset = ($page - 1) * 50;
        $rows = $this->rows("SELECT ar.id,ar.titolo,ar.autori,ar.fascicolo_id,t.titolo contenitore_titolo,t.titolo testata_titolo,f.data_copertina data_pubblicazione_testo,a.volume,f.numero,CONCAT_WS('–',ar.pagina_inizio,ar.pagina_fine) pagine,(f.stato<>'scartato') pubblico".$from." ORDER BY ar.id DESC LIMIT 50 OFFSET $offset", $params);
        return compact('rows', 'total', 'page', 'pages');
    }

    /** Association never creates a holding. Null target detaches; create+associate is atomic. */
    public function associate(array $revisions, int $testata, int $fascicolo = 0, string $newTitle = '', bool $reassign = false, bool $detachIssues = false): int
    {
        if (!$revisions || count($revisions) > 500) {
            throw new \InvalidArgumentException(__('Seleziona da 1 a 500 articoli.'));
        }
        // A nested begin_transaction() does not fail in mysqli: it implicitly
        // commits the caller's transaction, so the rollback below would leave
        // half of the reassignment on disk.
        $ownsTransaction = !$this->hasActiveTransaction();
        if ($ownsTransaction && !$this->db->begin_transaction()) {
            throw new \RuntimeException('Contribution association could not start a transaction');
        }
        try {
            if ($newTitle !== '') {
                if ($testata || mb_strlen($newTitle) > 255) {
                    throw new \InvalidArgumentException(__('Testata non valida.'));
                }
                $this->rows('INSERT INTO emeroteca_testate (titolo) VALUES (?)', [trim($newTitle)]);
                $testata = (int)$this->db->insert_id;
            }
            if ($testata && !$this->rows('SELECT id FROM emeroteca_testate WHERE id=? FOR UPDATE', [$testata])) {
                throw new \InvalidArgumentException(__('Testata non trovata.'));
            }
            if ($fascicolo) {
                $host = $this->rows('SELECT a.testata_id FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id=f.annata_id WHERE f.id=? FOR UPDATE', [$fascicolo])[0] ?? [];
                if (!$testata || (int)($host['testata_id'] ?? 0) !== $testata) {
                    throw new \InvalidArgumentException(__('Il fascicolo non appartiene alla testata.'));
                }
            }
            ksort($revisions, SORT_NUMERIC);
            foreach ($revisions as $id => $revision) {
                $row = $this->rows('SELECT * FROM emeroteca_contributi WHERE id=? FOR UPDATE', [(int)$id])[0] ?? null;
                if (!$row || (int)$row['revision'] !== (int)$revision) {
                    throw new \InvalidArgumentException(__('La selezione è cambiata. Ricarica gli articoli.'));
                }
                if (!$reassign && $row['testata_id'] && (int)$row['testata_id'] !== $testata) {
                    throw new \InvalidArgumentException(__('Conferma la riassegnazione degli articoli già associati.'));
                }
                // Zero explicitly means masthead only, including when the host is
                // unchanged — so it can drop an issue link the operator never
                // meant to touch. Same guard as the reassignment above: on a
                // batch of up to 500 the loss must be confirmed, not inferred.
                if (!$fascicolo && $row['fascicolo_id'] && !$detachIssues) {
                    throw new \InvalidArgumentException(__('Conferma la rimozione del collegamento al fascicolo per gli articoli già collocati.'));
                }
                $issue = $fascicolo ?: null;
                if ((int)$row['testata_id'] === $testata && (int)$row['fascicolo_id'] === (int)$issue) {
                    continue;
                }
                $this->rows('UPDATE emeroteca_contributi SET testata_id=?,fascicolo_id=?,revision=revision+1 WHERE id=?', [$testata ?: null,$issue ?: null,(int)$id]);
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $testata;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Detect both autocommit(false) and an explicit begin_transaction() (the
     * latter leaves @@autocommit enabled), using the same disposable savepoint
     * probe as PeriodicalAdminController::hasActiveTransaction().
     */
    private function hasActiveTransaction(): bool
    {
        $result = $this->db->query('SELECT @@autocommit AS ac');
        if ($result instanceof \mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            if ((int) ($row['ac'] ?? 1) === 0) {
                return true;
            }
        }

        $probe = 'pinakes_contributo_probe_' . bin2hex(random_bytes(6));
        $probeCreated = false;
        try {
            if (!$this->db->query("SAVEPOINT {$probe}")) {
                return false;
            }
            $probeCreated = true;
            if (!$this->db->query("ROLLBACK TO SAVEPOINT {$probe}")) {
                return false;
            }
            return true;
        } catch (\mysqli_sql_exception) {
            return false;
        } finally {
            if ($probeCreated) {
                try {
                    $this->db->query("RELEASE SAVEPOINT {$probe}");
                } catch (\mysqli_sql_exception) {
                    // The caller still owns its transaction; a failed cleanup
                    // of this disposable probe must not change that.
                }
            }
        }
    }

    /**
     * The electronic resource of danMARC2 856, as one decision instead of
     * three fields every caller has to re-combine.
     *
     * Returns null when there is nothing to show — no address, or an address
     * the librarian has not published — so a caller cannot accidentally leak
     * it by reading the columns directly. `linkable` is the whole point of the
     * shape: an http(s) address becomes an href, and ANY other value (a UNC
     * share, a file: URI, an identifier in a document management system) is a
     * reference the library can read and a browser cannot, so it is rendered
     * as text. Deciding that here, once, is what stops one of the four call
     * sites from putting a `file:` path in an anchor.
     *
     * @param array<string,mixed> $row
     * @param bool $public true on the website and the mobile API, false in the
     *        admin interface, where an unpublished resource is still shown
     * @return array{url:string,text:string,access:string,linkable:bool}|null
     */
    public static function resource(array $row, bool $public): ?array
    {
        $url = trim((string) ($row['risorsa_url'] ?? ''));
        if ($url === '' || ($public && empty($row['risorsa_pubblica']))) {
            return null;
        }

        return [
            'url' => $url,
            'text' => trim((string) ($row['risorsa_testo'] ?? '')),
            'access' => trim((string) ($row['risorsa_accesso'] ?? '')),
            'linkable' => preg_match('~^https?://~i', $url) === 1,
        ];
    }

    /**
     * What this record IS, in one line, for a reader who does not know what an
     * analytic record is.
     *
     * The distinction a component-part record exists to make — this is a piece
     * OF something, not a thing the library holds — is currently implicit: it
     * lives in the table the row sits in and in the JSON-LD nobody reads. A
     * catalogue that knows the difference should say it on the page.
     *
     * @param array<string,mixed> $row
     */
    public static function materialType(array $row): string
    {
        $container = (string) ($row['contenitore_tipo'] ?? '');
        if ($container === 'giornale') {
            return __('Articolo di giornale');
        }
        if ($container === 'antologia') {
            return __('Capitolo di un volume');
        }
        if ($container !== '') {
            return __('Articolo di rivista');
        }

        return __('Articolo');
    }

    /**
     * The image to show for an article: its own, else its issue's cover, else
     * the masthead's logo.
     *
     * An article carries a cover only since 1.6, and most never will — it is
     * an optional field on a record that is usually just a citation. Falling
     * straight through to the catalogue placeholder made a list of results a
     * column of identical grey rectangles. The issue the article was printed in
     * is the most specific image that is genuinely its own; the masthead's
     * logo is the next one, and it still says at a glance which publication a
     * result came from.
     *
     * (Until 1.9 the issue cover was skipped on purpose, to keep a list visually
     * keyed to the masthead. The library chose the more specific image: an
     * article placed in an issue is shown with that issue.)
     *
     * The single owner of this rule. Public views, the core catalogue and the
     * mobile projection all ask it — or reproduce it in SQL with the same
     * order — rather than each writing "own cover or else". Pure: the caller's
     * row must already carry `fascicolo_copertina_url` and `testata_logo_url`,
     * which every read path that renders an article joins in. A row without
     * them degrades to the next image down, never to a per-row query.
     *
     * @param array<string,mixed> $row
     * @return string '' when there is no image at all — the caller decides
     *                what a missing image looks like (a placeholder on a page,
     *                a null in a payload, an absent key in structured data).
     */
    public static function coverUrl(array $row): string
    {
        foreach (['copertina_url', 'fascicolo_copertina_url', 'testata_logo_url'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Shape one raw contribution row for public/mobile consumption: keeps only an allowlist of
     * public columns, so internal ones (note_private, collocazione, pdf_path, reference_key,
     * revision, pubblico, ...) never leave, casts numeric ids, and adds `kind` and `has_public_pdf` (true only when a PDF is stored
     * AND opted into public visibility).
     *
     * @param array<string, mixed> $r a raw emeroteca_contributi row
     * @return array<string, mixed>
     */
    public static function publicData(array $r): array
    {
        $out = array_intersect_key($r, array_flip(['id','titolo','sottotitolo','autori','tipo_contributo','contenitore_tipo','contenitore_titolo','issn','data_pubblicazione_testo','anno_pubblicazione','volume','numero','pagine','doi','supporto','keywords','abstract','lingua','paese','classificazione_schema','classificazione','nota_possesso','contenitore_curatori','contenitore_editore','contenitore_luogo','isbn','testata_id','fascicolo_id','updated_at']));
        foreach (['id','anno_pubblicazione','testata_id','fascicolo_id'] as $key) {
            if (isset($out[$key])) { $out[$key] = (int) $out[$key]; }
        }
        $out['kind'] = 'autonomo';
        $out['has_public_pdf'] = !empty($r['pdf_path']) && !empty($r['pdf_pubblico']);
        // The 856 triple travels only when it has been published, and the
        // three keys are ABSENT rather than null when it has not: a null
        // address in a payload still says one exists. `collocazione`, the
        // archive box, stays out of both cases — it is a shelf mark, and this
        // whitelist has never carried one.
        $resource = self::resource($r, true);
        $out['has_public_resource'] = $resource !== null;
        if ($resource !== null) {
            $out['risorsa_url'] = $resource['url'];
            $out['risorsa_testo'] = $resource['text'];
            $out['risorsa_accesso'] = $resource['access'];
        }
        return $out;
    }
}
