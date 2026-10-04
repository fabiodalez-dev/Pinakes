<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\AuthorRepository;
use App\Support\AuthorName;
use mysqli;

/** Explicit author identities for analytic records; legacy credits stay unlinked. */
final class ArticleAuthorService
{
    /** Credits an article can link; the editor holds the same limit. */
    public const MAX_CREDITS = 20;
    /** Length of emeroteca_contributi_autori.nome_credito. */
    public const MAX_CREDIT_LENGTH = 255;

    private ?bool $available = null;
    public function __construct(private mysqli $db) {}

    public function available(): bool
    {
        if ($this->available === null) {
            $result = $this->db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='emeroteca_contributi_autori'");
            $this->available = $result !== false && $result->num_rows > 0;
        }
        return $this->available;
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) { throw new \RuntimeException('Article author statement unavailable'); }
        try {
            if ($params) { $stmt->bind_param(str_repeat('s', count($params)), ...$params); }
            if (!$stmt->execute()) { throw new \RuntimeException('Article author query failed'); }
            $result = $stmt->get_result();
            return $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } finally { $stmt->close(); }
    }

    /** Resolve only explicitly selected IDs or explicitly requested new people.
     * @return list<array{autore_id:?int,nome_credito:string,ruolo:string}>
     */
    public function resolve(mixed $input): array
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException(__('Valore non valido.'));
        }
        if (count($input) > self::MAX_CREDITS) {
            throw new \InvalidArgumentException(__('Puoi collegare al massimo 20 autori a un articolo.'));
        }
        $credits = []; $seen = [];
        foreach ($input as $credit) {
            if (!is_array($credit) || !is_scalar($credit['nome_credito'] ?? '') || !is_scalar($credit['autore_id'] ?? '') || !is_scalar($credit['ruolo'] ?? 'co-autore')) {
                throw new \InvalidArgumentException(__('Valore non valido.'));
            }
            $name = trim((string)($credit['nome_credito'] ?? ''));
            $rawId = (string)($credit['autore_id'] ?? '');
            $role = (string)($credit['ruolo'] ?? 'co-autore');
            if (!in_array($role, ['principale', 'co-autore'], true) || str_contains($name, ';')) {
                throw new \InvalidArgumentException(__('Valore non valido.'));
            }
            if (mb_strlen($name) > self::MAX_CREDIT_LENGTH) {
                throw new \InvalidArgumentException(__('Il nome di un autore non può superare i 255 caratteri.'));
            }
            $id = null;
            if ($rawId !== '' && $rawId !== '0') {
                if (!ctype_digit($rawId) || (int)$rawId < 1) { throw new \InvalidArgumentException(__('Valore non valido.')); }
                $id = (int)$rawId;
                $author = $this->rows('SELECT * FROM autori WHERE id=? FOR UPDATE', [$id])[0] ?? null;
                if (!$author) { throw new \InvalidArgumentException(__('Autore non trovato.')); }
                $name = self::citationCredit($author, $name);
            } elseif (($credit['create'] ?? '') === '1' || ($credit['create'] ?? '') === 1) {
                if ($name === '') { throw new \InvalidArgumentException(__('Il nome è obbligatorio.')); }
                $id = (new AuthorRepository($this->db))->create(['nome'=>$name]);
                $author = $this->rows('SELECT * FROM autori WHERE id=?', [$id])[0];
                $name = self::citationCredit($author, $name);
            }
            if ($name === '') { continue; }
            if ($id !== null && isset($seen[$id])) { throw new \InvalidArgumentException(__('Autore già selezionato.')); }
            if ($id !== null) { $seen[$id] = true; }
            $credits[] = ['autore_id'=>$id, 'nome_credito'=>$name, 'ruolo'=>$role];
        }
        if (count(array_filter($credits, static fn($c) => $c['ruolo'] === 'principale')) > 1) {
            throw new \InvalidArgumentException(__('Valore non valido.'));
        }
        return $credits;
    }

    /** @param list<array{autore_id:?int,nome_credito:string,ruolo:string}> $credits */
    public function replace(int $id, array $credits): void
    {
        if (!$this->available()) { throw new \RuntimeException('Article author schema unavailable'); }
        $this->rows('DELETE FROM emeroteca_contributi_autori WHERE contributo_id=?', [$id]);
        foreach ($credits as $order => $credit) {
            $this->rows('INSERT INTO emeroteca_contributi_autori(contributo_id,ordine_credito,autore_id,nome_credito,ruolo) VALUES (?,?,?,?,?)',
                [$id, $order, $credit['autore_id'], $credit['nome_credito'], $credit['ruolo']]);
        }
    }

    /**
     * The credit as it is CITED ("Surname, Forename") for a linked identity.
     *
     * A written credit that already names this identity in inverted form is
     * kept, so a librarian's "van Gogh, Vincent" is not re-guessed as
     * "Gogh, Vincent van". Anything else — a direct-order label from the
     * picker, a stale spelling after a rename — is replaced by the identity's
     * current citation form. Never the "Pseudonimo (Nome vero)" display form:
     * that belongs in display_name and in link text only.
     *
     * @param array<string,mixed> $author
     */
    private static function citationCredit(array $author, string $written): string
    {
        $citation = AuthorName::citation($author);
        $written = trim($written);
        if ($written !== '' && str_contains($written, ',')) {
            [$surname, $rest] = array_map('trim', explode(',', $written, 2));
            $direct = preg_replace('/\s+/u', ' ', trim($rest . ' ' . $surname)) ?? '';
            $nome = trim((string)($author['nome'] ?? ''), ' ');
            $pseudonimo = trim((string)($author['pseudonimo'] ?? ''), ' ');
            $preferred = preg_replace('/\s+/u', ' ', $pseudonimo !== '' ? $pseudonimo : $nome) ?? '';
            if ($direct === $preferred || $written === $preferred) {
                return $written;
            }
        }
        return $citation;
    }

    /** Bulk projection reads current names, pseudonyms and identifiers without mutating the original credit.
     *
     * For a linked credit `nome_credito` (and therefore the row's `autori`) is
     * the identity's current CITATION form, which is what citations and MARC
     * 100/700 need; the reader-facing "Pseudonimo (Nome vero)" form travels
     * separately as `display_name`, for link text only.
     *
     * @param list<array<string,mixed>> $records @return list<array<string,mixed>>
     */
    public function hydrate(array $records): array
    {
        if ($records === [] || !$this->available()) { return $records; }
        $ids = array_map(static fn($r) => (int)$r['id'], $records);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $credits = $this->rows("SELECT ca.*, a.* , ca.autore_id identity_id, ca.contributo_id article_id FROM emeroteca_contributi_autori ca LEFT JOIN autori a ON a.id=ca.autore_id WHERE ca.contributo_id IN ($marks) ORDER BY ca.contributo_id, ca.ordine_credito", $ids);
        $byArticle = [];
        foreach ($credits as $credit) {
            $id = $credit['identity_id'] !== null ? (int)$credit['identity_id'] : null;
            $stored = (string)$credit['nome_credito'];
            $name = $id !== null ? self::citationCredit($credit, $stored) : $stored;
            $display = $id !== null ? AuthorName::display($credit) : $stored;
            $identifiers = [];
            $confidence = (string)($credit['authority_confidence'] ?? '');
            $confirmed = $confidence !== 'rejected' && ($confidence === 'exact' || ($credit['authority_source'] ?? '') === 'manual');
            foreach ($confirmed ? ['viaf_uri', 'isni_uri'] : [] as $key) {
                $uri = (string)($credit[$key] ?? '');
                if (preg_match('~^https?://~', $uri) === 1) { $identifiers[] = $uri; }
            }
            if (!empty($credit['gnd_id'])) { $identifiers[] = 'https://d-nb.info/gnd/'.$credit['gnd_id']; }
            $byArticle[(int)$credit['article_id']][] = ['autore_id'=>$id, 'nome_credito'=>$name,
                'display_name'=>$display, 'ruolo'=>(string)$credit['ruolo'], 'identifiers'=>$identifiers];
        }
        foreach ($records as &$record) {
            $record['author_credits'] = $byArticle[(int)$record['id']] ?? [];
            if ($record['author_credits'] !== []) {
                $record['autori'] = implode('; ', array_column($record['author_credits'], 'nome_credito'));
                if (array_key_exists('autore', $record)) { $record['autore'] = $record['autori']; }
            }
        }
        unset($record);
        return $records;
    }

    /** During a core author merge preserve IDs and collapse only explicitly identical people. */
    public function merge(int $primaryId, int $duplicateId): void
    {
        if (!$this->available()) { return; }
        // If both identities credit one article, retain its principal role before collapsing.
        $this->rows("UPDATE emeroteca_contributi_autori keep_credit JOIN emeroteca_contributi_autori duplicate_credit ON duplicate_credit.contributo_id=keep_credit.contributo_id SET keep_credit.ruolo='principale' WHERE keep_credit.autore_id=? AND duplicate_credit.autore_id=? AND duplicate_credit.ruolo='principale'", [$primaryId, $duplicateId]);
        $this->rows('UPDATE IGNORE emeroteca_contributi_autori SET autore_id=? WHERE autore_id=?', [$primaryId, $duplicateId]);
        $this->rows('DELETE FROM emeroteca_contributi_autori WHERE autore_id=?', [$duplicateId]);
    }

    /** Keep the latest name, in citation form, if an identity is explicitly deleted. */
    public function beforeDelete(int $id): void
    {
        if (!$this->available()) { return; }
        $author = $this->rows('SELECT * FROM autori WHERE id=?', [$id])[0] ?? null;
        if ($author) {
            foreach ($this->rows('SELECT contributo_id, ordine_credito, nome_credito FROM emeroteca_contributi_autori WHERE autore_id=?', [$id]) as $credit) {
                $this->rows('UPDATE emeroteca_contributi_autori SET nome_credito=?, autore_id=NULL WHERE contributo_id=? AND ordine_credito=?',
                    [self::citationCredit($author, (string)$credit['nome_credito']), $credit['contributo_id'], $credit['ordine_credito']]);
            }
        }
    }
}
