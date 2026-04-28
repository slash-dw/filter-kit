<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Helpers;

/**
 * Qualifies column names with table name.
 *
 * This class qualifies bare column names with table name.
 * Already-qualified columns (for example, "table.column") are left unchanged.
 *
 * **Responsibility (SRP):**
 * - Column qualification only
 *
 * **Performance:**
 * - Final class (prevents inheritance, compile-time optimization)
 * - Minimal string operations
 *
 *
 * @example
 * $qualifier = new ColumnQualifier();
 * $qualified = $qualifier->qualifyIfBare('name', 'users');
 * // 'users.name'
 *
 * $qualified = $qualifier->qualifyIfBare('users.name', 'users');
 * // 'users.name' (already qualified)
 */
final class ColumnQualifier
{
    /**
     * Qualifies a column name when it is bare.
     *
     * If the column is already qualified (contains a dot), it is returned unchanged.
     * Otherwise qualifies with table name.
     *
     * @param  string  $column  Column name (bare or qualified)
     * @param  string  $table  Table name
     * @return string Qualified column name
     *
     * @example
     * $qualified = $qualifier->qualifyIfBare('name', 'users');
     * // 'users.name'
     *
     * $qualified = $qualifier->qualifyIfBare('users.name', 'users');
     * // 'users.name'
     */
    public function qualifyIfBare(string $column, string $table): string
    {
        return str_contains($column, '.') ? $column : "{$table}.{$column}";
    }
}
