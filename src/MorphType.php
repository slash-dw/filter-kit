<?php

declare(strict_types=1);

namespace SlashDw\FilterKit;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class MorphType extends Filterable {}
