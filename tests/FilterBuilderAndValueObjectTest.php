<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use SlashDw\FilterKit\BaseFilter;
use SlashDw\FilterKit\Builders\FilterFromRequestBuilder;
use SlashDw\FilterKit\Builders\FilterReflector;
use SlashDw\FilterKit\Enum\FetchTypeEnum;
use SlashDw\FilterKit\Enum\JsonOperator;
use SlashDw\FilterKit\Enum\Operator;
use SlashDw\FilterKit\Enum\SortDirection;
use SlashDw\FilterKit\FetchType;
use SlashDw\FilterKit\Filterable;
use SlashDw\FilterKit\JsonFilterable;
use SlashDw\FilterKit\MorphType;
use SlashDw\FilterKit\MultiFieldSearchConfig;
use SlashDw\FilterKit\OrderClause;

final class FilterBuilderAndValueObjectTest extends TestCase
{
    public function test_fetch_type_factories_and_serialization(): void
    {
        $paginate = FetchType::paginate('/items', 50);
        $this->assertSame(FetchTypeEnum::PAGINATE, $paginate->getType());
        $this->assertTrue($paginate->isPaginate());
        $this->assertSame('/items', $paginate->getPaginationPath());
        $this->assertSame(50, $paginate->getPerPage());

        $pluck = FetchType::pluck('name', 'id');
        $this->assertTrue($pluck->isPluck());
        $this->assertSame('name', $pluck->getColumnForValue());
        $this->assertSame('id', $pluck->getColumnForKey());
        $this->assertSame($pluck->toArray(), $pluck->jsonSerialize());

        $scroll = FetchType::infiniteScroll(10, 25);
        $this->assertTrue($scroll->isInfiniteScroll());
        $this->assertSame(10, $scroll->getOffset());
        $this->assertSame(25, $scroll->getLimit());
    }

    public function test_order_clause_create_custom_and_default_direction(): void
    {
        $order = OrderClause::create('created_at');
        $this->assertSame('created_at', $order->getColumn());
        $this->assertSame('asc', $order->getDirection());

        $closure = static function (Builder $builder, string $direction): void {
            $builder->orderBy('id', $direction);
        };
        $custom = OrderClause::custom($closure, SortDirection::DESC);
        $this->assertSame('__custom__', $custom->getColumn());
        $this->assertSame('desc', $custom->getDirection());
        $this->assertSame($closure, $custom->getCustomApplier());
        $this->assertSame(['column' => '__custom__', 'direction' => 'desc'], $custom->toArray());
    }

    public function test_filter_reflector_reads_filterable_json_and_morph_attributes(): void
    {
        $filter = new AttributeDrivenFilter;
        $filter->name = 'john';
        $filter->jsonSearch = 'x';
        $filter->morph = 'User';

        $reflector = new FilterReflector;
        $local = $reflector->getLocalFilters($filter);
        $standard = null;
        $json = null;
        $morph = null;
        foreach ($local as $row) {
            if ($row['is_json']) {
                $json = $row;

                continue;
            }

            if ($row['columns'] === ['morph_type']) {
                $morph = $row;

                continue;
            }

            $standard = $row;
        }

        $this->assertCount(3, $local);
        $this->assertIsArray($standard);
        $this->assertIsArray($json);
        $this->assertIsArray($morph);

        $this->assertSame(['name'], $standard['columns']);
        $this->assertSame(Operator::ILIKE, $standard['operator']);
        $this->assertSame('john', $standard['value']);

        $this->assertTrue($json['is_json']);
        $this->assertSame(JsonOperator::ILIKE, $json['json_operator']);
        $this->assertSame(['url'], $json['multiFieldSearchConfig']?->excludedKeys);

        $this->assertSame(Operator::EQ, $morph['operator']);
    }

