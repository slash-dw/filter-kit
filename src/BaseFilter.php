<?php

declare(strict_types=1);

namespace SlashDw\FilterKit;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use SlashDw\CoreKit\Contracts\EloquentQueryFilterContract;
use SlashDw\FilterKit\Appliers\JsonColumnApplier;
use SlashDw\FilterKit\Appliers\RelationFilterApplier;
use SlashDw\FilterKit\Appliers\StandardFilterApplier;
use SlashDw\FilterKit\Builders\FilterFromRequestBuilder;
use SlashDw\FilterKit\Builders\FilterReflector;
use SlashDw\FilterKit\Enum\FetchTypeEnum;
use SlashDw\FilterKit\Enum\JsonOperator;
use SlashDw\FilterKit\Enum\Operator;
use SlashDw\FilterKit\Helpers\ColumnQualifier;
use SlashDw\FilterKit\Helpers\ValueWrapper;
use SlashDw\FilterKit\Parsers\JsonColumnParser;
use SlashDw\FilterKit\Support\RelationMorphType;

/**
 * Class BaseFilter
 *
 * Abstract filter class designed to be applied to Eloquent queries
 * with various filtering, sorting, and fetchType options.
 *
 * Features:
 *   - Eager loading: load relations with setWithRelations()
 *   - Count eager loading: load only counts with setWithCount() (prevents N+1 queries)
 *   - Filtering: automatic filtering with Filterable attributes
 *   - Sorting: flexible sorting with OrderClause
 *   - Pagination: paginate, collect, and pluck support with FetchType
 *
 * Implements:
 *   - \Illuminate\Contracts\Support\Arrayable
 *     -> converts the filter state to an array with ->toArray()
 *   - \JsonSerializable
 *     -> allows json_encode($filter) directly
 *
 *
 * @example
 * // Single line in a controller:
 * // 1) Validate with FormRequest
 * // 2) Create $filter instance
 * // 3) apply+get
 * $filter  = CategoryFilter::fromRequest($request);
 * $results = Category::query()
 *     ->filter($filter)
 *     ->get();
 * @example
 * // Eager loading and withCount usage:
 * $filter = new CompanyManagementFilter();
 * $filter->setWithRelations(['company', 'branch', 'franchise']); // Load all relations
 * $filter->setWithCount(['unreadNotifications']); // Load only counts (prevents N+1 queries)
 * $companies = $filter->apply(UserCompany::query());
 * // Usage: $company->unread_notifications_count
 *
 * @phpstan-consistent-constructor
 */
abstract class BaseFilter implements \JsonSerializable, Arrayable, EloquentQueryFilterContract
{
    /**
     * Static helper instances (singleton pattern).
     * All filter instances share these helpers (performance optimization).
     */
    private static ?FilterReflector $reflector = null;

    private static ?ValueWrapper $valueWrapper = null;

    private static ?JsonColumnApplier $jsonApplier = null;

    private static ?StandardFilterApplier $standardApplier = null;

    private static ?RelationFilterApplier $relationApplier = null;

    private static ?ColumnQualifier $qualifier = null;

    /**
     * List of IDs used to filter specific records.
     *
     * @var string[]
     *
     * @example ["ids" => [1,2,3]]
     */
    private array $ids = [];

    /**
     * Becomes true when setIds() is called; even an empty array applies whereIn (returns 0 rows).
     */
    private bool $idsRestrictApplied = false;

    /**
     * Returns only the specified columns.
     *
     * @var string[]
     *
     * @example ["columns" => ['name','price']]
     */
    private array $columns = [];

    /**
     * List of IDs to exclude.
     *
     * @var int[]
     *
     * @example ["excludedIds" => [5,6]]
     */
    private array $excludedIds = [];

    /**
     * Relations to eager load.
     *
     * @var string[]
     *
     * @example ["withRelations" => ['category','owner']]
     */
    private array $withRelations = [];

    /**
     * Relations to count with withCount.
     *
     * Eager loads relation counts using Laravel's withCount() method.
     * Prevents N+1 queries and only loads count values (not full relations).
     *
     * Example: ['unreadNotifications', 'comments'] creates
     * $model->unread_notifications_count and $model->comments_count attributes on each model.
     *
     * @var string[]
     *
     * @example ["withCount" => ['unreadNotifications','comments']]
     *
     * @see https://laravel.com/docs/eloquent-relationships#counting-related-models
     */
    private array $withCount = [];

