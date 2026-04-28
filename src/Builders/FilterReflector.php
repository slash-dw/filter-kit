<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Builders;

use SlashDw\FilterKit\Enum\JsonOperator;
use SlashDw\FilterKit\Enum\Operator;
use SlashDw\FilterKit\Filterable;
use SlashDw\FilterKit\JsonFilterable;
use SlashDw\FilterKit\MorphType;
use SlashDw\FilterKit\MultiFieldSearchConfig;

/**
 * Reads Filterable and JsonFilterable attributes via reflection.
 *
 * This class reads Filterable and JsonFilterable attributes from public properties
 * on filter classes and caches them. Reflection runs only once, and the cache is
 * used on subsequent calls for performance.
 *
 * **Responsibility (SRP):**
 * - Reflection-only operations
 * - Separate method per attribute type (getFilterableAttributes, getJsonFilterableAttributes)
 *
 * **Performance:**
 * - Static reflection cache: reflection runs only on first call
 * - Significant performance improvement
 *
 * **Safety:**
 * - Uses the class name as the reflection cache key
 *
 * **JsonFilterable Support:**
 * - Reads JsonFilterable attributes and includes MultiFieldSearchConfig information
 * - If MultiFieldSearchConfig is defined, multi-field search is enabled
 *
 *
 * @example
 * $reflector = new FilterReflector();
 * $filters = $reflector->getLocalFilters($filterInstance);
 * // First call: reflection executes and cache is populated
 * // Subsequent calls: read from cache
 * // Both Filterable and JsonFilterable attributes are read.
 */
final class FilterReflector
{
    /**
     * Reflection cache: reflection runs once per class,
     * and the cache is used on subsequent calls.
     *
     * Key: Class name
     * Value: Filterable and JsonFilterable attribute information
     *
     * @var array<string, array{property: string, columns: string[], operator?: Operator, json_operator?: JsonOperator, whereHas?: bool, multiFieldSearchConfig?: MultiFieldSearchConfig|null, is_json: bool, morph: bool}[]>
     */
    private static array $cache = [];

    /**
     * Reads Filterable and JsonFilterable attributes from a filter instance.
     *
     * Uses the reflection cache: the first call reflects and caches, and
     * subsequent calls read from the cache.
     *
     * Only properties with non-null values are returned.
     *
     * @param  object  $filter  Filter instance
     * @return array{columns: string[], operator?: Operator, json_operator?: JsonOperator, value: mixed, whereHas?: bool, multiFieldSearchConfig?: MultiFieldSearchConfig|null, is_json: bool}[]
     */
    public function getLocalFilters(object $filter): array
    {
        $className = get_class($filter);

        // Cache check: build and cache on first run
        if (! isset(self::$cache[$className])) {
            self::$cache[$className] = $this->buildFilters($filter);
        }

        // Read from cache and keep only non-null values.
        $cached = self::$cache[$className];

        return array_values(array_filter(
            array_map(function (array $f) use ($filter) {
                $propertyName = $f['property'];
                $value = $filter->{$propertyName};

                if ($value === null) {
                    return null;
                }

                $result = [
                    'columns' => $f['columns'],
                    'value' => $value,
                    'is_json' => $f['is_json'],
                    'property' => $f['property'],
                    'morph' => $f['morph'],
                ];

                // For JSON filters, include json_operator and multiFieldSearchConfig
                if ($f['is_json']) {
                    $result['json_operator'] = $f['json_operator'];
                    if (isset($f['multiFieldSearchConfig'])) {
                        $result['multiFieldSearchConfig'] = $f['multiFieldSearchConfig'];
                    }
                } else {
                    // For standard filters, include operator and whereHas
                    $result['operator'] = $f['operator'];
                    if (isset($f['whereHas'])) {
                        $result['whereHas'] = $f['whereHas'];
                    }
                }

                return $result;
            }, $cached),
            fn ($f) => $f !== null
        ));
    }

    /**
     * Reads Filterable attributes.
     *
     * SOLID (SRP): reads only Filterable attributes.
     *
     * @param  object  $filter  Filter instance
     * @return array{property: string, columns: string[], operator: Operator, whereHas: bool, is_json: false, morph: bool}[]
     */
    private function getFilterableAttributes(object $filter): array
    {
        $filters = [];
        $ref = new \ReflectionClass($filter);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            // Look for both Filterable and MorphType attributes (MorphType extends Filterable, but reflection does not infer child attribute types).
            $filterableAttrs = $prop->getAttributes(Filterable::class);
            $morphTypeAttrs = $prop->getAttributes(MorphType::class);

            if (empty($filterableAttrs) && empty($morphTypeAttrs)) {
                continue;
            }

            // Prefer MorphType when present; otherwise use Filterable
            $attrs = ! empty($morphTypeAttrs) ? $morphTypeAttrs : $filterableAttrs;
            $instance = $attrs[0]->newInstance();

            $columns = match (true) {
                is_array($instance->columns) => $instance->columns,
                is_string($instance->columns) => [$instance->columns],
                default => [$prop->getName()],
            };

            $filters[] = [
                'property' => $prop->getName(),
                'columns' => $columns,
                'operator' => $instance->operator,
                'whereHas' => $instance->whereHas,
                'is_json' => false,
                'morph' => $instance instanceof MorphType,
            ];
        }

        return $filters;
    }

    /**
     * Reads JsonFilterable attributes.
     *
     * SOLID (SRP): reads only JsonFilterable attributes.
     *
     * @param  object  $filter  Filter instance
     * @return array{property: string, columns: string[], json_operator: JsonOperator, multiFieldSearchConfig: MultiFieldSearchConfig|null, is_json: true, morph: false}[]
     */
    private function getJsonFilterableAttributes(object $filter): array
    {
        $filters = [];
        $ref = new \ReflectionClass($filter);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $jsonFilterableAttrs = $prop->getAttributes(JsonFilterable::class);
            if (empty($jsonFilterableAttrs)) {
                continue;
            }

            $instance = $jsonFilterableAttrs[0]->newInstance();

            $columns = match (true) {
                is_array($instance->columns) => $instance->columns,
                is_string($instance->columns) => [$instance->columns],
                default => [$prop->getName()],
            };

            $filters[] = [
                'property' => $prop->getName(),
                'columns' => $columns,
                'json_operator' => $instance->operator,
                'multiFieldSearchConfig' => $instance->multiFieldSearchConfig,
                'is_json' => true,
                'morph' => false,
            ];
        }

        return $filters;
    }

    /**
     * Reads Filterable and JsonFilterable attributes via reflection and prepares the cache.
     *
     * SOLID (SRP): uses a separate method per attribute type.
     *
     * This method runs only once per class for caching.
     *
     * @param  object  $filter  Filter instance
     * @return array{property: string, columns: string[], operator?: Operator, json_operator?: JsonOperator, whereHas?: bool, multiFieldSearchConfig?: MultiFieldSearchConfig|null, is_json: bool, morph: bool}[]
     */
    private function buildFilters(object $filter): array
    {
        $filterableFilters = $this->getFilterableAttributes($filter);
        $jsonFilterableFilters = $this->getJsonFilterableAttributes($filter);

        return array_merge($filterableFilters, $jsonFilterableFilters);
    }
}
