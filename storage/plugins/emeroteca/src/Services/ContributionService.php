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
        'revision' => "INT UNSIGNED NOT NULL DEFAULT 1",
        'created_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'updated_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    public const TEXT_FIELDS = ['titolo' => 500,'autori' => 500,'tipo_contributo' => 30,'contenitore_tipo' => 30,
        'contenitore_titolo' => 255,'issn' => 9,'data_pubblicazione_testo' => 100,'volume' => 50,'numero' => 50,
        'pagine' => 100,'doi' => 255,'supporto' => 20,'keywords' => 500,'abstract' => 10000,'collocazione' => 255,'note_private' => 10000];
    /** A reference_key the table accepts: shared by save() and the CSV preview. */
    public const REFERENCE_KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,190}$/D';

    public const CSV_FIELDS = ['reference_key','titolo','autori','tipo_contributo','contenitore_tipo','contenitore_titolo',
        'issn','data_pubblicazione_testo','anno_pubblicazione','volume','numero','pagine','doi','supporto','keywords','abstract','collocazione','note_private','pubblico'];

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
    public function __construct(private \mysqli $db)
    {
    }

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
        return $rows;
    }

    public function get(int $id, bool $publicOnly = false): ?array
    {
        return $this->rows('SELECT * FROM emeroteca_contributi WHERE id = ?' . ($publicOnly ? ' AND pubblico = 1' : ''), [$id])[0] ?? null;
    }

    public function mode(): string
    {
        $row = $this->rows("SELECT s.setting_value FROM plugin_settings s JOIN plugins p ON p.id=s.plugin_id WHERE p.name='emeroteca' AND s.setting_key='mode'")[0] ?? [];
        return ($row['setting_value'] ?? '') === 'simple' ? 'simple' : 'complete';
    }

    public function setMode(string $mode): void
    {
        if (!in_array($mode, ['simple','complete'], true)) {
            throw new \InvalidArgumentException(__('Modalità non valida.'));
        }
        $this->rows("INSERT INTO plugin_settings (plugin_id,setting_key,setting_value) SELECT id,'mode',? FROM plugins WHERE name='emeroteca' ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)", [$mode]);
    }

    public static function normalize(array $input): array
    {
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
            || ($out['contenitore_tipo'] !== null && !in_array($out['contenitore_tipo'], ['rivista','giornale','magazine','bollettino','fanzine'], true))) {
            throw new \InvalidArgumentException(__('Tipo non valido.'));
        }
        if ($out['issn'] !== null) {
            if (!IssnHelper::isValidChecksum($out['issn'])) {
                throw new \InvalidArgumentException(__('ISSN non valido.'));
            }
            $out['issn'] = IssnHelper::normalize($out['issn']);
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
        foreach (['pubblico','pdf_pubblico'] as $key) {
            if (!in_array($input[$key] ?? 0, [0,1,'0','1',null,''], true)) {
                throw new \InvalidArgumentException(__('Visibilità non valida.'));
            }
            $out[$key] = (int) ($input[$key] ?? 0);
        }
        return $out;
    }

    /** Full form or merged import snapshot; optimistic concurrency protects edits. */
    public function save(array $data, int $id = 0, ?int $revision = null, array $pdf = []): int
    {
        $values = self::normalize($data);
        foreach (['pdf_path','pdf_nome_originale','pdf_dimensione'] as $field) {
            if (array_key_exists($field, $pdf)) {
                $values[$field] = $pdf[$field];
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
        return $id;
    }

    /** @return array{rows:array,total:int,page:int,pages:int} */
    public function search(string $term = '', int $testata = 0, bool $public = false, int $page = 1): array
    {
        $where = ['1=1'];
        $params = [];
        if ($public) {
            $where[] = 'c.pubblico=1';
        }
        if ($testata > 0) {
            $where[] = 'c.testata_id=?';
            $params[] = $testata;
        }
        if ($term !== '') {
            $where[] = "(c.titolo LIKE ? ESCAPE '=' OR c.autori LIKE ? ESCAPE '=' OR c.contenitore_titolo LIKE ? ESCAPE '=' OR c.keywords LIKE ? ESCAPE '=' OR c.issn=?)";
            $pattern = '%' . strtr(mb_substr($term, 0, 200), ['=' => '==','%' => '=%','_' => '=_']) . '%';
            array_push($params, $pattern, $pattern, $pattern, $pattern, $term);
        }
        $sql = implode(' AND ', $where);
        $total = (int)$this->rows("SELECT COUNT(*) n FROM emeroteca_contributi c WHERE $sql", $params)[0]['n'];
        $pages = max(1, (int)ceil($total / 50));
        $page = min($pages, max(1, $page));
        $offset = ($page - 1) * 50;
        $rows = $this->rows("SELECT c.*,t.titolo testata_titolo FROM emeroteca_contributi c LEFT JOIN emeroteca_testate t ON t.id=c.testata_id WHERE $sql ORDER BY c.id DESC LIMIT 50 OFFSET $offset", $params);
        return compact('rows', 'total', 'page', 'pages');
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
    public function associate(array $revisions, int $testata, int $fascicolo = 0, string $newTitle = '', bool $reassign = false): int
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
                // Preserve an existing issue when associating again to its same masthead.
                $issue = $fascicolo ?: ((int)$row['testata_id'] === $testata ? $row['fascicolo_id'] : null);
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

    public static function publicData(array $r): array
    {
        $out = array_intersect_key($r, array_flip(['id','titolo','autori','tipo_contributo','contenitore_tipo','contenitore_titolo','issn','data_pubblicazione_testo','anno_pubblicazione','volume','numero','pagine','doi','supporto','keywords','abstract','testata_id','fascicolo_id','updated_at']));
        foreach (['id','anno_pubblicazione','testata_id','fascicolo_id'] as $key) {
            if (isset($out[$key])) { $out[$key] = (int) $out[$key]; }
        }
        $out['kind'] = 'autonomo';
        $out['has_public_pdf'] = !empty($r['pdf_path']) && !empty($r['pdf_pubblico']);
        return $out;
    }
}
