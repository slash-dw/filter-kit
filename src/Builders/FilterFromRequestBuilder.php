<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Builders;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use SlashDw\FilterKit\BaseFilter;
use SlashDw\FilterKit\Enum\SortDirection;
use SlashDw\FilterKit\OrderClause;

/**
 * Builds a filter from FormRequest.
 *
 * This class reads validated data from FormRequest and maps it to a Filter instance.
 * Performs type conversion, validation, sorting, and column handling.
 *
 * **Responsibility (SRP):**
 * - Filter building only
 *
 * **Safety:**
 * - Uses FormRequest validated data (Laravel validation)
 *
 * **Performance:**
 * - Final class (prevents inheritance, compile-time optimization)
 * - Early returns
 * - Match expression (instead of switch)
 *
 *
 * @example
 * $builder = new FilterFromRequestBuilder();
 * $filter = $builder->build($request, new NotificationFilter());
 */
final class FilterFromRequestBuilder
{
    /**
     * Builds a filter from FormRequest.
     *
     * @template TFilter of BaseFilter
     *
     * @param  FormRequest  $request  FormRequest instance
     * @param  TFilter  $filter  Filter instance
     * @return TFilter Filter instance (same instance, updated)
     *
     * @throws \Exception
     */
    public function build(FormRequest $request, BaseFilter $filter): BaseFilter
    {
        $validated = $request->validated();
        $refClass = new \ReflectionClass($filter);

        foreach ($validated as $key => $value) {
            if ($value === null) {
                continue;
            }

            // Handle base class properties (offset, limit) explicitly
            if ($key === 'offset') {
                $filter->setOffset((int) $value);

                continue;
            }
            if ($key === 'limit') {
                $filter->setLimit((int) $value);

                continue;
            }

            // Skip keys that do not exist as properties on the child class.
            if (! property_exists($filter, $key)) {
                continue;
            }

            // For *_at_end fields, normalize to end-of-day automatically
            if (str_ends_with($key, 'at_end')) {
                $value = Carbon::parse($value)->endOfDay()->toDateTimeString();
            }

            // Read property; skip if inaccessible
            try {
                $prop = $refClass->getProperty($key);
            } catch (\ReflectionException $e) {
                // Skip inaccessible properties (e.g. private base class property)
                continue;
            }

            $value = $this->convertType($value, $prop);
            $filter->{$key} = $value;
        }

        $this->applySorting($validated, $filter);
        $this->applyColumns($validated, $filter);

        return $filter;
    }

    /**
     * Converts value by property type.
     *
     * @param  mixed  $value  Value
     * @param  \ReflectionProperty  $prop  Property reflection
     * @return mixed Converted value
     *
     * @throws \Exception
     */
    private function convertType(mixed $value, \ReflectionProperty $prop): mixed
    {
        $type = $prop->getType();
        if (! $type instanceof \ReflectionNamedType) {
            return $value;
        }

        $typeName = $type->getName();

        // Built-in types
        if ($type->isBuiltin()) {
            return match ($typeName) {
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'int' => (int) $value,
                'float' => (float) $value,
                'string' => (string) $value,
                'array' => $this->convertToArray($value),
                default => $value,
            };
        }

        // Carbon date types
        if (in_array($typeName, [Carbon::class, CarbonImmutable::class], true)) {
            return Carbon::parse($value);
        }

        // BackedEnum
        if (enum_exists($typeName) && is_subclass_of($typeName, \BackedEnum::class)) {
            return $typeName::from($value);
        }

        // General DateTimeInterface
        if (is_a($typeName, \DateTimeInterface::class, true)) {
            return new \DateTimeImmutable($value);
        }

        return $value;
    }

    /**
     * Converts value to array.
     *
     * @param  mixed  $value  Value
     * @return array Array
     */
    private function convertToArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [$value];
        }

        return is_array($value) ? $value : (array) $value;
    }

    /**
     * Applies sorting information.
     *
     * @param  array  $validated  Validated data
     * @param  BaseFilter  $filter  Filter instance
     */
    private function applySorting(array $validated, BaseFilter $filter): void
    {
        if (($validated['sort_by'] ?? null) === null) {
            return;
        }

        $dirStr = strtolower((string) ($validated['sort_dir'] ?? SortDirection::ASC->value));
        $dirEnum = $dirStr === SortDirection::DESC->value ? SortDirection::DESC : SortDirection::ASC;

        try {
            $sortableMethod = new \ReflectionMethod($filter::class, 'sortable');
        } catch (\ReflectionException) {
            throw new \LogicException(
                sprintf(
                    '[%s][%s] sortable() is not defined on class %s.',
                    __CLASS__,
                    __FUNCTION__,
                    get_class($filter)
                )
            );
        }

        if (! $sortableMethod->isStatic()) {
            throw new \LogicException(
                sprintf(
                    '[%s][%s] %s::sortable() must be static.',
                    __CLASS__,
                    __FUNCTION__,
                    get_class($filter)
                )
            );
        }

        $sortableMethod->setAccessible(true);

        /** @var array<string, string|\Closure> $map */
        $map = $sortableMethod->invoke(null);
        $key = (string) $validated['sort_by'];

        if (! array_key_exists($key, $map)) {
            throw new \InvalidArgumentException(
                sprintf(
                    '[%s][%s] Invalid sort_by: %s',
                    __CLASS__,
                    __FUNCTION__,
                    $key
                )
            );
        }

        $spec = $map[$key];
        if (is_string($spec)) {
            $filter->setOrderClause(OrderClause::create($spec, $dirEnum));
        } elseif ($spec instanceof \Closure) {
            $filter->setOrderClause(OrderClause::custom($spec, $dirEnum));
        }
    }

    /**
     * Applies column selection.
     *
     * @param  array  $validated  Validated data
     * @param  BaseFilter  $filter  Filter instance
     */
    private function applyColumns(array $validated, BaseFilter $filter): void
    {
        if (($validated['columns'] ?? null) === null) {
            return;
        }

        $raw = $validated['columns'];

        if (is_string($raw)) {
            // "name, code" -> ['name', 'code']
            $parts = array_map('trim', explode(',', $raw));
        } elseif (is_array($raw)) {
            $parts = array_map(static fn ($v) => is_string($v) ? trim($v) : $v, $raw);
        } else {
            $parts = [];
        }

        // Remove empty and duplicate values.
        $parts = array_values(array_unique(array_filter($parts, fn ($v) => is_string($v) && $v !== '')));

        $hasRelational = array_filter($parts, fn ($c) => str_contains($c, '.'));
        if ($hasRelational) {
            throw new \InvalidArgumentException(
                sprintf(
                    '[%s][%s] Relational column (a.b) cannot be selected directly in columns.',
                    __CLASS__,
                    __FUNCTION__
                )
            );
        }

        $allowed = $filter::selectableColumns();
        if (empty($allowed)) {
            throw new \LogicException(
                sprintf(
                    '[%s][%s] %s::selectableColumns() returned an empty list. Define at least one selectable column.',
                    __CLASS__,
                    __FUNCTION__,
                    get_class($filter)
                )
            );
        }

        // Whitelist validation.
        $diff = array_diff($parts, $allowed);
        if (! empty($diff)) {
            throw new \InvalidArgumentException(
                sprintf(
                    '[%s][%s] Invalid columns parameter. Disallowed column(s): %s',
                    __CLASS__,
                    __FUNCTION__,
                    implode(', ', $diff)
                )
            );
        }

        $filter->setColumns($parts);
    }
}
