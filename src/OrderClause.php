<?php

declare(strict_types=1);

namespace SlashDw\FilterKit;

use Illuminate\Contracts\Support\Arrayable;
use SlashDw\FilterKit\Enum\SortDirection;

/**
 * OrderClause
 *
 * Holds sorting information for use in Eloquent queries.
 * Created with column and direction parameters.
 *
 *
 * @example
 * // Basic create usage:
 * $order = OrderClause::create('name', SortDirection::DESC);
 * $query->orderBy($order->getColumn(), $order->getDirection());
 *
 * @phpstan-consistent-constructor
 *
 * @implements Arrayable<string, string>
 */
class OrderClause implements \JsonSerializable, Arrayable
{
    /**
     * Column name to sort by.
     *
     * @example 'created_at'
     */
    protected string $column;

    /**
     * SortDirection enum value (ASC or DESC).
     * Defaults to ASC when null.
     */
    protected ?SortDirection $direction;

    /**
     * Optional custom applier for relational/complex sorting.
     */
    protected ?\Closure $customApplier = null;

    /**
     * OrderClause constructor.
     *
     * @param  string  $column  Sorting column
     * @param  SortDirection|null  $direction  Sort direction (ASC/DESC), default ASC
     * @param  \Closure|null  $customApplier  Custom sorting applier
     */
    public function __construct(string $column, ?SortDirection $direction = null, ?\Closure $customApplier = null)
    {
        // Column name is required and cannot be empty.
        $this->column = $column;
        $this->direction = $direction;
        $this->customApplier = $customApplier;
    }

    /**
     * Static factory method that creates OrderClause.
     *
     * @param  string  $column  Sorting column
     * @param  SortDirection|null  $direction  Sort direction (ASC/DESC)
     */
    public static function create(string $column, ?SortDirection $direction = null): static
    {
        return new static($column, $direction);
    }

    /**
     * Creates OrderClause with a custom applier.
     *
     * @param  \Closure  $applier  function(Builder $q, string $direction): void
     */
    public static function custom(\Closure $applier, ?SortDirection $direction = null): static
    {
        return new static('__custom__', $direction, $applier);
    }

    /**
     * Returns sorting column information.
     */
    public function getColumn(): string
    {
        return $this->column;
    }

    /**
     * Returns sorting direction as string ('asc' or 'desc').
     * Returns SortDirection::ASC->value when $direction is null.
     */
    public function getDirection(): string
    {
        return $this->direction === null ? SortDirection::ASC->value : $this->direction->value;
    }

    /**
     * Returns the custom applier when present.
     */
    public function getCustomApplier(): ?\Closure
    {
        return $this->customApplier;
    }

    /**
     * Returns sorting data as an array for Arrayable interface.
     *
     * @return array{column:string,direction:string}
     */
    public function toArray(): array
    {
        return [
            'column' => $this->getColumn(),
            'direction' => $this->getDirection(),
        ];
    }

    /**
     * Returns `toArray()` output for JsonSerializable interface.
     *
     * @return array<string,string>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
