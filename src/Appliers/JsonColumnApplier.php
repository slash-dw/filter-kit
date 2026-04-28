<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Appliers;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SlashDw\FilterKit\Enum\JsonOperator;
use SlashDw\FilterKit\MultiFieldSearchConfig;
use SlashDw\FilterKit\Parsers\JsonColumnParser;

/**
 * Applies JSON column filters.
 *
 * This class applies filters to PostgreSQL JSON columns.
 * All values are applied safely with parameter binding.
 *
 * **Responsibility (SRP):**
 * - JSON column filtering only
 *
 * **Safety (CRITICAL):**
 * - All values use `?` placeholders and parameter binding in `whereRaw`
 * - Table and column names are quoted with double quotes
 * - JSON paths are quoted with single quotes
 * - User input is never inserted directly into SQL
 *
 * **Performance:**
 * - Final class (prevents inheritance, compile-time optimization)
 * - Match expression (instead of switch)
 * - Parameter binding (safety + performance)
 *
 * **MultiFieldSearchConfig:**
 * - If MultiFieldSearchConfig is defined, it performs multi-field search using jsonb_each_text()
 * - Keys in excludedKeys are excluded from search
 * - If MultiFieldSearchConfig is null, a normal JSON path search is performed
 *
 * **Operator Notes:**
 * - STARTS_WITH: uses ILIKE "value%" for prefix string searches
 * - ENDS_WITH: uses ILIKE "%value" for suffix string searches
 * - Date range filters should use GTE and LTE
 *
 *
 * @example
 * // Normal JSON path search
 * $applier = new JsonColumnApplier(new JsonColumnParser());
 * $query = $applier->apply($query, 'data.message', JsonOperator::ILIKE, 'test', 'notifications', null);
 * // WHERE "notifications"."data"->>'message' ILIKE '%test%'
 * @example
 * // Multi-field search (excluding url)
 * $config = new MultiFieldSearchConfig(excludedKeys: ['url']);
 * $query = $applier->apply($query, 'data', JsonOperator::ILIKE, 'test', 'notifications', $config);
 * // WHERE EXISTS (SELECT 1 FROM jsonb_each_text("notifications"."data") AS kv WHERE kv.value ILIKE '%test%' AND kv.key NOT IN ('url'))
 */
final class JsonColumnApplier
{
    public function __construct(
        private readonly JsonColumnParser $parser
    ) {}

    /**
     * Applies a JSON column filter.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $column  JSON column format (example: "data" or "data.message")
     * @param  JsonOperator  $op  JSON operator
     * @param  mixed  $value  Filter value
     * @param  string  $table  Table name
     * @param  MultiFieldSearchConfig|null  $multiFieldSearchConfig  Multi-field search configuration
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid operator or value.
     */
    public function apply(Builder $query, string $column, JsonOperator $op, mixed $value, string $table, ?MultiFieldSearchConfig $multiFieldSearchConfig = null): Builder
    {
        // If MultiFieldSearchConfig is defined, perform multi-field search
        if ($multiFieldSearchConfig !== null) {
            return $this->applyMultiFieldSearch($query, $column, $op, $value, $table, $multiFieldSearchConfig);
        }

        // Normal JSON path search
        $parsed = $this->parser->parse($column);
        $expr = $this->parser->buildExpression($table, $parsed['column'], $parsed['path']);

        return match ($op) {
            JsonOperator::EQ => $query->whereRaw("{$expr} = ?", [$value]),
            JsonOperator::NEQ => $query->whereRaw("{$expr} != ?", [$value]),
            JsonOperator::GT => $query->whereRaw("{$expr} > ?", [$value]),
            JsonOperator::LT => $query->whereRaw("{$expr} < ?", [$value]),
            JsonOperator::GTE => $query->whereRaw("{$expr} >= ?", [$value]),
            JsonOperator::LTE => $query->whereRaw("{$expr} <= ?", [$value]),
            JsonOperator::ILIKE => $query->whereRaw("{$expr} ILIKE ?", ["%{$value}%"]),
            JsonOperator::LIKE => $query->whereRaw("{$expr} LIKE ?", ["%{$value}%"]),
            JsonOperator::STARTS_WITH => $query->whereRaw("{$expr} ILIKE ?", ["{$value}%"]),
            JsonOperator::ENDS_WITH => $query->whereRaw("{$expr} ILIKE ?", ["%{$value}"]),
            JsonOperator::IN => $this->applyIn($query, $expr, $value),
            JsonOperator::NOT_IN => $this->applyNotIn($query, $expr, $value),
            JsonOperator::IS_NULL => $query->whereRaw("{$expr} IS NULL"),
            JsonOperator::NOT_NULL => $query->whereRaw("{$expr} IS NOT NULL"),
        };
    }

