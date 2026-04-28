<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Helpers;

use SlashDw\FilterKit\Enum\Operator;

/**
 * Wraps filter values (for example, %value% for LIKE).
 *
 * This class wraps values for LIKE/ILIKE and string-search operators.
 * It does not mutate the original value (immutable pattern).
 *
 * **Responsibility (SRP):**
 * - Value wrapping only
 *
 * **Operator Support:**
 * - LIKE/ILIKE: "%value%" (contains)
 * - STARTS_WITH: "value%" (starts with)
 * - ENDS_WITH: "%value" (ends with)
 *
 * **Performance:**
 * - Final class (prevents inheritance, compile-time optimization)
 * - Immutable pattern (original value is unchanged)
 * - Match expression
 *
 *
 * @example
 * $wrapper = new ValueWrapper();
 * $wrapped = $wrapper->wrap('test', Operator::ILIKE);
 * // '%test%'
 *
 * $wrapped = $wrapper->wrap('test', Operator::STARTS_WITH);
 * // 'test%'
 *
 * $wrapped = $wrapper->wrap('test', Operator::ENDS_WITH);
 * // '%test'
 */
final class ValueWrapper
{
    /**
     * Wraps a value (for example, %value% for LIKE).
     * Does not mutate the original value (immutable).
     *
     * @param  mixed  $value  Filter value
     * @param  Operator  $op  Operator (LIKE, ILIKE, STARTS_WITH, ENDS_WITH)
     * @return mixed Wrapped value
     *               - LIKE/ILIKE: "%value%"
     *               - STARTS_WITH: "value%"
     *               - ENDS_WITH: "%value"
     *               - Other: original value
     *
     * @example
     * $wrapped = $wrapper->wrap('test', Operator::ILIKE);
     * // '%test%'
     */
    public function wrap(mixed $value, Operator $op): mixed
    {
        return match ($op) {
            Operator::LIKE, Operator::ILIKE => "%{$value}%",
            Operator::STARTS_WITH => "{$value}%",
            Operator::ENDS_WITH => "%{$value}",
            default => $value,
        };
    }
}
