<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use SlashDw\FilterKit\BaseFilter;
use SlashDw\FilterKit\Enum\Operator;
use SlashDw\FilterKit\FetchType;
use SlashDw\FilterKit\Filterable;
use SlashDw\FilterKit\MorphType;
use SlashDw\FilterKit\OrderClause;

final class BaseFilterPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('base_filter_users', function ($table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('base_filter_items', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('title');
            $table->string('owner_type')->nullable();
            $table->integer('score')->default(0);
        });

        Schema::create('base_filter_comments', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('body');
            $table->integer('rating')->default(0);
        });
    }

    public function test_apply_requires_fetch_type(): void
    {
        $filter = new PipelineItemFilter;
        $this->expectException(\LogicException::class);
        $filter->apply(PipelineItem::query());
    }

    public function test_ids_columns_and_collect_pipeline(): void
    {
        $first = PipelineItem::query()->create(['title' => 'A', 'score' => 10]);
        PipelineItem::query()->create(['title' => 'B', 'score' => 20]);

        $filter = new PipelineItemFilter;
        $filter->setIds([(string) $first->id]);
        $filter->setColumns(['title']);
        $filter->setFetchType(FetchType::collect());

        $rows = $filter->apply(PipelineItem::query());

        $this->assertCount(1, $rows);
        $this->assertSame('A', $rows->first()->title);
        $this->assertNotNull($rows->first()->id);
    }

    public function test_empty_ids_excluded_ids_limit_and_offset_are_applied(): void
    {
        $first = PipelineItem::query()->create(['title' => 'A', 'score' => 10]);
        PipelineItem::query()->create(['title' => 'B', 'score' => 20]);
        PipelineItem::query()->create(['title' => 'C', 'score' => 30]);

        $emptyIds = new PipelineItemFilter;
        $emptyIds->setIds([]);
        $emptyIds->setFetchType(FetchType::collect());
        $this->assertCount(0, $emptyIds->apply(PipelineItem::query()));

        $windowed = new PipelineItemFilter;
        $windowed->setExcludedIds([(int) $first->id]);
        $windowed->setOffset(0);
        $windowed->setLimit(1);
        $windowed->setFetchType(FetchType::collect());

        $rows = $windowed->apply(PipelineItem::query()->orderBy('id'));

        $this->assertCount(1, $rows);
        $this->assertSame('B', $rows->first()->title);
    }

    public function test_setters_reject_negative_limit_and_offset(): void
    {
        $filter = new PipelineItemFilter;

        try {
            $filter->setLimit(-1);
            $this->fail('Expected InvalidArgumentException for negative limit.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Limit', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $filter->setOffset(-1);
    }

    public function test_order_clause_relation_column_and_custom_applier(): void
    {
        $u1 = PipelineUser::query()->create(['name' => 'Alice']);
        $u2 = PipelineUser::query()->create(['name' => 'Bob']);
        PipelineItem::query()->create(['title' => 'X', 'score' => 1, 'user_id' => $u2->id]);
        PipelineItem::query()->create(['title' => 'Y', 'score' => 2, 'user_id' => $u1->id]);

        $relationOrder = new PipelineItemFilter;
        $relationOrder->setOrderClause(OrderClause::create('user.name'));
        $relationOrder->setFetchType(FetchType::collect());
        $ordered = $relationOrder->apply(PipelineItem::query()->join('base_filter_users', 'base_filter_items.user_id', '=', 'base_filter_users.id')->select('base_filter_items.*'));
        $this->assertSame('Y', $ordered->first()->title);

        $customOrder = new PipelineItemFilter;
        $customOrder->setOrderClause(OrderClause::custom(
            static fn (Builder $q, string $dir): Builder => $q->orderBy('base_filter_items.score', $dir),
        ));
        $customOrder->setFetchType(FetchType::collect());
        $custom = $customOrder->apply(PipelineItem::query());
        $this->assertSame('X', $custom->first()->title);
    }

    public function test_pluck_with_relation_auto_join(): void
    {
        $u1 = PipelineUser::query()->create(['name' => 'Alice']);
        $u2 = PipelineUser::query()->create(['name' => 'Bob']);
        PipelineItem::query()->create(['title' => 'Item1', 'user_id' => $u1->id]);
        PipelineItem::query()->create(['title' => 'Item2', 'user_id' => $u2->id]);

        $filter = new PipelineItemFilter;
        $filter->setFetchType(FetchType::pluck('user.name', 'title'));

        $pluck = $filter->apply(PipelineItem::query());

        $this->assertSame(['Item1' => 'Alice', 'Item2' => 'Bob'], $pluck->all());
    }

    public function test_model_paginate_infinite_scroll_and_eager_aggregate_fetches(): void
    {
        $user = PipelineUser::query()->create(['name' => 'Alice']);
        $first = PipelineItem::query()->create(['title' => 'A', 'score' => 10, 'user_id' => $user->id]);
        PipelineItem::query()->create(['title' => 'B', 'score' => 20, 'user_id' => $user->id]);
        PipelineComment::query()->create(['item_id' => $first->id, 'body' => 'first', 'rating' => 4]);
        PipelineComment::query()->create(['item_id' => $first->id, 'body' => 'second', 'rating' => 8]);

        $modelFilter = new PipelineItemFilter;
        $modelFilter->setWithRelations(['user']);
        $modelFilter->setWithCount(['comments']);
        $modelFilter->setWithAvg([['comments', 'rating']]);
        $modelFilter->setFetchType(FetchType::model());

        $model = $modelFilter->apply(PipelineItem::query()->orderBy('id'));
        $this->assertInstanceOf(PipelineItem::class, $model);
        $this->assertTrue($model->relationLoaded('user'));
        $this->assertSame(2, $model->getAttribute('comments_count'));
        $this->assertSame(6.0, (float) $model->getAttribute('comments_avg_rating'));

        $scrollFilter = new PipelineItemFilter;
        $scrollFilter->setFetchType(FetchType::infiniteScroll(1, 1));
        $scroll = $scrollFilter->apply(PipelineItem::query()->orderBy('id'));
        $this->assertSame(['B'], $scroll->pluck('title')->all());

        $paginateFilter = new PipelineItemFilter;
        $paginateFilter->setLimit(1);
        $paginateFilter->setOffset(1);
        $paginateFilter->setFetchType(FetchType::paginate('/items', 1));
        $paginator = $paginateFilter->apply(PipelineItem::query()->orderBy('id'));
        $this->assertSame(2, $paginator->total());
        $this->assertSame('/items', $paginator->path());
    }

    public function test_morph_eq_is_transformed_to_including_alias_and_class(): void
    {
        Relation::morphMap(['owner-user' => PipelineUser::class], false);

        PipelineItem::query()->create(['title' => 'AliasHit', 'owner_type' => 'owner-user']);
        PipelineItem::query()->create(['title' => 'ClassHit', 'owner_type' => PipelineUser::class]);
        PipelineItem::query()->create(['title' => 'Other', 'owner_type' => 'x']);

        $filter = new PipelineItemFilter;
        $filter->ownerType = PipelineUser::class;
        $filter->setFetchType(FetchType::collect());

        $rows = $filter->apply(PipelineItem::query());
        $titles = $rows->pluck('title')->all();

        $this->assertSame(['AliasHit', 'ClassHit'], $titles);

        Relation::morphMap([], false);
    }
}

/** @property int $id */
final class PipelineUser extends Model
{
    protected $table = 'base_filter_users';

    public $timestamps = false;

    protected $guarded = [];
}

/** @property int $id */
final class PipelineItem extends Model
{
    protected $table = 'base_filter_items';

    public $timestamps = false;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(PipelineUser::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PipelineComment::class, 'item_id');
    }
}

/** @property int $id */
final class PipelineComment extends Model
{
    protected $table = 'base_filter_comments';

    public $timestamps = false;

    protected $guarded = [];
}

final class PipelineItemFilter extends BaseFilter
{
    #[Filterable(columns: 'title', operator: Operator::LIKE)]
    public ?string $title = null;

    #[MorphType(columns: 'owner_type', operator: Operator::EQ)]
    public ?string $ownerType = null;
}
