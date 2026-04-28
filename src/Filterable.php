<?php

declare(strict_types=1);

namespace SlashDw\FilterKit;

use Attribute;
use SlashDw\FilterKit\Enum\Operator;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Filterable
{
    /**
     * @param  string|string[]|null  $columns  Column name or column list
     * @param  Operator  $operator  Filter operator
     * @param  bool  $whereHas  Use whereHas for relation filtering (example: "user.is_active")
     */
    public function __construct(
        public string|array|null $columns = null,
        public Operator $operator = Operator::EQ,
        public bool $whereHas = false,
    ) {}
}
