# slash-dw/filter-kit

A Laravel filtering pipeline package for Eloquent queries.

## Requirements

- PHP `^8.5`
- `slash-dw/core-kit` `^0.0.1`
- Laravel `^13.0`

Exact Laravel component constraints are defined in `composer.json`.

## What This Package Provides

- `BaseFilter` pipeline
- Attributes: `Filterable`, `JsonFilterable`, `MorphType`
- Builders: `FilterFromRequestBuilder`, `FilterReflector`
- Appliers: `StandardFilterApplier`, `RelationFilterApplier`, `JsonColumnApplier`
- Value objects: `FetchType`, `OrderClause`, `MultiFieldSearchConfig`

## Quick Usage

```php
use SlashDw\FilterKit\BaseFilter;
use SlashDw\FilterKit\Enum\Operator;
use SlashDw\FilterKit\FetchType;
use SlashDw\FilterKit\Filterable;

final class ItemFilter extends BaseFilter
{
    #[Filterable(columns: 'title', operator: Operator::ILIKE)]
    public ?string $q = null;
}

$filter = ItemFilter::fromRequest($request)
    ->setFetchType(FetchType::paginate('/items', 30));
```

## Test Status

- PHPUnit: 25 tests / 102 assertions
- PHPStan: clean (package level: 5)
- Pint: passed

## Dev Commands

```bash
composer install
./vendor/bin/phpunit -c phpunit.xml.dist
./vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=1G
./vendor/bin/pint --format agent
```
