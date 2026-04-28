<?php

declare(strict_types=1);

namespace SlashDw\FilterKit;

/**
 * Multi-field search configuration.
 *
 * This class is used to search across all properties in a JSON column.
 * `jsonb_each_text()` provides dynamic search.
 *
 * excludedKeys: JSON keys to exclude from search.
 * These keys are filtered out from jsonb_each_text() results.
 *
 * @example
 * // Search all properties except url
 * new MultiFieldSearchConfig(excludedKeys: ['url'])
 * @example
 * // Search all properties (no excluded keys)
 * new MultiFieldSearchConfig()
 */
readonly class MultiFieldSearchConfig
{
    /**
     * @param  string[]  $excludedKeys  JSON keys to exclude from search
     */
    public function __construct(
        public array $excludedKeys = [],
    ) {}
}