    /**
     * Relations whose averages are loaded with withAvg.
     * Each item is in [relation, column] format; example: [['feedbacks', 'score']] -> feedbacks_avg_score.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $withAvg = [];

    /**
     * Sorting information as an OrderClause object.
     */
    private ?OrderClause $orderClause = null;

    /**
     * Query-level LIMIT support.
     */
    private ?int $limit = null;

    /**
     * Query-level OFFSET support.
     */
    private ?int $offset = null;

    /**
     * Fetch type information as a FetchType object (paginate, pluck, collect).
     */
    private ?FetchType $fetchType = null;

    /**
     * Returns the ID filter.
     *
     * @return string[]
     */
    public function getIds(): array
    {
        return $this->ids;
    }

    /**
     * Sets the ID filter.
     *
     * @param  string[]  $ids
     * @return $this
     */
    public function setIds(array $ids): self
    {
        $this->ids = $ids;
        $this->idsRestrictApplied = true;

        return $this;
    }

    /**
     * Adds a single ID without duplicates.
     *
     * @return $this
     */
    public function addId(string $id): self
    {
        if (! in_array($id, $this->ids)) {
            $this->ids[] = $id;
        }

        $this->idsRestrictApplied = true;

        return $this;
    }

    /**
     * Returns the list of columns to select.
     *
     * @return string[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Sets the list of columns to select.
     *
     * @param  string[]  $columns
     * @return $this
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Adds a single column without duplicates.
     *
     * @return $this
     */
    public function addColumn(string $column): self
    {
        if (! in_array($column, $this->columns)) {
            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * Returns the list of IDs to exclude.
     *
     * @return int[]
     */
    public function getExcludedIds(): array
    {
        return $this->excludedIds;
    }

    /**
     * Sets the list of IDs to exclude.
     *
     * @param  int[]  $excludedIds
     * @return $this
     */
    public function setExcludedIds(array $excludedIds): self
    {
        $this->excludedIds = $excludedIds;

        return $this;
    }

    /**
     * Adds a single excluded ID.
     *
     * @return $this
     */
    public function addExcludedId(int $excludedId): self
    {
        if (! in_array($excludedId, $this->excludedIds)) {
            $this->excludedIds[] = $excludedId;
        }

        return $this;
    }

    /**
     * Returns relations to eager load.
     *
     * @return string[] Relation name list
     *
     * @example ['company', 'branch', 'franchise']
     */
    public function getWithRelations(): array
    {
        return $this->withRelations;
    }

    /**
     * Sets relations to eager load.
     *
     * The specified relations are eager loaded with with().
     * Prevents N+1 queries but loads full relation data.
     *
     * Use setWithCount() when you only need count values.
     *
     * Example usage:
     * ```php
     * $filter->setWithRelations(['company', 'branch', 'franchise']);
     * // Result: $userCompany->company, $userCompany->branch, $userCompany->franchise
     * ```
     *
     * @param  string[]  $withRelations  Relation name list
     * @return $this
     *
     * @see setWithCount() More efficient alternative when only counts are needed
     */
    public function setWithRelations(array $withRelations): self
    {
        $this->withRelations = $withRelations;

        return $this;
    }

    /**
     * Adds a single relation.
     *
     * @return $this
     */
    public function addWithRelation(string $relation): self
    {
        if (! in_array($relation, $this->withRelations)) {
            $this->withRelations[] = $relation;
        }

        return $this;
    }

    /**
     * Returns relations to count with withCount.
     *
     * @return string[] Relation name list
     *
     * @example ['unreadNotifications', 'comments']
     */
    public function getWithCount(): array
    {
        return $this->withCount;
    }

    /**
     * Sets relations to count with withCount.
     *
     * Count values are eager loaded for the specified relations.
     * Each relation creates a {relation}_count attribute on the model.
     *
     * Example usage:
     * ```php
     * $filter->setWithCount(['unreadNotifications']);
     * // Result: $userCompany->unread_notifications_count
     * ```
     *
     * @param  string[]  $withCount  Relation name list
     * @return $this
     *
     * @see https://laravel.com/docs/eloquent-relationships#counting-related-models
     */
    public function setWithCount(array $withCount): self
    {
        $this->withCount = $withCount;

        return $this;
    }

    /**
     * Adds a single withCount relation.
     *
     * Adds a new relation to the withCount list (with duplicate check).
     *
     * Example usage:
     * ```php
     * $filter->addWithCount('unreadNotifications');
     * $filter->addWithCount('comments');
     * ```
     *
     * @param  string  $relation  Relation name to add
     * @return $this
     */
    public function addWithCount(string $relation): self
    {
        if (! in_array($relation, $this->withCount)) {
            $this->withCount[] = $relation;
        }

        return $this;
    }

    /**
     * Returns relations whose averages are loaded with withAvg.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public function getWithAvg(): array
    {
        return $this->withAvg;
    }

    /**
     * Sets relations whose averages are loaded with withAvg.
     *
     * @param  array<int, array{0: string, 1: string}>  $withAvg  Each item is [relation, column]; example: [['feedbacks', 'score']]
     * @return $this
     */
    public function setWithAvg(array $withAvg): self
    {
        $this->withAvg = $withAvg;

        return $this;
    }

    /**
     * Returns sorting information.
     */
    public function getOrderClause(): ?OrderClause
    {
        return $this->orderClause;
    }

    /**
     * Sets sorting information.
     *
     * @return $this
     */
    public function setOrderClause(?OrderClause $orderClause): self
    {
        $this->orderClause = $orderClause;

        return $this;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * @return $this
     */
    public function setLimit(?int $limit): self
    {
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('Limit must be >= 0.');
        }
        $this->limit = $limit;

        return $this;
    }

    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * @return $this
     */
    public function setOffset(?int $offset): self
    {
        if ($offset !== null && $offset < 0) {
            throw new InvalidArgumentException('Offset must be >= 0.');
        }
        $this->offset = $offset;

        return $this;
    }

    /**
     * Returns FetchType information.
     */
    public function getFetchType(): ?FetchType
    {
        return $this->fetchType;
    }

    /**
     * Sets FetchType information.
     *
     * @return $this
     */
    public function setFetchType(?FetchType $fetchType): self
    {
        $this->fetchType = $fetchType;

        return $this;
    }

    /**
     * Checks Filterable attributes on public properties via reflection
     * and converts non-null values into condition arrays.
     *
     * Uses FilterReflector (reflection cache for performance optimization).
     *
     * @return array{columns:string[], operator:Operator, value:mixed, whereHas:bool}[]
     */
    protected function getLocalFilters(): array
    {
        return $this->getReflector()->getLocalFilters($this);
    }

    /**
     * Returns the FilterReflector instance (singleton pattern).
     */
    protected function getReflector(): FilterReflector
    {
        return self::$reflector ??= new FilterReflector;
    }

    /**
     * Returns the ValueWrapper instance (singleton pattern).
     */
    protected function getValueWrapper(): ValueWrapper
    {
        return self::$valueWrapper ??= new ValueWrapper;
    }

    /**
     * Returns the JsonColumnApplier instance (singleton pattern).
     */
    protected function getJsonApplier(): JsonColumnApplier
    {
        return self::$jsonApplier ??= new JsonColumnApplier(new JsonColumnParser);
    }

    /**
     * Returns the StandardFilterApplier instance (singleton pattern).
     */
    protected function getStandardApplier(): StandardFilterApplier
    {
        return self::$standardApplier ??= new StandardFilterApplier;
    }

    /**
     * Returns the RelationFilterApplier instance (singleton pattern).
     */
    protected function getRelationApplier(): RelationFilterApplier
    {
        return self::$relationApplier ??= new RelationFilterApplier;
    }

    /**
     * Returns the ColumnQualifier instance (singleton pattern).
     */
    protected function getQualifier(): ColumnQualifier
    {
        return self::$qualifier ??= new ColumnQualifier;
    }

    /**
     * Applies all filters, sorting, and fetch type operations to the Eloquent Builder.
     *
     * Operation order:
     * 1. ID filters (ids, excludedIds)
     * 2. Eager loading (withRelations, withCount)
     * 3. Column selection (columns)
     * 4. Local filters (Filterable attributes)
     * 5. Sorting (orderClause)
     * 6. Limit/Offset
     * 7. Fetch type (paginate, collect, pluck, etc.)
     *
     * @return mixed LengthAwarePaginator|\Illuminate\Support\Collection|Model|null
     *
     * @example
     * ```php
     * $filter = new CompanyManagementFilter();
     * $filter->setWithRelations(['company', 'branch']);
     * $filter->setWithCount(['unreadNotifications']);
     * $filter->setFetchType(FetchType::paginate());
     * $result = $filter->apply(UserCompany::query());
     * ```
     */
    public function apply(Builder $query): mixed
    {
        $query = $this->applyIdFilters($query);
        $query = $this->applyEagerLoad($query);
        $query = $this->applyColumns($query);
        $query = $this->applyLocalFilters($query);
        $query = $this->applyOrderClause($query);
        $query = $this->applyLimitAndOffset($query);

        return $this->applyFetchType($query);
    }

    private function applyIdFilters(Builder $query): Builder
    {
        $pk = $query->getModel()->getKeyName();

        if ($this->idsRestrictApplied) {
            $query = $query->whereIn($pk, $this->ids);
        }
        if (! empty($this->excludedIds)) {
            $query = $query->whereNotIn($pk, $this->excludedIds);
        }

        return $query;
    }

    /**
     * Applies eager loading operations.
     *
     * - withRelations: eager loads all relations with with()
     * - withCount: eager loads only relation counts with withCount()
     *
     * Using withCount prevents N+1 query problems and improves performance.
     * Example: the unreadNotifications relation creates a $model->unread_notifications_count attribute.
     */
    private function applyEagerLoad(Builder $query): Builder
    {
        // Eager-load relations (full relation data)
        if (! empty($this->withRelations)) {
            $query = $query->with($this->withRelations);
        }

        // Eager-load counts (counts only, not full relations)
        // Prevents N+1 query problems and improves performance.
        if (! empty($this->withCount)) {
            $query = $query->withCount($this->withCount);
        }

        foreach ($this->withAvg as [$relation, $column]) {
            $query = $query->withAvg($relation, $column);
        }

        return $query;
    }

    /**
     * Applies select when columns[] is filled. Adds the primary key automatically when missing.
     */
    private function applyColumns(Builder $query): Builder
    {
        if (empty($this->columns)) {
            return $query;
        }

        $model = $query->getModel();
        $table = $model->getTable();
        $primary = $model->getKeyName();

        $qualifier = $this->getQualifier();
        $cols = array_map(fn ($c) => $qualifier->qualifyIfBare(trim($c), $table), $this->columns);

        $hasPrimary = in_array("{$table}.{$primary}", $cols, true) || in_array($primary, $this->columns, true);
        if (! $hasPrimary) {
            $cols[] = "{$table}.{$primary}";
        }

        return $query->select($cols);
    }

    /**
     * Applies conditions from public properties and manually added filters
     * to the Eloquent query. This method handles different filtering scenarios
     * (JSON, relational, multi-relational, normal).
     *
     * Uses helper classes in a SOLID-friendly way.
     *
     * @param  Builder  $query  Eloquent query builder to operate on.
     * @return Builder Filtered query builder.
     */
    protected function applyLocalFilters(Builder $query): Builder
    {
        $model = $query->getModel();
        $table = $model->getTable();

        // Helper instances (singleton pattern)
        $reflector = $this->getReflector();
        $valueWrapper = $this->getValueWrapper();
        $jsonApplier = $this->getJsonApplier();
        $standardApplier = $this->getStandardApplier();
        $relationApplier = $this->getRelationApplier();
        $qualifier = $this->getQualifier();

        foreach ($reflector->getLocalFilters($this) as $f) {
            $cols = $f['columns'];
            $value = $f['value'];
            $isJson = $f['is_json'] ?? false;

            // JSON filter check (when JsonFilterable attribute exists)
            if ($isJson) {
                /** @var JsonOperator $jsonOperator */
                $jsonOperator = $f['json_operator'];
                $multiFieldSearchConfig = $f['multiFieldSearchConfig'] ?? null;

                foreach ($cols as $col) {
                    $query = $jsonApplier->apply($query, $col, $jsonOperator, $value, $table, $multiFieldSearchConfig);
                }

                continue;
            }

            // Standard Filterable processing logic
            /** @var Operator $op */
            $op = $f['operator'];
            $whereHas = $f['whereHas'] ?? false;

            // Morph type: when EQ, convert the value to MorphMapKey + class via whereIn.
            if (($f['morph'] ?? false) && $op === Operator::EQ) {
                $resolved = array_values(array_unique(array_filter([
                    RelationMorphType::keyForType($value),
                    $value,
                ])));
                if ($resolved !== []) {
                    $value = $resolved;
                    $op = Operator::IN;
                }
            }

            $wrapped = $valueWrapper->wrap($value, $op);

            // If whereHas is true, parse relation path and use whereHas
            if ($whereHas && count($cols) === 1 && is_string($cols[0]) && str_contains($cols[0], '.')) {
                $relationPath = $cols[0];
                $parts = explode('.', $relationPath);
                $column = array_pop($parts); // Last segment is the column name
                $relationChain = implode('.', $parts); // Relation chain (example: 'user' or 'userCompanies.branches')

                $query = $relationApplier->applyWhereHas($query, $relationChain, $column, $op, $value, $wrapped);

                continue;
            }

            // Multiple relation check: if all columns are relations
            $areAllRelational = count($cols) > 1 && $this->areAllRelational($cols);
            if ($areAllRelational) {
                $query = $relationApplier->applyMultiple($query, $cols, $op, $value, $wrapped);

                continue;
            }

            // Single relation check
            if (count($cols) === 1 && str_contains($cols[0], '.')) {
                [$relation, $column] = explode('.', $cols[0], 2);
                $query = $relationApplier->applySingle($query, $relation, $column, $op, $value, $wrapped);

                continue;
            }

            // Standard column filtering
            if (count($cols) > 1) {
                $query->where(function (Builder $q) use ($cols, $op, $wrapped, $value, $standardApplier, $qualifier, $table) {
                    foreach ($cols as $i => $col) {
                        $first = $i === 0;

                        if (str_contains($col, '.')) {
                            // Relation column: whereHas/orWhereHas
                            [$relation, $column] = explode('.', $col, 2);
                            $method = $first ? 'whereHas' : 'orWhereHas';

                            if ($op === Operator::IN) {
                                if (! is_array($value)) {
                                    throw new InvalidArgumentException(
                                        sprintf('[%s][%s] IN operator expects an array (column: %s)', __CLASS__, __FUNCTION__, $col)
                                    );
                                }
                                $q->{$method}($relation, fn (Builder $rq) => $rq->whereIn($column, $value));
                            } elseif ($op === Operator::NOT_IN) {
                                if (! is_array($value)) {
                                    throw new InvalidArgumentException(
                                        sprintf('[%s][%s] NOT_IN operator expects an array (column: %s)', __CLASS__, __FUNCTION__, $col)
                                    );
                                }
                                $q->{$method}($relation, fn (Builder $rq) => $rq->whereNotIn($column, $value));
                            } elseif (in_array($op, [Operator::STARTS_WITH, Operator::ENDS_WITH], true)) {
                                $q->{$method}($relation, fn (Builder $rq) => $rq->where($column, Operator::ILIKE->value, $wrapped));
                            } else {
                                $q->{$method}($relation, fn (Builder $rq) => $rq->where($column, $op->value, $wrapped));
                            }
                        } else {
                            // Plain column: where / orWhere (qualify with table name)
                            $qualifiedCol = $qualifier->qualifyIfBare($col, $table);
                            $standardApplier->apply($q, $qualifiedCol, $op, $value, $wrapped, $first);
                        }
                    }
                });
            } else {
                // Single column: qualify it with the table name.
                $col = $cols[0];
                $qualifiedCol = $qualifier->qualifyIfBare($col, $table);
                $query = $standardApplier->apply($query, $qualifiedCol, $op, $value, $wrapped, true);
            }
        }

        return $query;
    }

    /**
     * Checks whether all columns are relation columns.
     *
     * @param  array  $cols  Column array
     * @return bool True when all columns are relation columns
     */
    private function areAllRelational(array $cols): bool
    {
        foreach ($cols as $col) {
            if (! str_contains($col, '.')) {
                return false;
            }
        }

        return true;
    }

    private function applyOrderClause(Builder $query): Builder
    {
        if ($this->orderClause) {
            // Apply custom applier when provided
            if ($applier = $this->orderClause->getCustomApplier()) {
                $applier($query, $this->orderClause->getDirection());

                return $query;
            }

            $column = $this->orderClause->getColumn();
            $model = $query->getModel();
            $table = $model->getTable();

            // For relation columns, use the real related table name
            if (str_contains($column, '.')) {
                [$relation, $col] = explode('.', $column, 2);
                if ($model->isRelation($relation)) {
                    $relationInstance = $model->{$relation}();
                    $relatedTable = $relationInstance->getRelated()->getTable();
                    $column = "{$relatedTable}.{$col}";
                }
            } else {
                // For plain columns, qualify with table name
                $column = "{$table}.{$column}";
            }

            $query = $query->orderBy(
                $column,
                $this->orderClause->getDirection()
            );
        }

        return $query;
    }

    private function applyLimitAndOffset(Builder $query): Builder
    {
        // Do not apply offset/limit in PAGINATE mode; paginate manages them.
        if ($this->fetchType?->getType() === FetchTypeEnum::PAGINATE) {
            return $query;
        }

        // Apply offset with null check
        if ($this->offset !== null && $this->offset > 0) {
            $query->offset($this->offset);
        }

        // Apply limit with null check
        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return $query;
    }

    private function applyFetchType(Builder $query): mixed
    {
        if (! $this->fetchType) {
            throw new \LogicException(
                sprintf('[%s][%s] FetchType must be set before applying the query.', __CLASS__, __FUNCTION__)
            );
        }

        // Define action per enum value with match
        return match ($this->fetchType->getType()) {
            FetchTypeEnum::PAGINATE => $query
                ->paginate(
                    $this->fetchType->getPerPage(),
                    ['*'],
                    'page',
                    null
                )
                ->withPath($this->fetchType->getPaginationPath()),

            FetchTypeEnum::MODEL => $query->first(),

            FetchTypeEnum::PLUCK => $this->applyPluckWithJoins($query),

            FetchTypeEnum::COLLECT => $query->get(),

            FetchTypeEnum::INFINITE_SCROLL => $query
                ->offset($this->fetchType->getOffset() ?? 0)
                ->limit($this->fetchType->getLimit() ?? 15)
                ->get(),
        };
    }

    /**
     * Applies required joins for relation columns during pluck.
     */
    private function applyPluckWithJoins(Builder $query): mixed
    {
        $valueColumn = $this->fetchType->getColumnForValue();
        $keyColumn = $this->fetchType->getColumnForKey();
        $model = $query->getModel();
        $table = $model->getTable();

        // Join check for value column
        $actualValueColumn = $valueColumn;
        if (str_contains($valueColumn, '.')) {
            [$relation, $column] = explode('.', $valueColumn, 2);
            if ($model->isRelation($relation)) {
                $relationInstance = $model->{$relation}();
                $relatedTable = $relationInstance->getRelated()->getTable();
                $foreignKey = $relationInstance->getForeignKeyName();
                $ownerKey = $relationInstance->getOwnerKeyName();

                // Add join when it does not exist yet
                $joins = Collection::make($query->getQuery()->joins ?? [])->pluck('table')->toArray();
                if (! in_array($relatedTable, $joins)) {
                    $query->join($relatedTable, "{$table}.{$foreignKey}", '=', "{$relatedTable}.{$ownerKey}");
                }

                // Use the real table name in pluck
                $actualValueColumn = "{$relatedTable}.{$column}";
            }
        }

        // Join check for key column (if key column is also relational)
        $actualKeyColumn = $keyColumn;
        if ($keyColumn) {
            if (str_contains($keyColumn, '.')) {
                [$relation, $column] = explode('.', $keyColumn, 2);
                if ($model->isRelation($relation)) {
                    $relationInstance = $model->{$relation}();
                    $relatedTable = $relationInstance->getRelated()->getTable();
                    $foreignKey = $relationInstance->getForeignKeyName();
                    $ownerKey = $relationInstance->getOwnerKeyName();

                    // Add join when it does not exist yet
                    $joins = Collection::make($query->getQuery()->joins ?? [])->pluck('table')->toArray();
                    if (! in_array($relatedTable, $joins)) {
                        $query->join($relatedTable, "{$table}.{$foreignKey}", '=', "{$relatedTable}.{$ownerKey}");
                    }

                    // Use the real table name in pluck
                    $actualKeyColumn = "{$relatedTable}.{$column}";
                }
            } else {
                // If key column is plain (e.g. "id"), qualify with main table
                $actualKeyColumn = "{$table}.{$keyColumn}";
            }
        }

        return $query->pluck($actualValueColumn, $actualKeyColumn);
    }

    /**
     * Reads validated data from a FormRequest and assigns it dynamically
     * to the related public properties.
     *
     * Uses FilterFromRequestBuilder in a SOLID-friendly way.
     *
     * @param  FormRequest  $request  FormRequest
     * @return static Created concrete filter instance
     *
     * @example
     * // In your concrete filter class:
     * $filter = CategoryFilter::fromRequest($request);
     */
    public static function fromRequest(FormRequest $request): static
    {
        $filter = new static;
        (new FilterFromRequestBuilder)->build($request, $filter);

        return $filter;
    }

    /**
     * Converts non-null public properties from child classes to an array.
     * Serializes enums to their value and Carbon/DateTime instances to ISO8601 strings.
     *
     * @return array<string,mixed>
     */
    protected function getLocalPublicPropsAsArray(): array
    {
        $out = [];
        $ref = new \ReflectionObject($this);

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            $value = $this->{$name};

            if ($value === null) {
                continue;
            }

            // Enum -> value
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
            // Carbon/DateTime -> ISO8601
            elseif ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::ATOM); // ISO8601
            }
            // Collection/Arrayable -> array
            elseif ($value instanceof Arrayable) {
                $value = $value->toArray();
            }

            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * Child classes can return their own sortable map.
     * Default empty array.
     * Child classes fill it by overriding this method.
     *
     * **Note:** Although this method is protected static, FilterFromRequestBuilder
     * can call static::sortable() (within the same class hierarchy).
     *
     * @return array<string, string|\Closure> Sortable column map
     *                                        - Key: Sorting key (example: 'name', 'created_at')
     *                                        - Value: column name (string) or custom sorting closure
     */
    protected static function sortable(): array
    {
        return [];
    }

    /**
     * allowedSortKeys(): returns sorting keys allowed for validation.
     *
     * @return array<int, string>
     */
    public static function allowedSortKeys(): array
    {
        static $cache = [];

        return $cache[static::class] ??= array_keys(static::sortable());
    }

    /**
     * Whitelist of selectable columns for this filter.
     * Child classes fill it by overriding this method.
     * Relational columns are not allowed.
     *
     * **Note:** Although this method is protected static, FilterFromRequestBuilder
     * can call static::selectableColumns() (within the same class hierarchy).
     *
     * @return array<int, string> Selectable column names
     *
     * @example ['id','name','code']
     */
    public static function selectableColumns(): array
    {
        return [];
    }

    /**
     * Converts the filter state to an array (Arrayable interface).
     *
     * @return array{
     *     ids: string[],
     *     columns: string[],
     *     excludedIds: int[],
     *     withRelations: string[],
     *     withCount: string[],
     *     locals: array<string,mixed>,
     *     orderClause: array<string,mixed>,
     *     fetchType: array<string,mixed>,
     *     limit: int|null
     * }
     */
    public function toArray(): array
    {
        return [
            'ids' => $this->ids,
            'columns' => $this->columns,
            'excludedIds' => $this->excludedIds,
            'withRelations' => $this->withRelations,
            'withCount' => $this->withCount,
            'withAvg' => $this->withAvg,
            // Child class public properties (example: ProductSupplierFilter)
            'locals' => $this->getLocalPublicPropsAsArray(),
            // Optional resolved form of local filters with attributes
            // 'resolved_local_filters' => $this->getLocalFilters(),
            'orderClause' => $this->orderClause?->toArray() ?? [],
            'fetchType' => $this->fetchType?->toArray() ?? [],
            'limit' => $this->limit,
        ];
    }

    /**
     * Returns the toArray() output for JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
