<?php

declare(strict_types=1);

namespace App\Plugins\BibframeLinkedData;

use mysqli;

/**
 * RDA Registry (rdaregistry.info) JSON-LD builder — issue #135.
 *
 * Emits the EURIG-aligned RDA Registry vocabulary as an alternative to the
 * plugin's default BIBFRAME 2.0 output. Models the FRBR/LRM stack:
 *   Manifestation (rdac:C10007) → Expression (rdac:C10006) → Work
 *   (rdac:C10001) → Agent (rdac:C10002), described with rdam:/rdae:/rdaw:
 *   properties
 *
 * Work/Expression cross-links are populated from the `opere`/`espressioni`
 * tables created by the frbr-lrm plugin (#134) WHEN PRESENT. If those tables
 * don't exist (frbr-lrm never activated) the builder degrades gracefully to a
 * Manifestation-only document — exactly the BIBFRAME-equivalent granularity.
 */
class RdaRegistryBuilder
{
    /**
     * RDA classes live in the Elements/c/ vocabulary under their canonical
     * numeric URIs; the m/e/w/a namespaces hold only properties.
     */
    private const CLASS_MANIFESTATION = 'rdac:C10007';
    private const CLASS_EXPRESSION    = 'rdac:C10006';
    private const CLASS_WORK          = 'rdac:C10001';
    private const CLASS_AGENT         = 'rdac:C10002';

    private const CONTEXT = [
        'rdac' => 'http://rdaregistry.info/Elements/c/',
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
        // @id is the public book page (book_url() is the canonical, locale-
        // and base-path-aware link), so the URI dereferences.
        $doc = [
            '@context' => self::CONTEXT,
            '@id'      => absoluteUrl(book_url($book)),
            '@type'    => self::CLASS_MANIFESTATION,
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
                    '@type'           => self::CLASS_EXPRESSION,
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
            '@type' => self::CLASS_EXPRESSION,
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
            '@type' => self::CLASS_WORK,
        ];
        $titolo = trim((string) ($row['titolo_uniforme'] ?? ''));
        if ($titolo !== '') {
            $node['rdaw:preferredTitleOfWork'] = $titolo;
        }
        if (!empty($row['autore_principale_id'])) {
            // The public author page (same link the book page uses).
            $node['rdaw:creator'] = [
                '@id'   => absoluteUrl(route_path('author') . '/' . (int) $row['autore_principale_id']),
                '@type' => self::CLASS_AGENT,
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
