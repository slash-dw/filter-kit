<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Appliers;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SlashDw\FilterKit\Enum\Operator;

/**
 * Applies standard column filters.
 *
 * This class applies normal (non-JSON, non-relation) column filters.
 * It uses Eloquent query builder methods with automatic parameter binding.
 *
 * **Responsibility (SRP):**
 * - Standard column filtering only
 *
 * **Safety:**
 * - Uses the Eloquent query builder (automatic parameter binding)
 * - User input is never inserted directly into SQL
 *
 * **Performance:**
 * - Final class (prevents inheritance, compile-time optimization)
 * - Match expression (instead of switch)
 * - Early returns
 *
 *
 * @example
 * $applier = new StandardFilterApplier();
 * $query = $applier->apply($query, 'name', Operator::ILIKE, 'test', '%test%', true);
 * // WHERE "table"."name" ILIKE '%test%'
 */
final class StandardFilterApplier
{
    /**
     * Applies a standard column filter.
     *
     * **Operator Notes:**
     * - STARTS_WITH and ENDS_WITH: string searches are converted to the ILIKE operator
     *   (STARTS_WITH -> ILIKE "value%", ENDS_WITH -> ILIKE "%value")
     * - Date range filters should use GTE and LTE
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $column  Column name (qualified: "table.column")
     * @param  Operator  $op  Operator
     * @param  mixed  $value  Filter value
     * @param  mixed  $wrapped  Wrapped value (LIKE for %value%, STARTS_WITH for value%, ENDS_WITH for %value)
     * @param  bool  $first  Whether this is the first condition (where vs orWhere)
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid operator or value.
     */
    public function apply(Builder $query, string $column, Operator $op, mixed $value, mixed $wrapped, bool $first = true): Builder
    {
        $methodBase = $first ? 'where' : 'orWhere';

        return match ($op) {
            Operator::EQ, Operator::NEQ, Operator::GT, Operator::LT,
            Operator::GTE, Operator::LTE, Operator::LIKE, Operator::ILIKE => $query->{$methodBase}($column, $op->value, $wrapped),

            Operator::STARTS_WITH, Operator::ENDS_WITH => $query->{$methodBase}($column, Operator::ILIKE->value, $wrapped),

            Operator::IN => $this->applyIn($query, $column, $value, $methodBase),
            Operator::NOT_IN => $this->applyNotIn($query, $column, $value, $methodBase),
            Operator::BETWEEN => $this->applyBetween($query, $column, $value, $methodBase),
            Operator::NOT_BETWEEN => $this->applyNotBetween($query, $column, $value, $methodBase),
            Operator::IS_NULL => $query->{$methodBase.'Null'}($column),
            Operator::NOT_NULL => $query->{$methodBase.'NotNull'}($column),
        };
    }

    /**
     * Applies the IN operator.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $column  Column name
     * @param  array  $value  Value array
     * @param  string  $methodBase  Method base (where/orWhere)
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    private function applyIn(Builder $query, string $column, mixed $value, string $methodBase): Builder
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s][%s] IN operator expects an array (column: %s)',
                    __CLASS__,
                    __FUNCTION__,
                    $column
                )
            );
        }

        return $query->{$methodBase.'In'}($column, $value);
    }

    /**
     * Applies the NOT_IN operator.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $column  Column name
     * @param  array  $value  Value array
     * @param  string  $methodBase  Method base (where/orWhere)
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    private function applyNotIn(Builder $query, string $column, mixed $value, string $methodBase): Builder
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s][%s] NOT_IN operator expects an array (column: %s)',
                    __CLASS__,
                    __FUNCTION__,
                    $column
                )
            );
        }

        return $query->{$methodBase.'NotIn'}($column, $value);
    }

    /**
     * Applies the BETWEEN operator.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $column  Column name
     * @param  array  $value  Two-item array [min, max]
     * @param  string  $methodBase  Method base (where/orWhere)
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    private function applyBetween(Builder $query, string $column, mixed $value, string $methodBase): Builder
    {
        if (! is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s][%s] BETWEEN operator expects a two-item array (column: %s)',
                    __CLASS__,
                    __FUNCTION__,
                    $column
                )
            );
        }

        return $query->{$methodBase.'Between'}($column, $value);
    }

    /**
     * Applies the NOT_BETWEEN operator.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $column  Column name
     * @param  array  $value  Two-item array [min, max]
     * @param  string  $methodBase  Method base (where/orWhere)
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    private function applyNotBetween(Builder $query, string $column, mixed $value, string $methodBase): Builder
    {
        if (! is_array($value) || count($value) !== 2) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s][%s] NOT_BETWEEN operator expects a two-item array (column: %s)',
                    __CLASS__,
                    __FUNCTION__,
                    $column
                )
            );
        }

        return $query->{$methodBase.'NotBetween'}($column, $value);
    }
}
