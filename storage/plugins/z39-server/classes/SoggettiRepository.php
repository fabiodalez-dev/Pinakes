<?php

declare(strict_types=1);

namespace Z39Server;

use mysqli;

/**
 * Persistence for Nuovo Soggettario subject headings — issue #133.
 *
 * Owns the `soggetti` dictionary and the `libri_soggetti` junction created by
 * {@see \Z39ServerPlugin::ensureSchema()}. A subject is deduplicated by its
 * BNCF identifier when present (controlled term); free-text subjects with no
 * BNCF id are matched by exact label within the 'libero' scheme.
 */
class SoggettiRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /**
     * Find an existing subject or create it, returning its id.
     *
     * @param array{termine:string,bncf_id?:?string,uri?:?string,tipo?:?string} $subject
     */
    public function findOrCreate(array $subject): int
    {
        $termine = trim($subject['termine']);
        if ($termine === '') {
            return 0;
        }
        $bncfId = isset($subject['bncf_id']) ? trim((string) $subject['bncf_id']) : '';
        $uri    = isset($subject['uri']) ? trim((string) $subject['uri']) : '';
        $tipo   = isset($subject['tipo']) ? trim((string) $subject['tipo']) : '';
        $schema = $bncfId !== '' ? 'nuovo-soggettario' : 'libero';

        // Dedup: controlled terms by bncf_id, free terms by (schema, label).
        if ($bncfId !== '') {
            $existing = $this->scalarId('SELECT id FROM soggetti WHERE bncf_id = ? LIMIT 1', 's', [$bncfId]);
        } else {
            $existing = $this->scalarId(
                'SELECT id FROM soggetti WHERE schema_soggetto = ? AND termine = ? LIMIT 1',
                'ss',
                ['libero', $termine]
            );
        }
        if ($existing > 0) {
            return $existing;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO soggetti (termine, schema_soggetto, bncf_id, uri, tipo) VALUES (?, ?, ?, ?, ?)'
        );
        if ($stmt === false) {
            return 0;
        }
        $bncfParam = $bncfId !== '' ? $bncfId : null;
        $uriParam  = $uri !== '' ? $uri : null;
        $tipoParam = $tipo !== '' ? $tipo : null;
        $stmt->bind_param('sssss', $termine, $schema, $bncfParam, $uriParam, $tipoParam);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function attachToBook(int $libroId, int $soggettoId): void
    {
        if ($libroId <= 0 || $soggettoId <= 0) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO libri_soggetti (libro_id, soggetto_id) VALUES (?, ?)'
        );
        if ($stmt === false) {
            return;
        }
        $stmt->bind_param('ii', $libroId, $soggettoId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Replace all subjects attached to a book with the given set.
     *
     * @param list<array{termine:string,bncf_id?:?string,uri?:?string,tipo?:?string}> $subjects
     */
    public function syncBookSubjects(int $libroId, array $subjects): void
    {
        if ($libroId <= 0) {
            return;
        }
        $del = $this->db->prepare('DELETE FROM libri_soggetti WHERE libro_id = ?');
        if ($del !== false) {
            $del->bind_param('i', $libroId);
            $del->execute();
            $del->close();
        }
        foreach ($subjects as $subject) {
            $id = $this->findOrCreate($subject);
            if ($id > 0) {
                $this->attachToBook($libroId, $id);
            }
        }
    }

    /**
     * @return list<array{id:int,termine:string,bncf_id:?string,uri:?string,schema:string}>
     */
    public function listForBook(int $libroId): array
    {
        $out = [];
        if ($libroId <= 0) {
            return $out;
        }
        $stmt = $this->db->prepare(
            'SELECT s.id, s.termine, s.bncf_id, s.uri, s.schema_soggetto
             FROM libri_soggetti ls
             JOIN soggetti s ON s.id = ls.soggetto_id
             WHERE ls.libro_id = ?
             ORDER BY s.termine'
        );
        if ($stmt === false) {
            return $out;
        }
        $stmt->bind_param('i', $libroId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $out[] = [
                'id'      => (int) $row['id'],
                'termine' => (string) $row['termine'],
                'bncf_id' => $row['bncf_id'] !== null ? (string) $row['bncf_id'] : null,
                'uri'     => $row['uri'] !== null ? (string) $row['uri'] : null,
                'schema'  => (string) $row['schema_soggetto'],
            ];
        }
        $stmt->close();
        return $out;
    }

    /**
     * @param string $types mysqli bind_param type string
     * @param list<string> $params
     */
    private function scalarId(string $sql, string $types, array $params): int
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $id = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $id;
    }
}
