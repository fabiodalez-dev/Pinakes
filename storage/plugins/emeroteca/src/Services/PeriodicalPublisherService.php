<?php
declare(strict_types=1);
namespace App\Plugins\Emeroteca\Services;

use App\Models\PublisherRepository;
use mysqli;

/** Ordered publishers for mastheads; editore_id remains the primary publisher. */
final class PeriodicalPublisherService
{
    public const MAX_PUBLISHERS = 20;
    public function __construct(private mysqli $db) {}

    public static function ddl(): string
    {
        return "CREATE TABLE IF NOT EXISTS emeroteca_testate_editori (
            testata_id INT NOT NULL,
            editore_id INT NOT NULL,
            ordine_credito SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (testata_id, editore_id),
            KEY idx_emeroteca_testate_editori_editore (editore_id),
            CONSTRAINT fk_emeroteca_testate_editori_testata FOREIGN KEY (testata_id)
                REFERENCES emeroteca_testate(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    }

    public function available(): bool
    {
        return $this->rows("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emeroteca_testate_editori'") !== [];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) { throw new \RuntimeException('Periodical publisher statement unavailable'); }
        try {
            if ($params !== []) { $stmt->bind_param(str_repeat('s', count($params)), ...$params); }
            if (!$stmt->execute()) { throw new \RuntimeException('Periodical publisher query failed'); }
            $result = $stmt->get_result();
            return $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } finally { $stmt->close(); }
    }

