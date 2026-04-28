<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Enum;

/**
 * SortDirection
 *
 * Enum representing sort direction with two basic values:
 * - ASC  : ascending
 * - DESC : descending
 *
 *
 * @example
 * // Usage example with OrderClause:
 * $direction = SortDirection::DESC;
 * $query->orderBy('name', $direction->value);
 * @example
 * // Convert to array format:
 * $enumData = SortDirection::ASC->toArray();
 * // ['key' => 'ASC', 'value' => 'asc', 'description' => 'ASC']
 */
enum SortDirection: string
{
    /**
     * Ascending order.
     */
    case ASC = 'asc';

    /**
     * Descending order.
     */
    case DESC = 'desc';

    /**
     * Returns enum information in array format.
     *
     * @return array{key: string, value: string, description: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->name,
            'value' => $this->value,
            'description' => $this->name,
        ];
    }
}
