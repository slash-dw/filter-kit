<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Appliers;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SlashDw\FilterKit\Enum\Operator;

/**
 * Applies relation filters.
 *
 * This class applies Eloquent relation filters.
 * It uses `whereHas` and `orWhereHas` with automatic parameter binding.
 *
 * **Responsibility (SRP):**
 * - Relation filtering only
 *
 * **Safety:**
 * - Uses Eloquent `whereHas` (automatic parameter binding)
 * - User input is never inserted directly into SQL
 *
 * **Performance:**
 * - Final class (prevents inheritance, compile-time optimization)
 * - Native array operations (instead of collect)
 *
 *
 * @example
 * $applier = new RelationFilterApplier();
 * $query = $applier->applySingle($query, 'user', 'name', Operator::ILIKE, 'test', '%test%');
 * // WHERE EXISTS (SELECT * FROM users WHERE users.id = table.user_id AND users.name ILIKE '%test%')
 */
final class RelationFilterApplier
{
    /**
     * Applies a single relation filter.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $relation  Relation name
     * @param  string  $column  Column name on relation
     * @param  Operator  $op  Operator
     * @param  mixed  $value  Filter value
     * @param  mixed  $wrapped  Wrapped value (for LIKE, e.g. %value%)
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    public function applySingle(Builder $query, string $relation, string $column, Operator $op, mixed $value, mixed $wrapped): Builder
    {
        return match ($op) {
            Operator::IN => $this->applyIn($query, $relation, $column, $value),
            Operator::NOT_IN => $this->applyNotIn($query, $relation, $column, $value),
            default => $query->whereHas($relation, fn (Builder $q) => $q->where($column, $op->value, $wrapped)),
        };
    }

    /**
     * Applies multiple relation filters with OR logic.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  array  $columns  Relation column list (example: ['user.name', 'user.email'])
     * @param  Operator  $op  Operator
     * @param  mixed  $value  Filter value
     * @param  mixed  $wrapped  Wrapped value (for LIKE, e.g. %value%)
     * @return Builder Filtered query builder
     */
    public function applyMultiple(Builder $query, array $columns, Operator $op, mixed $value, mixed $wrapped): Builder
    {
        $query->where(function (Builder $q) use ($columns, $op, $wrapped) {
            foreach ($columns as $index => $col) {
                [$relation, $column] = explode('.', $col, 2);
                $method = ($index === 0) ? 'whereHas' : 'orWhereHas';
                $q->{$method}($relation, function (Builder $relationQuery) use ($column, $op, $wrapped) {
                    $relationQuery->where($column, $op->value, $wrapped);
                });
            }
        });

        return $query;
    }

    /**
     * Applies relation-chain filters using whereHas.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $relationChain  Relation chain (example: 'userCompanies.branches')
     * @param  string  $column  Column name on relation
     * @param  Operator  $op  Operator
     * @param  mixed  $value  Filter value
     * @param  mixed  $wrapped  Wrapped value (for LIKE, e.g. %value%)
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    public function applyWhereHas(Builder $query, string $relationChain, string $column, Operator $op, mixed $value, mixed $wrapped): Builder
    {
        return match ($op) {
            Operator::IN => $this->applyIn($query, $relationChain, $column, $value),
            Operator::NOT_IN => $this->applyNotIn($query, $relationChain, $column, $value),
            default => $query->whereHas($relationChain, fn (Builder $q) => $q->where($column, $op->value, $wrapped)),
        };
    }

    /**
     * Applies IN operator on relation.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $relation  Relation name or relation chain
     * @param  string  $column  Column name
     * @param  array  $value  Value array
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    private function applyIn(Builder $query, string $relation, string $column, mixed $value): Builder
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s][%s] Array expected for IN operator (relation: %s, column: %s)',
                    __CLASS__,
                    __FUNCTION__,
                    $relation,
                    $column
                )
            );
        }

        return $query->whereHas($relation, fn (Builder $q) => $q->whereIn($column, $value));
    }

    /**
     * Applies NOT_IN operator on relation.
     *
     * @param  Builder  $query  Eloquent query builder
     * @param  string  $relation  Relation name or relation chain
     * @param  string  $column  Column name
     * @param  array  $value  Value array
     * @return Builder Filtered query builder
     *
     * @throws InvalidArgumentException Invalid value.
     */
    private function applyNotIn(Builder $query, string $relation, string $column, mixed $value): Builder
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    '[%s][%s] Array expected for NOT_IN operator (relation: %s, column: %s)',
                    __CLASS__,
                    __FUNCTION__,
                    $relation,
                    $column
                )
            );
        }

        return $query->whereHas($relation, fn (Builder $q) => $q->whereNotIn($column, $value));
    }
}
