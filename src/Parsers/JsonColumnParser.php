<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Parsers;

use InvalidArgumentException;

/**
 * Parses JSON column format and builds PostgreSQL SQL expression.
 *
 * This class parses JSON column definitions in "data.message" format
 * and converts them into PostgreSQL SQL expressions.
 *
 * **Responsibility (SRP):**
 * - Parse JSON column format
 * - Build PostgreSQL SQL expressions
 *
 * **Performance:**
 * - Final class (prevents inheritance, compile-time optimization)
 * - Minimal string operations
 * - Early returns
 *
 * **Safety:**
 * - Quotes table/column names to prevent SQL injection
 * - Path validation (alphanumeric characters and underscore)
 *
 *
 * @example
 * $parser = new JsonColumnParser();
 * $parsed = $parser->parse('data.message');
 * // ['column' => 'data', 'path' => 'message']
 *
 * $expr = $parser->buildExpression('notifications', 'data', 'message');
 * // "notifications"."data"->>'message'
 */
final class JsonColumnParser
{
    /**
     * Parses JSON column format.
     *
     * Parses a string in "data.message" format and separates the column name and path.
     * Throws descriptive error messages for invalid formats.
     *
     * **Parse Logic:**
     * 1. Must contain two parts separated by a dot (.)
     * 2. Neither part can be empty
     * 3. The two parts cannot be the same (example: "data.data")
     *
     * **Invalid Formats:**
     * - "data" (no dot)
     * - "data." (path empty)
     * - ".message" (column empty)
     * - "data.message.foo" (multiple dots; only 2 parts are accepted)
     *
     * @param  string  $column  Column name in "data.message" format
     * @return array{column: string, path: string} Parsed column information
     *
     * @throws InvalidArgumentException Invalid format.
     *
     * @example
     * $parsed = $parser->parse('data.message');
     * // ['column' => 'data', 'path' => 'message']
     * @example
     * $parsed = $parser->parse('metadata.title');
     * // ['column' => 'metadata', 'path' => 'title']
     */
    public function parse(string $column): array
    {
        // Dot check: at least one dot is required
        if (! str_contains($column, '.')) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s] JSON column format invalid: "%s". '.
                    'Expected format: "column.path" (example: "data.message"). '.
                    'It must contain two parts separated by a dot (.).',
                    __CLASS__,
                    $column
                )
            );
        }

        // Split into two parts (limit=2 prevents extra dot segments).
        $parts = explode('.', $column, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s] JSON column format invalid: "%s". '.
                    'Expected format: "column.path" (example: "data.message"). '.
                    'It must contain two parts separated by a dot (.).',
                    __CLASS__,
                    $column
                )
            );
        }

        [$baseColumn, $path] = $parts;

        // Trim step: remove leading/trailing whitespace.
        $baseColumn = trim($baseColumn);
        $path = trim($path);

        // Validation: empty value and same-name checks
        if ($baseColumn === '' || $path === '' || $baseColumn === $path) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s] JSON column format invalid: "%s". '.
                    'Expected format: "column.path" (example: "data.message"). '.
                    'Neither part can be empty, and the parts must be different.',
                    __CLASS__,
                    $column
                )
            );
        }

        // Path validation: only alphanumeric characters and underscore.
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $path)) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s] JSON path invalid: "%s". '.
                    'Path may contain only alphanumeric characters and underscores.',
                    __CLASS__,
                    $path
                )
            );
        }

        return [
            'column' => $baseColumn,
            'path' => $path,
        ];
    }

    /**
     * Builds PostgreSQL JSON column SQL expression.
     *
     * In PostgreSQL, `->` is used to access JSON columns.
     * - `->` returns JSON
     * - `->>` returns text (required for ILIKE/LIKE)
     *
     * Table and column names are quoted with double quotes to prevent SQL injection.
     * Path values are quoted with single quotes.
     *
     * @param  string  $table  Table name (example: 'notifications')
     * @param  string  $jsonColumn  JSON column name (example: 'data')
     * @param  string  $path  JSON path (example: 'message')
     * @return string PostgreSQL SQL expression
     *
     * @example
     * $expr = $parser->buildExpression('notifications', 'data', 'message');
     * // "notifications"."data"->>'message'
     */
    public function buildExpression(string $table, string $jsonColumn, string $path): string
    {
        // PostgreSQL: ->> returns text (required for ILIKE/LIKE)
        // SQL injection prevention: quote table and column names
        // Path values are quoted with single quotes
        return sprintf('"%s"."%s"->>\'%s\'', $table, $jsonColumn, $path);
    }
}
