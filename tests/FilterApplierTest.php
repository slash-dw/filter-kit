<?php

declare(strict_types=1);

namespace SlashDw\FilterKit\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use SlashDw\FilterKit\Appliers\JsonColumnApplier;
use SlashDw\FilterKit\Appliers\RelationFilterApplier;
use SlashDw\FilterKit\Appliers\StandardFilterApplier;
use SlashDw\FilterKit\Enum\JsonOperator;
use SlashDw\FilterKit\Enum\Operator;
use SlashDw\FilterKit\MultiFieldSearchConfig;
use SlashDw\FilterKit\Parsers\JsonColumnParser;

final class FilterApplierTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('filter_applier_users', function ($table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('filter_applier_posts', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->integer('score')->default(0);
            $table->text('payload')->nullable();
        });

        Schema::create('filter_applier_comments', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->string('body');
        });
    }

    public function test_standard_filter_applier_basic_and_range_operators(): void
    {
        FilterApplierPost::query()->create(['user_id' => 1, 'title' => 'alpha', 'score' => 10, 'payload' => '{}']);
        FilterApplierPost::query()->create(['user_id' => 1, 'title' => 'beta', 'score' => 50, 'payload' => '{}']);

        $applier = new StandardFilterApplier;
        $query = FilterApplierPost::query();
        $applier->apply($query, 'score', Operator::BETWEEN, [5, 20], [5, 20]);

        $this->assertSame(['alpha'], $query->pluck('title')->all());
    }

    public function test_standard_filter_applier_throws_on_invalid_array_inputs(): void
    {
        $applier = new StandardFilterApplier;
        $query = FilterApplierPost::query();

        $this->expectException(\InvalidArgumentException::class);
        $applier->apply($query, 'score', Operator::IN, 'not-array', 'not-array');
    }

    public function test_standard_filter_applier_membership_null_and_negative_range_operators(): void
    {
        FilterApplierPost::query()->create(['user_id' => 1, 'title' => 'alpha', 'score' => 10, 'payload' => null]);
        FilterApplierPost::query()->create(['user_id' => 1, 'title' => 'beta', 'score' => 50, 'payload' => '{}']);
        FilterApplierPost::query()->create(['user_id' => 1, 'title' => 'gamma', 'score' => 90, 'payload' => '{}']);

        $applier = new StandardFilterApplier;

        $notBetween = FilterApplierPost::query();
        $applier->apply($notBetween, 'score', Operator::NOT_BETWEEN, [20, 80], [20, 80]);
        $this->assertSame(['alpha', 'gamma'], $notBetween->pluck('title')->all());

        $notIn = FilterApplierPost::query();
        $applier->apply($notIn, 'title', Operator::NOT_IN, ['beta'], ['beta']);
        $this->assertSame(['alpha', 'gamma'], $notIn->pluck('title')->all());

        $isNull = FilterApplierPost::query();
        $applier->apply($isNull, 'payload', Operator::IS_NULL, null, null);
        $this->assertSame(['alpha'], $isNull->pluck('title')->all());

        $notNull = FilterApplierPost::query();
        $applier->apply($notNull, 'payload', Operator::NOT_NULL, null, null);
        $this->assertSame(['beta', 'gamma'], $notNull->pluck('title')->all());
    }

    public function test_relation_filter_applier_single_and_chain(): void
    {
        $user = FilterApplierUser::query()->create(['name' => 'John']);
        $post = FilterApplierPost::query()->create(['user_id' => $user->id, 'title' => 'hello', 'score' => 2, 'payload' => '{}']);
        FilterApplierComment::query()->create(['post_id' => $post->id, 'body' => 'nice']);

        $applier = new RelationFilterApplier;

        $single = FilterApplierPost::query();
        $applier->applySingle($single, 'user', 'name', Operator::EQ, 'John', 'John');
        $this->assertSame(1, $single->count());

        $chain = FilterApplierPost::query();
        $applier->applyWhereHas($chain, 'comments', 'body', Operator::LIKE, 'nic', '%nic%');
        $this->assertSame(1, $chain->count());
    }

    public function test_relation_filter_applier_multiple_uses_or_logic(): void
    {
        $user = FilterApplierUser::query()->create(['name' => 'Alice']);
        $post = FilterApplierPost::query()->create(['user_id' => $user->id, 'title' => 'post', 'score' => 1, 'payload' => '{}']);
        FilterApplierComment::query()->create(['post_id' => $post->id, 'body' => 'other']);

        $applier = new RelationFilterApplier;
        $query = FilterApplierPost::query();
        $applier->applyMultiple($query, ['user.name', 'comments.body'], Operator::LIKE, 'ali', '%ali%');

        $this->assertSame(1, $query->count());
    }

    public function test_relation_filter_applier_in_and_not_in_paths(): void
    {
        $alice = FilterApplierUser::query()->create(['name' => 'Alice']);
        $bob = FilterApplierUser::query()->create(['name' => 'Bob']);
        FilterApplierPost::query()->create(['user_id' => $alice->id, 'title' => 'first', 'score' => 1, 'payload' => '{}']);
        FilterApplierPost::query()->create(['user_id' => $bob->id, 'title' => 'second', 'score' => 1, 'payload' => '{}']);

        $applier = new RelationFilterApplier;

        $in = FilterApplierPost::query();
        $applier->applySingle($in, 'user', 'name', Operator::IN, ['Alice'], ['Alice']);
        $this->assertSame(['first'], $in->pluck('title')->all());

        $notIn = FilterApplierPost::query();
        $applier->applyWhereHas($notIn, 'user', 'name', Operator::NOT_IN, ['Alice'], ['Alice']);
        $this->assertSame(['second'], $notIn->pluck('title')->all());
    }

    public function test_json_column_applier_builds_raw_predicates_and_bindings(): void
    {
        $applier = new JsonColumnApplier(new JsonColumnParser);
        $query = FilterApplierPost::query();

        $applier->apply($query, 'payload.title', JsonOperator::ILIKE, 'abc', 'filter_applier_posts');
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $this->assertStringContainsString('ILIKE', $sql);
        $this->assertSame(['%abc%'], $bindings);
    }

    public function test_json_column_applier_multifield_search_adds_exclusions(): void
    {
        $applier = new JsonColumnApplier(new JsonColumnParser);
        $query = FilterApplierPost::query();

        $applier->apply(
            $query,
            'payload',
            JsonOperator::ILIKE,
            'needle',
            'filter_applier_posts',
            new MultiFieldSearchConfig(['url', 'meta']),
        );

        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $this->assertStringContainsString('jsonb_each_text', $sql);
        $this->assertStringContainsString('kv.key NOT IN', $sql);
        $this->assertSame(['%needle%', 'url', 'meta'], $bindings);
    }

    public function test_json_column_applier_rejects_non_array_membership_values(): void
    {
        $applier = new JsonColumnApplier(new JsonColumnParser);
        $query = FilterApplierPost::query();

        $this->expectException(\InvalidArgumentException::class);
        $applier->apply($query, 'payload.title', JsonOperator::IN, 'not-array', 'filter_applier_posts');
    }
}

/** @property int $id */
final class FilterApplierUser extends Model
{
    protected $table = 'filter_applier_users';

    public $timestamps = false;

    protected $guarded = [];

    public function posts(): HasMany
    {
        return $this->hasMany(FilterApplierPost::class, 'user_id');
    }
}

/** @property int $id */
final class FilterApplierPost extends Model
{
    protected $table = 'filter_applier_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(FilterApplierUser::class, 'user_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FilterApplierComment::class, 'post_id');
    }
}

/** @property int $id */
final class FilterApplierComment extends Model
{
    protected $table = 'filter_applier_comments';

    public $timestamps = false;

    protected $guarded = [];

    public function post(): BelongsTo
    {
        return $this->belongsTo(FilterApplierPost::class, 'post_id');
    }
}