    public function test_filter_from_request_builder_converts_types_and_applies_sort_columns(): void
    {
        $request = new FakeFilterFormRequest([
            'active' => 'true',
            'age' => '42',
            'ratio' => '1.75',
            'title' => 123,
            'tags' => '["a","b"]',
            'created_at_end' => '2026-01-01 10:00:00',
            'state' => 'done',
            'offset' => '5',
            'limit' => '15',
            'sort_by' => 'name',
            'sort_dir' => 'DESC',
            'columns' => 'name, code, name',
        ]);

        $filter = new RequestBuilderFilter;
        $builder = new FilterFromRequestBuilder;
        $builder->build($request, $filter);

        $this->assertTrue($filter->active);
        $this->assertSame(42, $filter->age);
        $this->assertSame(1.75, $filter->ratio);
        $this->assertSame('123', $filter->title);
        $this->assertSame(['a', 'b'], $filter->tags);
        $this->assertSame('2026-01-01 23:59:59', $filter->created_at_end);
        $this->assertSame(RequestBuilderState::Done, $filter->state);
        $this->assertSame(5, $filter->getOffset());
        $this->assertSame(15, $filter->getLimit());
        $orderClause = $filter->getOrderClause();
        $this->assertInstanceOf(OrderClause::class, $orderClause);
        $this->assertSame('desc', $orderClause->getDirection());
        $this->assertSame('users.name', $orderClause->getColumn());
        $this->assertSame(['name', 'code'], $filter->getColumns());
    }

    public function test_filter_from_request_builder_throws_on_invalid_sort_or_columns(): void
    {
        $builder = new FilterFromRequestBuilder;
        $filter = new RequestBuilderFilter;

        $this->expectException(\InvalidArgumentException::class);
        $builder->build(new FakeFilterFormRequest(['sort_by' => 'unknown']), $filter);
    }

    public function test_filter_from_request_builder_throws_on_relational_column_selection(): void
    {
        $builder = new FilterFromRequestBuilder;
        $filter = new RequestBuilderFilter;

        $this->expectException(\InvalidArgumentException::class);
        $builder->build(new FakeFilterFormRequest(['columns' => ['name', 'user.email']]), $filter);
    }

    public function test_filter_from_request_builder_handles_array_fallback_ignored_keys_and_custom_sort(): void
    {
        $builder = new FilterFromRequestBuilder;
        $filter = new RequestBuilderFilter;

        $builder->build(new FakeFilterFormRequest([
            'tags' => 'not-json',
            'unknown' => 'ignored',
            'sort_by' => 'custom',
        ]), $filter);

        $this->assertSame(['not-json'], $filter->tags);

        $orderClause = $filter->getOrderClause();
        $this->assertInstanceOf(OrderClause::class, $orderClause);
        $this->assertSame('asc', $orderClause->getDirection());
        $this->assertNotNull($orderClause->getCustomApplier());
    }

    public function test_filter_from_request_builder_throws_on_invalid_backed_enum_value(): void
    {
        $builder = new FilterFromRequestBuilder;
        $filter = new RequestBuilderFilter;

        $this->expectException(\ValueError::class);
        $builder->build(new FakeFilterFormRequest(['state' => 'missing']), $filter);
    }

    public function test_filter_from_request_builder_throws_when_selectable_columns_are_not_defined(): void
    {
        $builder = new FilterFromRequestBuilder;
        $filter = new EmptySelectableFilter;

        $this->expectException(\LogicException::class);
        $builder->build(new FakeFilterFormRequest(['columns' => ['name']]), $filter);
    }
}

final class FakeFilterFormRequest extends FormRequest
{
    public function __construct(private readonly array $data) {}

    public function rules(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return true;
    }

    public function validated($key = null, $default = null): array
    {
        return $this->data;
    }
}

enum RequestBuilderState: string
{
    case Done = 'done';
}

final class RequestBuilderFilter extends BaseFilter
{
    public bool $active = false;

    public int $age = 0;

    public float $ratio = 0;

    public string $title = '';

    public array $tags = [];

    public ?string $created_at_end = null;

    public ?RequestBuilderState $state = null;

    protected static function sortable(): array
    {
        return [
            'name' => 'users.name',
            'custom' => static fn (Builder $q, string $dir): Builder => $q->orderBy('users.id', $dir),
        ];
    }

    public static function selectableColumns(): array
    {
        return ['name', 'code'];
    }
}

final class AttributeDrivenFilter extends BaseFilter
{
    #[Filterable(columns: ['name'], operator: Operator::ILIKE, whereHas: false)]
    public ?string $name = null;

    #[JsonFilterable(columns: 'data.message', operator: JsonOperator::ILIKE, multiFieldSearchConfig: new MultiFieldSearchConfig(['url']))]
    public ?string $jsonSearch = null;

    #[MorphType(columns: 'morph_type', operator: Operator::EQ)]
    public ?string $morph = null;
}

final class EmptySelectableFilter extends BaseFilter
{
    public ?string $name = null;
}
