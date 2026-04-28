<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Enum;

/**
 * FetchTypeEnum
 *
 * Defines the fetch mode used by database queries.
 * Each case represents the mode used by the static factories on FetchType.
 *
 *
 * @example
 * // Represents pagination mode:
 * $type = FetchTypeEnum::PAGINATE;
 * echo $type->value;    // 1
 * echo $type->name;     // "PAGINATE"
 * @example
 * // In pluck mode, only a specific column value is returned:
 * if ($type === FetchTypeEnum::PLUCK) {
 *     // ...
 * }
 */
enum FetchTypeEnum: int
{
    /**
     * Returns data as a paginated result. (paginate)
     */
    case PAGINATE = 1;

    /**
     * Returns a single model. (first)
     */
    case MODEL = 2;

    /**
     * Returns a single column's values as a collection. (pluck)
     */
    case PLUCK = 3;

    /**
     * Returns all data as a collection. (collect)
     */
    case COLLECT = 4;

    /**
     * Returns data as a collection with offset and limit for infinite scroll.
     */
    case INFINITE_SCROLL = 5;

    /**
     * Returns the enum value in array format.
     *
     * @return array{key: string, value: int, description: string}
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
