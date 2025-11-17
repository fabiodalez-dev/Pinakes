<?php
/**
 * CQL (Contextual Query Language) Parser
 *
 * Parses CQL queries and converts them to database conditions.
 * Supports basic CQL syntax: index relation searchTerm
 *
 * Examples:
 * - dc.title = "Harry Potter"
 * - dc.creator = "Rowling"
 * - bath.isbn = "9780439708180"
 * - cql.anywhere = "fantasy"
 *
 * @see https://www.loc.gov/standards/sru/cql/
 */

declare(strict_types=1);

namespace Z39Server;

class CQLParser
{
    /**
     * Parse CQL query into conditions
     *
     * @param string $query CQL query string
     * @return array Array of conditions
     * @throws \Exception If query syntax is invalid
     */
    public function parse(string $query): array
    {
        $query = trim($query);

        if (empty($query)) {
            throw new \Exception('Empty query');
        }

        // Simple parser for basic CQL queries
        // Format: index relation searchTerm
        // Examples:
        //   dc.title = "Harry Potter"
        //   dc.creator = Rowling
        //   bath.isbn = 9780439708180

        $conditions = [];

        // Handle simple queries (just a search term, no index specified)
        if (!preg_match('/[=<>]/', $query) && !preg_match('/\b(and|or|not)\b/i', $query)) {
            // Simple keyword search
            $conditions[] = [
                'index' => 'cql.anywhere',
                'relation' => '=',
                'value' => $this->unquote($query)
            ];
            return $conditions;
        }

        // Split by boolean operators (simplified)
        $parts = preg_split('/\b(and|or|not)\b/i', $query, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $part) {
            $part = trim($part);

            // Skip boolean operators for now (in a full implementation, we'd build a tree)
            if (in_array(strtolower($part), ['and', 'or', 'not']) || empty($part)) {
                continue;
            }

            // Parse: index relation value
            if (preg_match('/^([a-z0-9_.]+)\s*(=|==|exact|all|any|<|>|<=|>=)\s*(.+)$/i', $part, $matches)) {
                $conditions[] = [
                    'index' => trim($matches[1]),
                    'relation' => trim($matches[2]),
                    'value' => $this->unquote(trim($matches[3]))
                ];
            } else {
                // If it doesn't match the pattern, treat it as a keyword search
                $conditions[] = [
                    'index' => 'cql.anywhere',
                    'relation' => '=',
                    'value' => $this->unquote($part)
                ];
            }
        }

        return $conditions;
    }

    /**
     * Remove quotes from search term
     *
     * @param string $term Search term
     * @return string Unquoted term
     */
    private function unquote(string $term): string
    {
        $term = trim($term);

        // Remove surrounding quotes
        if ((substr($term, 0, 1) === '"' && substr($term, -1) === '"') ||
            (substr($term, 0, 1) === "'" && substr($term, -1) === "'")) {
            $term = substr($term, 1, -1);
        }

        return $term;
    }
}
