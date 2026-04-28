<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Support;

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Normalizes morph type values according to the Laravel `Relation::morphMap()` registration.
 *
 * If the application does not use `MorphMapKey` enum but morph map is defined, class -> alias mapping is applied.
 * If no map or no match exists, the value is returned as-is.
 */
final class RelationMorphType
{
    /**
     * @param  int|string|null  $type  Numeric morph key or full class name
     */
    public static function keyForType(int|string|null $type): int|string|null
    {
        if ($type === null || $type === '') {
            return null;
        }

        if (is_int($type)) {
            return $type;
        }

        if (is_numeric($type)) {
            return (int) $type;
        }

        $map = Relation::morphMap();
        if ($map === []) {
            return $type;
        }

        foreach ($map as $alias => $class) {
            if (! is_string($class)) {
                continue;
            }

            if ($type === $class || is_a($type, $class, true)) {
                return is_int($alias) ? $alias : (string) $alias;
            }
        }

        return $type;
    }
}
