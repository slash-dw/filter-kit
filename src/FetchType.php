<?php

declare(strict_types=1);

namespace SlashDw\FilterKit;

use Illuminate\Contracts\Support\Arrayable;
use SlashDw\FilterKit\Enum\FetchTypeEnum;

/**
 * FetchType
 *
 * Represents the fetch type used by database queries.
 * Paginate, Model (get), Pluck, or Collect modes can be created with static factories.
 *
 *
 * @example
 * // Pagination mode:
 * $fetch = FetchType::paginate('/items', 20);
 * // Model mode (first record):
 * $fetch = FetchType::model();
 */
class FetchType implements \JsonSerializable, Arrayable
{
    /**
     * @var FetchTypeEnum Enum value for the fetch type
     */
    private FetchTypeEnum $type;

    /**
     * @var string|null Pagination link path (paginate mode only)
     *
     * @example '/items?page=2'
     */
    private ?string $paginationPath = null;

    /**
     * @var int|null Number of records per page (paginate mode)
     */
    private ?int $perPage = null;

    /**
     * @var string|null Value column used in pluck mode
     */
    private ?string $columnForValue = null;

    /**
     * @var string|null Column used as key in pluck mode
     */
    private ?string $columnForKey = null;

    /**
     * @var int|null Offset value in infinite scroll mode
     */
    private ?int $offset = null;

    /**
     * @var int|null Limit value in infinite scroll mode
     */
    private ?int $limit = null;

    /**
     * Private constructor: do not instantiate directly from outside; use factories instead.
     */
    private function __construct(FetchTypeEnum $type)
    {
        $this->type = $type;
    }

    /**
     * Creates FetchType for paginate mode.
     *
     * @param  string  $paginationPath  Pagination URL path
     * @param  int  $perPage  Number of records per page
     */
    public static function paginate(string $paginationPath, int $perPage = 30): self
    {
        $obj = new self(FetchTypeEnum::PAGINATE);
        $obj->paginationPath = $paginationPath;
        $obj->perPage = $perPage;

        return $obj;
    }

    /**
     * Creates FetchType for model (first) mode.
     */
    public static function model(): self
    {
        return new self(FetchTypeEnum::MODEL);
    }

    /**
     * Creates FetchType for collect mode.
     */
    public static function collect(): self
    {
        return new self(FetchTypeEnum::COLLECT);
    }

    /**
     * Creates FetchType for pluck mode.
     *
     * @param  string  $columnForValue  Value column
     * @param  string|null  $columnForKey  Optional key column
     */
    public static function pluck(string $columnForValue, ?string $columnForKey = null): self
    {
        $obj = new self(FetchTypeEnum::PLUCK);
        $obj->columnForValue = $columnForValue;
        $obj->columnForKey = $columnForKey;

        return $obj;
    }

    /**
     * Creates FetchType for infinite scroll mode.
     *
     * @param  int  $offset  Starting offset value
     * @param  int  $limit  Record count limit
     */
    public static function infiniteScroll(int $offset = 0, int $limit = 15): self
    {
        $obj = new self(FetchTypeEnum::INFINITE_SCROLL);
        $obj->offset = $offset;
        $obj->limit = $limit;

        return $obj;
    }

    /**
     * Returns the represented fetch type.
     */
    public function getType(): FetchTypeEnum
    {
        return $this->type;
    }

    /**
     * Returns path information for pagination mode.
     */
    public function getPaginationPath(): ?string
    {
        return $this->paginationPath;
    }

    /**
     * Returns per-page count information for pagination mode.
     */
    public function getPerPage(): ?int
    {
        return $this->perPage;
    }

    /**
     * Returns the value column name for pluck mode.
     */
    public function getColumnForValue(): ?string
    {
        return $this->columnForValue;
    }

    /**
     * Returns the key column name for pluck mode.
     */
    public function getColumnForKey(): ?string
    {
        return $this->columnForKey;
    }

    /**
     * Checks whether this FetchType instance is in paginate mode.
     */
    public function isPaginate(): bool
    {
        return $this->type === FetchTypeEnum::PAGINATE;
    }

    /**
     * Checks whether this instance is in model (get) mode.
     */
    public function isModel(): bool
    {
        return $this->type === FetchTypeEnum::MODEL;
    }

    /**
     * Checks whether this instance is in collect mode.
     */
    public function isCollect(): bool
    {
        return $this->type === FetchTypeEnum::COLLECT;
    }

    /**
     * Checks whether this instance is in pluck mode.
     */
    public function isPluck(): bool
    {
        return $this->type === FetchTypeEnum::PLUCK;
    }

    /**
     * Checks whether this instance is in infinite scroll mode.
     */
    public function isInfiniteScroll(): bool
    {
        return $this->type === FetchTypeEnum::INFINITE_SCROLL;
    }

    /**
     * Returns the offset value for infinite scroll mode.
     */
    public function getOffset(): ?int
    {
        return $this->offset;
    }

    /**
     * Returns the limit value for infinite scroll mode.
     */
    public function getLimit(): ?int
    {
        return $this->limit;
    }

    /**
     * Returns array format.
     *
     * @return array{
     *   type:string,
     *   paginationPath?:string,
     *   perPage?:int,
     *   columnForValue?:string,
     *   columnForKey?:string,
     *   offset?:int,
     *   limit?:int,
     * }
     */
    public function toArray(): array
    {
        $data = ['type' => $this->type->name];

        if ($this->isPaginate()) {
            $data['paginationPath'] = $this->paginationPath;
            $data['perPage'] = $this->perPage;
        }

        if ($this->isPluck()) {
            $data['columnForValue'] = $this->columnForValue;
            $data['columnForKey'] = $this->columnForKey;
        }

        if ($this->isInfiniteScroll()) {
            $data['offset'] = $this->offset;
            $data['limit'] = $this->limit;
        }

        return $data;
    }

    /**
     * Returns the same output for JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