    /**
     * Applies multi-field search.
     *
     * If MultiFieldSearchConfig is defined, it searches all JSON key-value pairs via jsonb_each_text().
     * Keys in excludedKeys are excluded from search.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $column  JSON column name (example: "data")
     * @param  JsonOperator  $op  JSON operator (usually ILIKE)
     * @param  mixed  $value  Search value
     * @param  string  $table  Table name
     * @param  MultiFieldSearchConfig  $config  Multi-field search configuration
     * @return Builder Filtered query builder
     */
    private function applyMultiFieldSearch(Builder $query, string $column, JsonOperator $op, mixed $value, string $table, MultiFieldSearchConfig $config): Builder
    {
        // MultiFieldSearchConfig only needs the JSON column, not the path.
        // Keep only the column name (parse if it is in "column.path" format)
        $jsonColumn = $column;
        if (str_contains($column, '.')) {
            // If it is in "column.path" format, keep only the column name.
            $parsed = $this->parser->parse($column);
            $jsonColumn = $parsed['column'];
        }

        // Build SQL condition for excluded keys
        $excludedCondition = '';
        $bindings = [];

        if (! empty($config->excludedKeys)) {
            $placeholders = implode(',', array_fill(0, count($config->excludedKeys), '?'));
            $excludedCondition = "AND kv.key NOT IN ({$placeholders})";
            $bindings = array_merge($bindings, $config->excludedKeys);
        }

        // Value pattern by operator
        $valuePattern = match ($op) {
            JsonOperator::ILIKE => "%{$value}%",
            JsonOperator::LIKE => "%{$value}%",
            JsonOperator::STARTS_WITH => "{$value}%",
            JsonOperator::ENDS_WITH => "%{$value}",
            default => $value,
        };

        // Search across all key-value pairs via jsonb_each_text()
        $query->whereRaw(
            'EXISTS (
                SELECT 1 
                FROM jsonb_each_text("'.$table.'"."'.$jsonColumn.'") AS kv
                WHERE kv.value ILIKE ? '.$excludedCondition.'
            )',
            array_merge([$valuePattern], $bindings)
        );

        return $query;
    }

    /**
     * Applies the IN operator.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $expr  SQL expression
     * @param  mixed  $value  Value array
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    private function applyIn(Builder $query, string $expr, mixed $value): Builder
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s][%s] IN operator expects an array.',
                    __CLASS__,
                    __FUNCTION__
                )
            );
        }

        $placeholders = implode(',', array_fill(0, count($value), '?'));

        return $query->whereRaw("{$expr} IN ({$placeholders})", $value);
    }

    /**
     * Applies the NOT_IN operator.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $expr  SQL expression
     * @param  mixed  $value  Value array
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    private function applyNotIn(Builder $query, string $expr, mixed $value): Builder
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s][%s] NOT_IN operator expects an array.',
                    __CLASS__,
                    __FUNCTION__
                )
            );
        }

        $placeholders = implode(',', array_fill(0, count($value), '?'));

        return $query->whereRaw("{$expr} NOT IN ({$placeholders})", $value);
    }
}
