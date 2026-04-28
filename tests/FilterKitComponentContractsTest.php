<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use SlashDw\FilterKit\Enum\FetchTypeEnum;
use SlashDw\FilterKit\Enum\JsonOperator;
use SlashDw\FilterKit\Enum\Operator;
use SlashDw\FilterKit\Enum\SortDirection;
use SlashDw\FilterKit\Filterable;
use SlashDw\FilterKit\Helpers\ColumnQualifier;
use SlashDw\FilterKit\Helpers\ValueWrapper;
use SlashDw\FilterKit\JsonFilterable;
use SlashDw\FilterKit\MorphType;
use SlashDw\FilterKit\MultiFieldSearchConfig;
use SlashDw\FilterKit\Parsers\JsonColumnParser;
use SlashDw\FilterKit\Support\RelationMorphType;

final class FilterKitComponentContractsTest extends TestCase
{
    public function test_json_column_parser_parse_and_expression(): void
    {
        $parser = new JsonColumnParser;

        $this->assertSame(
            ['column' => 'data', 'path' => 'message_1'],
            $parser->parse(' data . message_1 '),
        );

        $this->assertSame(
            '"notifications"."payload"->>\'title\'',
            $parser->buildExpression('notifications', 'payload', 'title'),
        );
    }

    public function test_json_column_parser_rejects_invalid_formats(): void
    {
        $parser = new JsonColumnParser;

        foreach (['data', 'data.', '.path', 'data.data', 'data.bad-path'] as $invalid) {
            try {
                $parser->parse($invalid);
                $this->fail('Expected InvalidArgumentException for: '.$invalid);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('invalid', $e->getMessage());
            }
        }
    }

    public function test_column_qualifier_and_value_wrapper(): void
    {
        $qualifier = new ColumnQualifier;
        $wrapper = new ValueWrapper;

        $this->assertSame('users.name', $qualifier->qualifyIfBare('name', 'users'));
        $this->assertSame('users.name', $qualifier->qualifyIfBare('users.name', 'users'));

        $this->assertSame('%foo%', $wrapper->wrap('foo', Operator::ILIKE));
        $this->assertSame('%foo%', $wrapper->wrap('foo', Operator::LIKE));
        $this->assertSame('foo%', $wrapper->wrap('foo', Operator::STARTS_WITH));
        $this->assertSame('%foo', $wrapper->wrap('foo', Operator::ENDS_WITH));
        $this->assertSame('foo', $wrapper->wrap('foo', Operator::EQ));
    }

    public function test_enums_and_to_array(): void
    {
        $this->assertSame('=', Operator::EQ->value);
        $this->assertSame('json_ilike', JsonOperator::ILIKE->value);
        $this->assertSame('asc', SortDirection::ASC->value);
        $this->assertSame(5, FetchTypeEnum::INFINITE_SCROLL->value);

        $this->assertSame(
            ['key' => 'DESC', 'value' => 'desc', 'description' => 'DESC'],
            SortDirection::DESC->toArray(),
        );
        $this->assertSame(
            ['key' => 'PLUCK', 'value' => 3, 'description' => 'PLUCK'],
            FetchTypeEnum::PLUCK->toArray(),
        );
    }

    public function test_attributes_and_multi_field_search_config_defaults(): void
    {
        $filterable = new Filterable(columns: ['name'], operator: Operator::LIKE, whereHas: true);
        $this->assertSame(['name'], $filterable->columns);
        $this->assertSame(Operator::LIKE, $filterable->operator);
        $this->assertTrue($filterable->whereHas);

        $cfg = new MultiFieldSearchConfig(['url']);
        $jsonFilterable = new JsonFilterable(columns: 'data.message', operator: JsonOperator::EQ, multiFieldSearchConfig: $cfg);
        $this->assertSame('data.message', $jsonFilterable->columns);
        $this->assertSame(JsonOperator::EQ, $jsonFilterable->operator);
        $this->assertSame(['url'], $jsonFilterable->multiFieldSearchConfig?->excludedKeys);

        $morphType = new MorphType(columns: 'morph_type', operator: Operator::EQ, whereHas: false);
        $this->assertSame('morph_type', $morphType->columns);
        $this->assertInstanceOf(Filterable::class, $morphType);
    }

    public function test_relation_morph_type_normalization(): void
    {
        Relation::morphMap([], false);

        $this->assertNull(RelationMorphType::keyForType(null));
        $this->assertNull(RelationMorphType::keyForType(''));
        $this->assertSame(10, RelationMorphType::keyForType(10));
        $this->assertSame(22, RelationMorphType::keyForType('22'));
        $this->assertSame('App\\Models\\User', RelationMorphType::keyForType('App\\Models\\User'));

        Relation::morphMap([
            'user' => MorphMapUser::class,
            9 => MorphMapAdmin::class,
        ], false);

        $this->assertSame('user', RelationMorphType::keyForType(MorphMapUser::class));
        $this->assertSame('user', RelationMorphType::keyForType(MorphMapAdmin::class));
        $this->assertSame('Unknown\\Class', RelationMorphType::keyForType('Unknown\\Class'));

        Relation::morphMap([], false);
    }

    public function test_filter_related_attributes_are_property_targeted(): void
    {
        $filterable = new ReflectionClass(Filterable::class);
        $jsonFilterable = new ReflectionClass(JsonFilterable::class);
        $morphType = new ReflectionClass(MorphType::class);

        $this->assertNotEmpty($filterable->getAttributes(\Attribute::class));
        $this->assertNotEmpty($jsonFilterable->getAttributes(\Attribute::class));
        $this->assertNotEmpty($morphType->getAttributes(\Attribute::class));
    }
}

class MorphMapUser extends Model {}

class MorphMapAdmin extends MorphMapUser {}
