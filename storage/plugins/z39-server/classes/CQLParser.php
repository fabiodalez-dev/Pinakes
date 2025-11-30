<?php
declare(strict_types=1);

namespace Z39Server;

use Z39Server\Exceptions\InvalidCQLSyntaxException;
use Z39Server\Exceptions\UnsupportedRelationException;

/**
 * Minimal CQL parser that builds an AST supporting boolean operators, parentheses
 * and the basic relation operators defined by SRU 1.2.
 */
class CQLParser
{
    private array $tokens = [];
    private int $position = 0;
    private int $depth = 0;

    // DOS PROTECTION: Configurable limits
    private const MAX_QUERY_LENGTH = 2000;
    private const MAX_TOKENS = 500;
    private const MAX_NESTING_DEPTH = 20;

    /**
     * Parse a CQL query string into an AST representation.
     *
     * @throws \Exception when the syntax is invalid.
     */
    public function parse(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new InvalidCQLSyntaxException('Empty query');
        }

        // DOS PROTECTION: Limit query length
        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new InvalidCQLSyntaxException('Query too long (max ' . self::MAX_QUERY_LENGTH . ' characters)');
        }

        $this->tokens = $this->tokenize($query);
        $this->position = 0;
        $this->depth = 0;

        // DOS PROTECTION: Limit number of tokens
        if (count($this->tokens) > self::MAX_TOKENS) {
            throw new InvalidCQLSyntaxException('Query too complex (max ' . self::MAX_TOKENS . ' tokens)');
        }

        $ast = $this->parseOrExpression();

        if ($this->peek() !== null) {
            throw new InvalidCQLSyntaxException('Unexpected token near "' . ($this->peek()['value'] ?? '') . '"');
        }

        return $ast;
    }

    /**
     * Tokenize the query into a flat list of symbols.
     */
    private function tokenize(string $query): array
    {
        $tokens = [];
        $length = strlen($query);
        $i = 0;

        while ($i < $length) {
            $char = $query[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if ($char === '(' || $char === ')') {
                $tokens[] = ['type' => $char, 'value' => $char];
                $i++;
                continue;
            }

            $twoChar = $i + 1 < $length ? substr($query, $i, 2) : null;
            if ($twoChar !== null && in_array($twoChar, ['>=', '<=', '<>', '=='], true)) {
                $tokens[] = ['type' => 'REL_OP', 'value' => $twoChar];
                $i += 2;
                continue;
            }

            if (in_array($char, ['=', '<', '>'], true)) {
                $tokens[] = ['type' => 'REL_OP', 'value' => $char];
                $i++;
                continue;
            }

            if ($char === '\"' || $char === "\'") {
                $quote = $char;
                $i++;
                $value = '';
                $closed = false;
                while ($i < $length) {
                    $current = $query[$i];
                    if ($current === $quote) {
                        $i++;
                        $closed = true;
                        break;
                    }
                    if ($current === '\\' && $i + 1 < $length) {
                        $value .= $query[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $value .= $current;
                    $i++;
                }
                if (!$closed) {
                    throw new InvalidCQLSyntaxException('Unterminated string literal');
                }
                $tokens[] = ['type' => 'STRING', 'value' => $value];
                continue;
            }

            $start = $i;
            while ($i < $length) {
                $current = $query[$i];
                if (ctype_space($current) || $current === '(' || $current === ')' || $current === '\"' || $current === "'" || $current === '=' || $current === '<' || $current === '>') {
                    break;
                }
                $i++;
            }

            if ($start === $i) {
                // Guard against infinite loop
                $i++;
                continue;
            }

            $word = substr($query, $start, $i - $start);
            $upper = strtoupper($word);
            $lower = strtolower($word);

            if (in_array($upper, ['AND', 'OR', 'NOT'], true)) {
                $tokens[] = ['type' => 'BOOLEAN', 'value' => $upper];
                continue;
            }

            if (in_array($lower, ['exact', 'all', 'any'], true)) {
                $tokens[] = ['type' => 'REL_WORD', 'value' => $lower];
                continue;
            }

            $tokens[] = ['type' => 'WORD', 'value' => $word];
        }

        return $tokens;
    }

    private function parseOrExpression(): array
    {
        $node = $this->parseAndExpression();

        while (($token = $this->peek()) !== null && $token['type'] === 'BOOLEAN' && $token['value'] === 'OR') {
            $this->consume();
            $right = $this->parseAndExpression();
            $node = [
                'type' => 'boolean',
                'operator' => 'OR',
                'left' => $node,
                'right' => $right,
            ];
        }

        return $node;
    }

    private function parseAndExpression(): array
    {
        $node = $this->parseNotExpression();

        while (($token = $this->peek()) !== null && $token['type'] === 'BOOLEAN' && $token['value'] === 'AND') {
            $this->consume();
            $right = $this->parseNotExpression();
            $node = [
                'type' => 'boolean',
                'operator' => 'AND',
                'left' => $node,
                'right' => $right,
            ];
        }

        return $node;
    }

    private function parseNotExpression(): array
    {
        $token = $this->peek();
        if ($token !== null && $token['type'] === 'BOOLEAN' && $token['value'] === 'NOT') {
            $this->consume();
            return [
                'type' => 'not',
                'operand' => $this->parseNotExpression(),
            ];
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        $token = $this->peek();
        if ($token === null) {
            throw new InvalidCQLSyntaxException('Unexpected end of query');
        }

        if ($token['type'] === '(') {
            // DOS PROTECTION: Track and limit nesting depth
            $this->depth++;
            if ($this->depth > self::MAX_NESTING_DEPTH) {
                throw new InvalidCQLSyntaxException('Query too complex (too many nested parentheses)');
            }

            $this->consume();
            $node = $this->parseOrExpression();
            if (($next = $this->peek()) === null || $next['type'] !== ')') {
                throw new InvalidCQLSyntaxException('Missing closing parenthesis');
            }
            $this->consume();
            $this->depth--;
            return $node;
        }

        return $this->parseCondition();
    }

    private function parseCondition(): array
    {
        $token = $this->peek();
        if ($token === null) {
            throw new InvalidCQLSyntaxException('Unexpected end of condition');
        }

        if ($token['type'] === 'WORD') {
            $next = $this->peek(1);
            if ($next !== null && $this->isRelationToken($next)) {
                $indexToken = $this->consume();
                $relation = $this->consumeRelation();
                $valueToken = $this->consumeValueToken();

                return [
                    'type' => 'condition',
                    'index' => strtolower($indexToken['value']),
                    'relation' => $relation,
                    'value' => $valueToken['value'],
                ];
            }
        }

        $valueToken = $this->consumeValueToken();
        return [
            'type' => 'condition',
            'index' => 'cql.anywhere',
            'relation' => '=',
            'value' => $valueToken['value'],
        ];
    }

    private function consume(): array
    {
        return $this->tokens[$this->position++] ?? ['type' => 'EOF', 'value' => null];
    }

    private function peek(int $offset = 0): ?array
    {
        $index = $this->position + $offset;
        return $this->tokens[$index] ?? null;
    }

    private function isRelationToken(array $token): bool
    {
        return in_array($token['type'], ['REL_OP', 'REL_WORD'], true);
    }

    private function consumeRelation(): string
    {
        $token = $this->consume();
        if (in_array($token['type'], ['REL_OP', 'REL_WORD'], true)) {
            return $token['value'];
        }

        throw new UnsupportedRelationException('Expected relation operator near "' . ($token['value'] ?? '') . '"');
    }

    private function consumeValueToken(): array
    {
        $token = $this->consume();
        if (in_array($token['type'], ['STRING', 'WORD'], true)) {
            return ['type' => 'VALUE', 'value' => $token['value']];
        }

        throw new InvalidCQLSyntaxException('Expected search term near "' . ($token['value'] ?? '') . '"');
    }
}