    /** Validate without creating authority records; preserves the input for redisplay. */
    public function validate(mixed $input, ?int $testataId = null): array
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException(__('Puoi selezionare al massimo 20 editori.'));
        }
        // A masthead merge can legitimately retain more than 20 publishers.
        // Its native select may keep/reduce that list without admitting new credits beyond the limit.
        if (count($input) > self::MAX_PUBLISHERS) {
            $existing = $testataId !== null && $this->available()
                ? $this->rows('SELECT editore_id FROM emeroteca_testate_editori WHERE testata_id=?', [$testataId]) : [];
            $allowed = array_map(static fn(array $row): string => (string)(int)$row['editore_id'], $existing);
            foreach ($input as $value) {
                if (!is_scalar($value) || !ctype_digit((string)$value) || !in_array((string)(int)$value,$allowed,true)) {
                    throw new \InvalidArgumentException(__('Puoi selezionare al massimo 20 editori.'));
                }
            }
        }
        $out = []; $seen = [];
        foreach ($input as $value) {
            if (!is_scalar($value)) { throw new \InvalidArgumentException(__('Valore non valido.')); }
            $value = trim((string)$value);
            if ($value === '') { continue; }
            if (str_starts_with($value, 'new:')) {
                $name = trim(substr($value, 4));
                if ($name === '' || mb_strlen($name) > 255) { throw new \InvalidArgumentException(__('Il nome è obbligatorio e non può superare i 255 caratteri.')); }
                $value = 'new:' . $name;
            } elseif (!ctype_digit($value) || (int)$value < 1 || $this->rows('SELECT id FROM editori WHERE id=?', [$value]) === []) {
                throw new \InvalidArgumentException(__('Editore non trovato.'));
            }
            $key = mb_strtolower($value);
            if (!isset($seen[$key])) { $out[] = $value; $seen[$key] = true; }
        }
        return $out;
    }

    /** Called inside the masthead save transaction. @return list<int> */
    public function resolve(array $values, ?int $testataId = null): array
    {
        $ids = [];
        foreach ($this->validate($values, $testataId) as $value) {
            if (str_starts_with($value, 'new:')) {
                $name = substr($value, 4);
                $existing = $this->rows('SELECT id FROM editori WHERE nome=? ORDER BY ' . \App\Support\ExactSearch::exactFirstSql('nome') . ', id LIMIT 1', [$name, $name]);
                $id = $existing !== [] ? (int)$existing[0]['id'] : (new PublisherRepository($this->db))->create(['nome'=>$name]);
            } else { $id = (int)$value; }
            if (!in_array($id, $ids, true)) { $ids[] = $id; }
        }
        return $ids;
    }

    public function replace(int $id, array $ids): void
    {
        if (!$this->available()) { throw new \RuntimeException('Periodical publisher schema unavailable'); }
        $this->rows('DELETE FROM emeroteca_testate_editori WHERE testata_id=?', [$id]);
        foreach (array_values(array_unique($ids)) as $order => $publisherId) {
            $this->rows('INSERT INTO emeroteca_testate_editori(testata_id,editore_id,ordine_credito) VALUES (?,?,?)', [$id,$publisherId,$order]);
        }
    }

    /** One query for a whole page; old mastheads keep their single publisher. */
    public function hydrate(array $records): array
    {
        if ($records === [] || !$this->available()) { return $records; }
        $ids = array_column($records, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $groups = [];
        foreach ($this->rows("SELECT te.testata_id, e.id, e.nome FROM emeroteca_testate_editori te JOIN editori e ON e.id=te.editore_id WHERE te.testata_id IN ($marks) ORDER BY te.testata_id,te.ordine_credito,e.id", $ids) as $row) {
            $groups[(int)$row['testata_id']][] = ['id'=>(int)$row['id'],'nome'=>(string)$row['nome']];
        }
        foreach ($records as &$record) {
            $publishers = $groups[(int)$record['id']] ?? [];
            if ($publishers === [] && !empty($record['editore_id']) && !empty($record['editore_nome'])) {
                $publishers[] = ['id'=>(int)$record['editore_id'],'nome'=>(string)$record['editore_nome']];
            }
            $record['editori'] = $publishers;
            $record['editori_ids'] = array_column($publishers, 'id');
            if ($publishers !== []) { $record['editore_nome'] = implode('; ', array_column($publishers, 'nome')); }
        }
        unset($record);
        return $records;
    }

    public function merge(int $primary, array $duplicates): void
    {
        if (!$this->available()) { return; }
        foreach ($duplicates as $duplicate) {
            $duplicate = (int)$duplicate;
            if ($duplicate <= 0 || $duplicate === $primary) { continue; }
            // If both identities occur on one masthead, the survivor inherits the earlier position.
            $this->rows('UPDATE emeroteca_testate_editori keep_credit JOIN emeroteca_testate_editori duplicate_credit ON duplicate_credit.testata_id=keep_credit.testata_id SET keep_credit.ordine_credito=LEAST(keep_credit.ordine_credito,duplicate_credit.ordine_credito) WHERE keep_credit.editore_id=? AND duplicate_credit.editore_id=?', [$primary,$duplicate]);
            $this->rows('UPDATE IGNORE emeroteca_testate_editori SET editore_id=? WHERE editore_id=?', [$primary,(int)$duplicate]);
            $this->rows('DELETE FROM emeroteca_testate_editori WHERE editore_id=?', [(int)$duplicate]);
        }
    }

    public function beforeDelete(int $id): void
    {
        if (!$this->available()) { return; }
        $titles = $this->rows('SELECT testata_id FROM emeroteca_testate_editori WHERE editore_id=?', [$id]);
        $this->rows('DELETE FROM emeroteca_testate_editori WHERE editore_id=?', [$id]);
        foreach ($titles as $title) {
            $remaining = $this->rows('SELECT editore_id FROM emeroteca_testate_editori WHERE testata_id=? ORDER BY ordine_credito LIMIT 1', [$title['testata_id']]);
            $this->rows('UPDATE emeroteca_testate SET editore_id=? WHERE id=? AND editore_id=?', [$remaining[0]['editore_id']??null,$title['testata_id'],$id]);
        }
    }

    /** During a masthead merge retain both ordered lists, including legacy publishers. */
    public function mergeTitles(array $source, array $target): void
    {
        if (!$this->available()) { return; }
        $records = $this->hydrate([$target,$source]);
        $ids = [];
        foreach ($records as $record) {
            foreach (($record['editori_ids'] ?? [($record['editore_id'] ?? 0)]) as $id) {
                if ((int)$id > 0 && !in_array((int)$id,$ids,true)) { $ids[] = (int)$id; }
            }
        }
        $this->replace((int)$target['id'],$ids);
        $this->rows('UPDATE emeroteca_testate SET editore_id=? WHERE id=?', [$ids[0]??null,$target['id']]);
    }
}
