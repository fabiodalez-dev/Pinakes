<?php
declare(strict_types=1);

namespace App\Models;

use mysqli;

class BookRepository
{
    public function __construct(private mysqli $db) {}

    private function logDebug(string $label, array $payload): void
    {
        // SECURITY: Logging disabilitato per prevenire information disclosure
        // Usa AppLog per logging sicuro in development
        if (getenv('APP_ENV') === 'development') {
            \App\Support\Log::debug($label, $payload);
        }
    }

    public function listWithAuthors(int $limit = 100): array
    {
        $rows = [];
        $sql = "SELECT l.id, l.titolo, e.nome AS editore,
                (
                  SELECT GROUP_CONCAT(a.nome SEPARATOR ', ')
                  FROM libri_autori la
                  JOIN autori a ON la.autore_id = a.id
                  WHERE la.libro_id = l.id
                ) AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                ORDER BY l.titolo ASC
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getById(int $id): ?array
    {
        // Check if sottogenere_id column exists
        $hasSottogenere = $this->hasColumn('sottogenere_id');

        $sql = "SELECT l.*, e.nome AS editore_nome,
                       g.nome AS genere_nome,
                       g.id AS genere_id_resolved,
                       g.parent_id AS genere_parent_id,
                       gp.nome AS radice_nome,
                       gp.id AS radice_id,
                       gpp.nome AS nonno_nome,
                       gpp.id AS nonno_id";

        // Add sottogenere fields conditionally
        if ($hasSottogenere) {
            $sql .= ", sg.nome AS sottogenere_nome";
        } else {
            $sql .= ", NULL AS sottogenere_nome";
        }

        $sql .= ", p.id AS posizione_id_join,
                       m.numero_livello AS mensola_livello,
                       s.codice AS scaffale_codice,
                       s.nome   AS scaffale_nome
                FROM libri l
                LEFT JOIN editori e ON l.editore_id=e.id
                LEFT JOIN generi g ON l.genere_id=g.id
                LEFT JOIN generi gp ON g.parent_id = gp.id
                LEFT JOIN generi gpp ON gp.parent_id = gpp.id";

        // Add sottogenere join conditionally
        if ($hasSottogenere) {
            $sql .= " LEFT JOIN generi sg ON l.sottogenere_id=sg.id";
        }

        $sql .= " LEFT JOIN posizioni p ON l.posizione_id = p.id
                LEFT JOIN mensole m ON p.mensola_id = m.id
                LEFT JOIN scaffali s ON p.scaffale_id = s.id
                WHERE l.id=? LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        if (!$row) return null;

        // Normalize genre hierarchy
        // Query currently returns:
        // g.nome = Level 3 (sottogenere)
        // gp.nome = Level 2 (genere) - but labeled as radice_nome
        // gpp.nome = Level 1 (radice) - labeled as nonno_nome
        if (!empty($row['nonno_id'])) {
            // This is a 3-level structure, reorganize the levels:
            $row['sottogenere_nome'] = $row['genere_nome'];  // Level 3
            $row['genere_nome'] = $row['radice_nome'];        // Level 2
            $row['radice_nome'] = $row['nonno_nome'];         // Level 1
            $row['radice_id'] = $row['nonno_id'];
        }

        // authors list (order by ordine_credito if column exists)
        // Whitelist ORDER BY per prevenire SQL injection
        $hasOrdineCredito = $this->hasColumnInTable('libri_autori', 'ordine_credito');
        $orderClause = $hasOrdineCredito
            ? 'ORDER BY la.ordine_credito, a.nome'
            : 'ORDER BY a.nome';
        $stmt2 = $this->db->prepare("SELECT a.id, a.nome FROM libri_autori la JOIN autori a ON la.autore_id=a.id WHERE la.libro_id=? $orderClause");
        $stmt2->bind_param('i', $id);
        $stmt2->execute();
        $authorsRes = $stmt2->get_result();
        $row['autori'] = [];
        while ($a = $authorsRes->fetch_assoc()) { $row['autori'][] = $a; }

        // Plugin hook: Allow plugins to extend book data
        $row = \App\Support\Hooks::apply('book.data.get', $row, [$id]);

        return $row;
    }

    public function getByAuthorId(int $authorId): array
    {
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato,
                       e.nome AS editore_nome,
                       (
                         SELECT GROUP_CONCAT(a.nome SEPARATOR ', ')
                         FROM libri_autori la
                         JOIN autori a ON la.autore_id = a.id
                         WHERE la.libro_id = l.id
                       ) AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                INNER JOIN libri_autori la ON l.id = la.libro_id
                WHERE la.autore_id = ?
                ORDER BY l.titolo ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $authorId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function getByPublisherId(int $publisherId): array
    {
        $sql = "SELECT l.id, l.titolo, l.isbn10, l.isbn13, l.data_acquisizione, l.stato,
                       e.nome AS editore_nome,
                       (
                         SELECT GROUP_CONCAT(a.nome SEPARATOR ', ')
                         FROM libri_autori la
                         JOIN autori a ON la.autore_id = a.id
                         WHERE la.libro_id = l.id
                       ) AS autori
                FROM libri l
                LEFT JOIN editori e ON l.editore_id = e.id
                WHERE l.editore_id = ?
                ORDER BY l.titolo ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $publisherId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function createBasic(array $data): int
    {
        $hasSottogenere = $this->hasColumn('sottogenere_id');

        $scaffale_id_val = $data['scaffale_id'] ?? null;
        if ($scaffale_id_val !== null) {
            $scaffale_id_val = (int)$scaffale_id_val;
            if ($scaffale_id_val <= 0) {
                $scaffale_id_val = null;
            }
        }

        $mensola_id_val = $data['mensola_id'] ?? null;
        if ($mensola_id_val !== null) {
            $mensola_id_val = (int)$mensola_id_val;
            if ($mensola_id_val <= 0) {
                $mensola_id_val = null;
            }
        }

        $posizione_progressiva_val = $data['posizione_progressiva'] ?? null;
        if ($posizione_progressiva_val !== null) {
            $posizione_progressiva_val = (int)$posizione_progressiva_val;
            if ($posizione_progressiva_val <= 0) {
                $posizione_progressiva_val = null;
            }
        }

        $collocazione = '';
        if (!empty($data['collocazione'])) {
            $collocazione = (string)$data['collocazione'];
        } else {
            $collocazione = $this->buildCollocazioneString($scaffale_id_val, $mensola_id_val, $posizione_progressiva_val);
        }

        $posizione_id_val = !empty($data['posizione_id']) ? (int)$data['posizione_id'] : null;

        // Normalize optional dates: store NULL when empty
        $data_acquisizione = $data['data_acquisizione'] ?? null;
        if ($data_acquisizione === '' || $data_acquisizione === null) {
            $data_acquisizione = null;
        }
        $data_pubblicazione = $data['data_pubblicazione'] ?? null;
        if ($data_pubblicazione === '') {
            $data_pubblicazione = null;
        }

        // Normalize codes to avoid unique conflicts on empty strings
        $isbn10 = trim((string)($data['isbn10'] ?? ''));
        $isbn10 = $isbn10 === '' ? null : $isbn10;
        $isbn13 = trim((string)($data['isbn13'] ?? ''));
        $isbn13 = $isbn13 === '' ? null : $isbn13;
        $ean = trim((string)($data['ean'] ?? ''));
        $ean = $ean === '' ? null : $ean;

        // Scalars that may be nullable
        $peso = $data['peso'] ?? null;
        if ($peso === '' || $peso === null) {
            $peso = null;
        } else {
            $peso = (float)$peso;
        }
        $prezzo = $data['prezzo'] ?? null;
        if ($prezzo === '' || $prezzo === null) {
            $prezzo = null;
        } else {
            $prezzo = (float)$prezzo;
        }

        $genere_id_val = $data['genere_id'] ?? null;
        $sottogenere_id_val = $data['sottogenere_id'] ?? null;
        $editore_id_val = $data['editore_id'] ?? null;

        $copie_totali = isset($data['copie_totali']) ? (int)$data['copie_totali'] : 1;
        $copie_disponibili = isset($data['copie_disponibili']) ? (int)$data['copie_disponibili'] : 1;

        $tipo_acquisizione = $this->normalizeEnumValue($data['tipo_acquisizione'] ?? null, 'tipo_acquisizione', 'acquisto');
        $stato = $this->normalizeEnumValue($data['stato'] ?? null, 'stato', 'disponibile');

        $fields = [];
        $placeholders = [];
        $typeParts = [];
        $bindParams = [];
        $addField = function (string $column, string $type, $value) use (&$fields, &$placeholders, &$typeParts, &$bindParams) {
            $fields[] = $column;
            $placeholders[] = '?';
            $typeParts[] = $type;
            $bindParams[] = $value;
        };

        $addField('titolo', 's', \App\Support\HtmlHelper::decode($data['titolo'] ?? ''));
        $addField('sottotitolo', 's', \App\Support\HtmlHelper::decode($data['sottotitolo'] ?? null));
        $addField('isbn10', 's', $isbn10);
        $addField('isbn13', 's', $isbn13);
        if ($this->hasColumn('ean')) {
            $addField('ean', 's', $ean);
        }
        $addField('genere_id', 'i', $genere_id_val);
        if ($hasSottogenere) {
            $addField('sottogenere_id', 'i', $sottogenere_id_val);
        }
        $addField('editore_id', 'i', $editore_id_val);
        $addField('data_acquisizione', 's', $data_acquisizione);
        if ($this->hasColumn('data_pubblicazione')) {
            $addField('data_pubblicazione', 's', $data_pubblicazione);
        }
        $addField('tipo_acquisizione', 's', $tipo_acquisizione);
        if ($this->hasColumn('copertina_url')) {
            $addField('copertina_url', 's', $data['copertina_url'] ?? null);
        }
        if ($this->hasColumn('descrizione')) {
            $addField('descrizione', 's', $data['descrizione'] ?? null);
        }
        if ($this->hasColumn('parole_chiave')) {
            $addField('parole_chiave', 's', $data['parole_chiave'] ?? null);
        }
        if ($this->hasColumn('formato')) {
            $addField('formato', 's', $data['formato'] ?? null);
        }
        if ($this->hasColumn('peso')) {
            $addField('peso', 'd', $peso);
        }
        if ($this->hasColumn('dimensioni')) {
            $addField('dimensioni', 's', $data['dimensioni'] ?? null);
        }
        if ($this->hasColumn('prezzo')) {
            $addField('prezzo', 'd', $prezzo);
        }
        if ($this->hasColumn('scaffale_id')) {
            $addField('scaffale_id', 'i', $scaffale_id_val);
        }
        if ($this->hasColumn('mensola_id')) {
            $addField('mensola_id', 'i', $mensola_id_val);
        }
        if ($this->hasColumn('posizione_progressiva')) {
            $addField('posizione_progressiva', 'i', $posizione_progressiva_val);
        }
        if ($this->hasColumn('copie_totali')) {
            $addField('copie_totali', 'i', $copie_totali);
        }
        if ($this->hasColumn('copie_disponibili')) {
            $addField('copie_disponibili', 'i', $copie_disponibili);
        }
        if ($this->hasColumn('numero_inventario')) {
            $addField('numero_inventario', 's', $data['numero_inventario'] ?? null);
        }
        if ($this->hasColumn('classificazione_dowey')) {
            $addField('classificazione_dowey', 's', $data['classificazione_dowey'] ?? null);
        }
        if ($this->hasColumn('collana')) {
            $addField('collana', 's', $data['collana'] ?? null);
        }
        if ($this->hasColumn('numero_serie')) {
            $addField('numero_serie', 's', $data['numero_serie'] ?? null);
        }
        if ($this->hasColumn('note_varie')) {
            $addField('note_varie', 's', $data['note_varie'] ?? null);
        }
        if ($this->hasColumn('file_url')) {
            $addField('file_url', 's', $data['file_url'] ?? null);
        }
        if ($this->hasColumn('audio_url')) {
            $addField('audio_url', 's', $data['audio_url'] ?? null);
        }
        if ($this->hasColumn('collocazione')) {
            $addField('collocazione', 's', $collocazione);
        }
        if ($this->hasColumn('posizione_id')) {
            $addField('posizione_id', 'i', $posizione_id_val);
        }
        if ($this->hasColumn('stato')) {
            $addField('stato', 's', $stato);
        }

        $sql = 'INSERT INTO libri (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->db->prepare($sql);
        $bindTypes = implode('', $typeParts);

        $this->logDebug('createBasic.bind.pre', [
            'types' => $bindTypes,
            'preview' => [
                'titolo' => $data['titolo'] ?? null,
                'isbn10' => $isbn10,
                'isbn13' => $isbn13,
                'ean' => $ean,
                'genere_id' => $genere_id_val,
                'sottogenere_id' => $sottogenere_id_val,
                'editore_id' => $editore_id_val,
                'tipo_acquisizione' => $tipo_acquisizione,
                'stato' => $stato,
                'posizione_id' => $posizione_id_val,
                'scaffale_id' => $scaffale_id_val,
                'mensola_id' => $mensola_id_val,
                'posizione_progressiva' => $posizione_progressiva_val,
            ],
            'field_count' => count($fields),
            'full_data_keys' => array_keys($data),
        ]);

        $stmt->bind_param($bindTypes, ...$bindParams);
        try {
            $stmt->execute();
            $this->logDebug('createBasic.execute.ok', ['insert_id' => (int)$this->db->insert_id]);
        } catch (\Throwable $e) {
            $this->logDebug('createBasic.execute.error', [
                'error' => $e->getMessage(),
                'code' => (int)$e->getCode(),
                'mysqli_error' => $stmt->error,
            ]);
            throw $e;
        }

        $bookId = (int)$this->db->insert_id;
        $this->syncAuthors($bookId, $data['autori_ids'] ?? []);
        return $bookId;
    }

    public function updateBasic(int $id, array $data): bool
    {
        $hasSottogenere = $this->hasColumn('sottogenere_id');

        $scaffale_id_val = $data['scaffale_id'] ?? null;
        if ($scaffale_id_val !== null) {
            $scaffale_id_val = (int)$scaffale_id_val;
            if ($scaffale_id_val <= 0) {
                $scaffale_id_val = null;
            }
        }

        $mensola_id_val = $data['mensola_id'] ?? null;
        if ($mensola_id_val !== null) {
            $mensola_id_val = (int)$mensola_id_val;
            if ($mensola_id_val <= 0) {
                $mensola_id_val = null;
            }
        }

        $posizione_progressiva_val = $data['posizione_progressiva'] ?? null;
        if ($posizione_progressiva_val !== null) {
            $posizione_progressiva_val = (int)$posizione_progressiva_val;
            if ($posizione_progressiva_val <= 0) {
                $posizione_progressiva_val = null;
            }
        }

        $collocazione = '';
        if (!empty($data['collocazione'])) {
            $collocazione = (string)$data['collocazione'];
        } else {
            $collocazione = $this->buildCollocazioneString($scaffale_id_val, $mensola_id_val, $posizione_progressiva_val);
        }

        $posizione_id_val = !empty($data['posizione_id']) ? (int)$data['posizione_id'] : null;

        $data_acquisizione = $data['data_acquisizione'] ?? null;
        if ($data_acquisizione === '' || $data_acquisizione === null) {
            $data_acquisizione = null;
        }
        $data_pubblicazione = $data['data_pubblicazione'] ?? null;
        if ($data_pubblicazione === '') {
            $data_pubblicazione = null;
        }

        $isbn10_upd = trim((string)($data['isbn10'] ?? ''));
        $isbn10_upd = $isbn10_upd === '' ? null : $isbn10_upd;
        $isbn13_upd = trim((string)($data['isbn13'] ?? ''));
        $isbn13_upd = $isbn13_upd === '' ? null : $isbn13_upd;
        $ean = trim((string)($data['ean'] ?? ''));
        $ean = $ean === '' ? null : $ean;

        $peso = $data['peso'] ?? null;
        if ($peso === '' || $peso === null) {
            $peso = null;
        } else {
            $peso = (float)$peso;
        }
        $prezzo = $data['prezzo'] ?? null;
        if ($prezzo === '' || $prezzo === null) {
            $prezzo = null;
        } else {
            $prezzo = (float)$prezzo;
        }

        $genere_id_val = $data['genere_id'] ?? null;
        $sottogenere_id_val = $data['sottogenere_id'] ?? null;
        $editore_id_val = $data['editore_id'] ?? null;

        $copie_totali = isset($data['copie_totali']) ? (int)$data['copie_totali'] : 1;
        $copie_disponibili = isset($data['copie_disponibili']) ? (int)$data['copie_disponibili'] : 1;

        $tipo_acquisizione = $this->normalizeEnumValue($data['tipo_acquisizione'] ?? null, 'tipo_acquisizione', 'acquisto');
        $stato = $this->normalizeEnumValue($data['stato'] ?? null, 'stato', 'disponibile');

        $setParts = [];
        $typeParts = [];
        $bindParams = [];
        $addSet = function (string $column, string $type, $value) use (&$setParts, &$typeParts, &$bindParams) {
            $setParts[] = "$column=?";
            $typeParts[] = $type;
            $bindParams[] = $value;
        };

        $addSet('titolo', 's', \App\Support\HtmlHelper::decode($data['titolo'] ?? ''));
        $addSet('sottotitolo', 's', \App\Support\HtmlHelper::decode($data['sottotitolo'] ?? null));
        $addSet('isbn10', 's', $isbn10_upd);
        $addSet('isbn13', 's', $isbn13_upd);
        if ($this->hasColumn('ean')) {
            $addSet('ean', 's', $ean);
        }
        $addSet('genere_id', 'i', $genere_id_val);
        if ($hasSottogenere) {
            $addSet('sottogenere_id', 'i', $sottogenere_id_val);
        }
        $addSet('editore_id', 'i', $editore_id_val);
        $addSet('data_acquisizione', 's', $data_acquisizione);
        if ($this->hasColumn('data_pubblicazione')) {
            $addSet('data_pubblicazione', 's', $data_pubblicazione);
        }
        $addSet('tipo_acquisizione', 's', $tipo_acquisizione);
        if ($this->hasColumn('copertina_url')) {
            $addSet('copertina_url', 's', $data['copertina_url'] ?? null);
        }
        if ($this->hasColumn('descrizione')) {
            $addSet('descrizione', 's', $data['descrizione'] ?? null);
        }
        if ($this->hasColumn('parole_chiave')) {
            $addSet('parole_chiave', 's', $data['parole_chiave'] ?? null);
        }
        if ($this->hasColumn('formato')) {
            $addSet('formato', 's', $data['formato'] ?? null);
        }
        if ($this->hasColumn('peso')) {
            $addSet('peso', 'd', $peso);
        }
        if ($this->hasColumn('dimensioni')) {
            $addSet('dimensioni', 's', $data['dimensioni'] ?? null);
        }
        if ($this->hasColumn('prezzo')) {
            $addSet('prezzo', 'd', $prezzo);
        }
        if ($this->hasColumn('scaffale_id')) {
            $addSet('scaffale_id', 'i', $scaffale_id_val);
        }
        if ($this->hasColumn('mensola_id')) {
            $addSet('mensola_id', 'i', $mensola_id_val);
        }
        if ($this->hasColumn('posizione_progressiva')) {
            $addSet('posizione_progressiva', 'i', $posizione_progressiva_val);
        }
        if ($this->hasColumn('copie_totali')) {
            $addSet('copie_totali', 'i', $copie_totali);
        }
        if ($this->hasColumn('copie_disponibili')) {
            $addSet('copie_disponibili', 'i', $copie_disponibili);
        }
        if ($this->hasColumn('numero_inventario')) {
            $addSet('numero_inventario', 's', $data['numero_inventario'] ?? null);
        }
        if ($this->hasColumn('classificazione_dowey')) {
            $addSet('classificazione_dowey', 's', $data['classificazione_dowey'] ?? null);
        }
        if ($this->hasColumn('collana')) {
            $addSet('collana', 's', $data['collana'] ?? null);
        }
        if ($this->hasColumn('numero_serie')) {
            $addSet('numero_serie', 's', $data['numero_serie'] ?? null);
        }
        if ($this->hasColumn('note_varie')) {
            $addSet('note_varie', 's', $data['note_varie'] ?? null);
        }
        if ($this->hasColumn('file_url')) {
            $addSet('file_url', 's', $data['file_url'] ?? null);
        }
        if ($this->hasColumn('audio_url')) {
            $addSet('audio_url', 's', $data['audio_url'] ?? null);
        }
        if ($this->hasColumn('collocazione')) {
            $addSet('collocazione', 's', $collocazione);
        }
        if ($this->hasColumn('posizione_id')) {
            $addSet('posizione_id', 'i', $posizione_id_val);
        }
        if ($this->hasColumn('stato')) {
            $addSet('stato', 's', $stato);
        }

        $sql = 'UPDATE libri SET ' . implode(', ', $setParts) . ', updated_at=NOW() WHERE id=?';
        $stmt = $this->db->prepare($sql);

        $bindTypes = implode('', $typeParts) . 'i';
        $bindParams[] = $id;

        $this->logDebug('updateBasic.bind.pre', [
            'types' => $bindTypes,
            'id' => $id,
            'preview' => [
                'titolo' => $data['titolo'] ?? null,
                'tipo_acquisizione' => $tipo_acquisizione,
                'stato' => $stato,
                'posizione_id' => $posizione_id_val,
                'scaffale_id' => $scaffale_id_val,
                'mensola_id' => $mensola_id_val,
                'posizione_progressiva' => $posizione_progressiva_val,
            ],
            'field_count' => count($setParts),
            'full_data_keys' => array_keys($data),
        ]);

        $stmt->bind_param($bindTypes, ...$bindParams);
        try {
            $ok = $stmt->execute();
            $this->logDebug('updateBasic.execute.ok', ['id' => $id, 'ok' => $ok]);
        } catch (\Throwable $e) {
            $this->logDebug('updateBasic.execute.error', [
                'error' => $e->getMessage(),
                'code' => (int)$e->getCode(),
                'mysqli_error' => $stmt->error,
            ]);
            throw $e;
        }

        $this->syncAuthors($id, $data['autori_ids'] ?? []);
        return $ok;
    }

    private function normalizeEnumValue(?string $value, string $column, string $default): string
    {
        if (!$this->hasColumn($column)) {
            return $default;
        }

        $options = $this->getEnumOptions('libri', $column);
        if (!$options) {
            return $default;
        }

        $candidate = trim((string)$value);
        if ($candidate === '') {
            return in_array($default, $options, true) ? $default : $options[0];
        }

        foreach ($options as $option) {
            if (strcasecmp($option, $candidate) === 0) {
                return $option;
            }
        }

        return in_array($default, $options, true) ? $default : $options[0];
    }

    private function syncAuthors(int $bookId, array $authorIds): void
    {
        $stmt = $this->db->prepare('DELETE FROM libri_autori WHERE libro_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $bookId);
            $stmt->execute();
            $stmt->close();
        } else {
            // If prepared statement fails, log the error and throw exception
            error_log("Critical error: Unable to prepare statement for deleting book authors for book_id: $bookId");
            throw new \Exception("Database error: unable to delete book authors");
        }
        if (!$authorIds) return;
        
        $stmt = $this->db->prepare("INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (?, ?, 'principale')");
        foreach ($authorIds as $authorData) {
            $authorId = $this->processAuthorId($authorData);
            if ($authorId > 0) {
                $stmt->bind_param('ii', $bookId, $authorId);
                $stmt->execute();
            }
        }
    }
    
    private function processAuthorId($authorData): int
    {
        // Handle both old format (just ID) and new format (could be temp ID with label)
        if (is_numeric($authorData)) {
            return (int)$authorData;
        }
        
        // Handle new author format from Choices.js (new_timestamp)
        if (is_string($authorData) && strpos($authorData, 'new_') === 0) {
            // This is a new author that needs to be created
            // For now, just skip it - this would need additional handling
            // based on your form submission logic
            return 0;
        }
        
        return (int)$authorData;
    }

    private function getScaffaleLetter(int $scaffaleId): ?string
    {
        $stmt = $this->db->prepare('SELECT lettera FROM scaffali WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $scaffaleId);
        $stmt->execute();
        $stmt->bind_result($lettera);
        if ($stmt->fetch()) { return $lettera; }
        return null;
    }

    private function getMensolaLevel(int $mensolaId): ?int
    {
        $stmt = $this->db->prepare('SELECT numero_livello FROM mensole WHERE id=? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $mensolaId);
        $stmt->execute();
        $stmt->bind_result($level);
        if ($stmt->fetch()) {
            return $level !== null ? (int)$level : null;
        }
        return null;
    }

    private function buildCollocazioneString(?int $scaffaleId, ?int $mensolaId, ?int $posizioneProgressiva): string
    {
        if (!$scaffaleId || !$mensolaId || !$posizioneProgressiva) {
            return '';
        }

        $lettera = $this->getScaffaleLetter($scaffaleId);
        if ($lettera === null || $lettera === '') {
            return '';
        }

        $level = $this->getMensolaLevel($mensolaId);
        if ($level === null) {
            return '';
        }

        return sprintf('%s-%d-%02d', strtoupper(trim($lettera)), $level, $posizioneProgressiva);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM libri_autori WHERE libro_id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM prestiti WHERE libro_id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt = $this->db->prepare('DELETE FROM libri WHERE id=?');
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function updateOptionals(int $bookId, array $data): void
    {
        $cols = [];
        foreach (['numero_pagine','ean','data_pubblicazione','anno_pubblicazione','traduttore','collana','edizione'] as $c) {
            if ($this->hasColumn($c) && array_key_exists($c, $data) && $data[$c] !== '' && $data[$c] !== null) {
                $cols[$c] = $data[$c];
            }
        }
        // Map scraped_* into columns if present
        if ($this->hasColumn('numero_pagine') && !isset($cols['numero_pagine']) && !empty($data['scraped_pages'])) {
            $cols['numero_pagine'] = (int)$data['scraped_pages'];
        }
        if ($this->hasColumn('ean') && !isset($cols['ean']) && !empty($data['scraped_ean'])) {
            $cols['ean'] = (string)$data['scraped_ean'];
        }
        if ($this->hasColumn('data_pubblicazione') && !isset($cols['data_pubblicazione']) && !empty($data['scraped_pub_date'])) {
            $cols['data_pubblicazione'] = (string)$data['scraped_pub_date'];
        }
        if ($this->hasColumn('collana') && !isset($cols['collana']) && !empty($data['scraped_series'])) {
            $cols['collana'] = (string)$data['scraped_series'];
        }
        if ($this->hasColumn('traduttore') && !isset($cols['traduttore']) && !empty($data['scraped_translator'])) {
            $cols['traduttore'] = (string)$data['scraped_translator'];
        }
        if (!$cols) return;
        $set=[]; $types=''; $vals=[];
        foreach ($cols as $k=>$v) {
            $set[] = "$k = ?";
            if ($k === 'numero_pagine' || $k === 'anno_pubblicazione') { $types.='i'; $vals[]=(int)$v; }
            else { $types.='s'; $vals[]=(string)$v; }
        }
        $sql = 'UPDATE libri SET '.implode(',', $set).', updated_at=NOW() WHERE id=?';
        $types .= 'i'; $vals[]=$bookId;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
    }

    private ?array $columnCache = null;
    private function hasColumn(string $name): bool
    {
        if ($this->columnCache === null) {
            $this->columnCache = [];
            $res = $this->db->query('SHOW COLUMNS FROM libri');
            if ($res) {
                while ($r = $res->fetch_assoc()) { $this->columnCache[$r['Field']] = true; }
            }
        }
        return isset($this->columnCache[$name]);
    }

    private array $tableColumnCache = [];
    private function hasColumnInTable(string $table, string $name): bool
    {
        // Whitelist di tabelle valide per prevenire SQL injection
        $validTables = ['libri', 'autori', 'libri_autori', 'editori', 'generi',
                        'utenti', 'prestiti', 'prenotazioni', 'posizioni', 'scaffali', 'mensole'];

        if (!in_array($table, $validTables, true)) {
            return false;
        }

        if (!isset($this->tableColumnCache[$table])) {
            $this->tableColumnCache[$table] = [];
            // Usa prepared statement con INFORMATION_SCHEMA
            $stmt = $this->db->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            if ($stmt) {
                $stmt->bind_param('s', $table);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $this->tableColumnCache[$table][$r['COLUMN_NAME']] = true;
                }
                $stmt->close();
            }
        }
        return isset($this->tableColumnCache[$table][$name]);
    }

    // Cache for enum options
    private array $enumCache = [];

    private function getEnumOptions(string $table, string $column): array
    {
        $key = $table.'.'.$column;
        if (isset($this->enumCache[$key])) return $this->enumCache[$key];
        $opts = [];
        $stmt = $this->db->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ss', $table, $column);
            if ($stmt->execute()) {
                $stmt->bind_result($columnType);
                if ($stmt->fetch() && preg_match("/enum\\((.*)\\)/i", (string)$columnType, $m)) {
                    $vals = str_getcsv($m[1], ',', "'", "\\");
                    foreach ($vals as $v) {
                        $opts[] = $v;
                    }
                }
            }
            $stmt->close();
        }
        return $this->enumCache[$key] = $opts;
    }
}
