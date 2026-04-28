<?php

declare(strict_types=1);

namespace SlashDw\FilterKit;

use Attribute;
use SlashDw\FilterKit\Enum\JsonOperator;

/**
 * Special attribute for JSON column filtering.
 *
 * This attribute is used to filter JSON/JSONB columns.
 * It accepts the JsonOperator enum.
 *
 * multiFieldSearchConfig: if defined, multi-field search becomes active.
 * In that case, jsonb_each_text() is used to search all JSON key-value pairs.
 * Keys in excludedKeys are excluded from search.
 *
 * If multiFieldSearchConfig is null, a normal JSON path search is performed (example: data.message).
 *
 * @example
 * // Single-field search (normal JSON path)
 * #[JsonFilterable(columns: "data.message", operator: JsonOperator::ILIKE)]
 * public ?string $search = null;
 * @example
 * // Multi-field search (all properties except url)
 * #[JsonFilterable(
 *     columns: "data",
 *     operator: JsonOperator::ILIKE,
 *     multiFieldSearchConfig: new MultiFieldSearchConfig(excludedKeys: ['url'])
 * )]
 * public ?string $search = null;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class JsonFilterable
{
    /**
     * @param  string|string[]|null  $columns  JSON column format (example: "data.message" or ["data.message", "data.title"])
     * @param  JsonOperator  $operator  JSON filter operator
     * @param  MultiFieldSearchConfig|null  $multiFieldSearchConfig  Multi-field search configuration (null for normal path search)
     */
    public function __construct(
        public string|array|null $columns = null,
        public JsonOperator $operator = JsonOperator::ILIKE,
        public ?MultiFieldSearchConfig $multiFieldSearchConfig = null,
    ) {}
}
