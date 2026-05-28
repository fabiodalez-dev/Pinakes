<?php

declare(strict_types=1);

namespace App\Plugins\BibframeLinkedData;

use mysqli;

/**
 * RDA Registry (rdaregistry.info) JSON-LD builder — issue #135.
 *
 * Emits the EURIG-aligned RDA Registry vocabulary as an alternative to the
 * plugin's default BIBFRAME 2.0 output. Models the FRBR/LRM stack:
 *   rdam:Manifestation → rdae:Expression → rdaw:Work → rdaa:* (agents)
 *
 * Work/Expression cross-links are populated from the `opere`/`espressioni`
 * tables created by the frbr-lrm plugin (#134) WHEN PRESENT. If those tables
 * don't exist (frbr-lrm never activated) the builder degrades gracefully to a
 * Manifestation-only document — exactly the BIBFRAME-equivalent granularity.
 */
class RdaRegistryBuilder
{
    private const CONTEXT = [
        'rdam' => 'http://rdaregistry.info/Elements/m/',
        'rdae' => 'http://rdaregistry.info/Elements/e/',
        'rdaw' => 'http://rdaregistry.info/Elements/w/',
        'rdaa' => 'http://rdaregistry.info/Elements/a/',
        'xsd'  => 'http://www.w3.org/2001/XMLSchema#',
    ];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * Build the RDA JSON-LD document for a Manifestation (libro), nesting the
     * Expression and Work when the frbr-lrm tables link them.
     *
     * @param array<string, mixed> $book  row from `libri` (+ optional joins)
     * @return array<string, mixed>
     */
    public function buildManifestation(array $book): array
    {
        $id = (int) ($book['id'] ?? 0);
        $doc = [
            '@context' => self::CONTEXT,
            '@id'      => absoluteUrl('/libri/' . $id),
            '@type'    => 'rdam:Manifestation',
        ];

        $title = trim((string) ($book['titolo'] ?? ''));
        if ($title !== '') {
            $doc['rdam:titleProper'] = $title;
        }
        $subtitle = trim((string) ($book['sottotitolo'] ?? ''));
        if ($subtitle !== '') {
            $doc['rdam:otherTitleInformationOfManifestation'] = $subtitle;
        }
        $year = trim((string) ($book['anno_pubblicazione'] ?? ''));
        if ($year !== '') {
            $doc['rdam:dateOfPublication'] = ['@value' => $year, '@type' => 'xsd:gYear'];
        }
        $pages = (int) ($book['numero_pagine'] ?? 0);
        if ($pages > 0) {
            $doc['rdam:extent'] = $pages . ' p.';
        }
        $isbn = trim((string) ($book['isbn13'] ?? $book['isbn10'] ?? ''));
        if ($isbn !== '') {
            $doc['rdam:identifierForManifestation'] = $isbn;
        }
        $publisher = $this->fetchPublisherName($book);
        if ($publisher !== '') {
            $doc['rdam:publishersName'] = $publisher;
        }

        // Cross-link to Expression → Work when frbr-lrm linked this book.
        $expressionId = !empty($book['espressione_id']) ? (int) $book['espressione_id'] : null;
        $operaId      = !empty($book['opera_id']) ? (int) $book['opera_id'] : null;

        if ($expressionId !== null && $this->tableExists('espressioni')) {
            $expr = $this->buildExpressionNode($expressionId);
            if ($expr !== null) {
                $doc['rdam:expressionManifested'] = $expr;
                return $doc;
            }
        }
        // No Expression, but maybe a direct Work link.
        if ($operaId !== null && $this->tableExists('opere')) {
            $work = $this->buildWorkNode($operaId);
            if ($work !== null) {
                // Manifestation embeds an anonymous Expression that carries the Work.
                $doc['rdam:expressionManifested'] = [
                    '@type'           => 'rdae:Expression',
                    'rdae:workExpressed' => $work,
                ];
            }
        }
        return $doc;
    }

    /**
     * Standalone Work document (/opere/{id}.rda.json).
     *
     * @return array<string, mixed>|null
     */
    public function buildWork(int $operaId): ?array
    {
        if (!$this->tableExists('opere')) {
            return null;
        }
        $node = $this->buildWorkNode($operaId);
        if ($node === null) {
            return null;
        }
        return array_merge(['@context' => self::CONTEXT], $node);
    }

    /**
     * Standalone Expression document (/espressioni/{id}.rda.json).
     *
     * @return array<string, mixed>|null
     */
    public function buildExpression(int $espressioneId): ?array
    {
        if (!$this->tableExists('espressioni')) {
            return null;
        }
        $node = $this->buildExpressionNode($espressioneId);
        if ($node === null) {
            return null;
        }
        return array_merge(['@context' => self::CONTEXT], $node);
    }

    /** @return array<string, mixed>|null */
    private function buildExpressionNode(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM espressioni WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        $node = [
            '@id'   => absoluteUrl('/espressioni/' . $id),
            '@type' => 'rdae:Expression',
        ];
        $lingua = trim((string) ($row['lingua'] ?? ''));
        if ($lingua !== '') {
            $node['rdae:languageOfExpression'] = $lingua;
        }
        $titolo = trim((string) ($row['titolo_espressione'] ?? ''));
        if ($titolo !== '') {
            $node['rdae:titleOfExpression'] = $titolo;
        }
        if (!empty($row['opera_id'])) {
            $work = $this->buildWorkNode((int) $row['opera_id']);
            if ($work !== null) {
                $node['rdae:workExpressed'] = $work;
            }
        }
        return $node;
    }

    /** @return array<string, mixed>|null */
    private function buildWorkNode(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM opere WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return null;
        }
        $node = [
            '@id'   => absoluteUrl('/opere/' . $id),
            '@type' => 'rdaw:Work',
        ];
        $titolo = trim((string) ($row['titolo_uniforme'] ?? ''));
        if ($titolo !== '') {
            $node['rdaw:preferredTitleOfWork'] = $titolo;
        }
        if (!empty($row['autore_principale_id'])) {
            $node['rdaw:creator'] = [
                '@id'   => absoluteUrl('/autori/' . (int) $row['autore_principale_id']),
                '@type' => 'rdaa:Agent',
            ];
        }
        return $node;
    }

    /** @param array<string, mixed> $book */
    private function fetchPublisherName(array $book): string
    {
        if (empty($book['editore_id'])) {
            return '';
        }
        $stmt = $this->db->prepare('SELECT nome FROM editori WHERE id = ? LIMIT 1');
        if ($stmt === false) {
            return '';
        }
        $eid = (int) $book['editore_id'];
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return is_array($row) ? trim((string) ($row['nome'] ?? '')) : '';
    }

    /** Cache table-existence checks per request. @var array<string,bool> */
    private array $tableCache = [];

    private function tableExists(string $table): bool
    {
        if (isset($this->tableCache[$table])) {
            return $this->tableCache[$table];
        }
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        if ($stmt === false) {
            return $this->tableCache[$table] = false;
        }
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $exists = (int) ($stmt->get_result()->fetch_row()[0] ?? 0) > 0;
        $stmt->close();
        return $this->tableCache[$table] = $exists;
    }
}
