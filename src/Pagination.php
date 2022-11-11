<?php

namespace Cheezytony\Pagination;

use Cheezytony\Pagination\Exports\PaginationExport;
use Cheezytony\Pagination\Http\Resources\GenericResource;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JetBrains\PhpStorm\ArrayShape;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class Pagination
{
    /**
     * Default cache lifespan.
     *
     * @var int
     */
    protected const CACHE_DURATION = 31536000; // One year

    /**
     * Allowed methods for exporting data.
     *
     * @var array<string, string>
     */
    protected const EXPORT_METHOD = [
        'ALL' => 'all',
        'FILTERED' => 'filtered',
    ];

    /**
     * Default items per page.
     *
     * @var int
     */
    protected const LIMIT = 15;

    /**
     * Default sort order.
     *
     * @var string
     */
    protected const ORDER = 'asc';

    /**
     * Default sort column.
     */
    protected const ORDER_BY = 'created_at';

    /**
     * The collection of data fetched from the database.
     *
     * @var Collection
     */
    protected Collection $data;

    /**
     * Initialize pagination.
     *
     * @param EloquentBuilder|QueryBuilder|Relation $query
     * @param array $config
     * @param class-string<GenericResource> $resource
     */
    public function __construct(
        protected EloquentBuilder|QueryBuilder|Relation $query,
        protected array                                 $config = [],
        protected string                                $resource = GenericResource::class
    ) {
    }

    /**
     * Initialize pagination or export.
     *
     * @return Response|array
     */
    public function process(): Response|array
    {
        // Export or paginate.
        return request()->has('export') ? $this->export() : $this->paginate();
    }

    #[ArrayShape(['data' => 'mixed', 'meta' => 'array'])]
    protected function paginate(): array
    {
        $this->data = Cache::tags(
            $this->getCacheTags()
        )->remember(
            $this->getCacheKey(),
            $this->getCacheDuration(),
            function () {
                return $this
                    ->applySearch()
                    ->applyFilterByColumn()
                    ->applyFilters()
                    ->applyRange()
                    ->applySorting()
                    ->applyLimit()
                    ->getData();
            }
        );

        return [
            'data' => $this->resource::collection($this->data),
            'meta' => $this->getMeta()
        ];
    }

    protected function export(): BinaryFileResponse
    {
        $this->data = Cache::tags($this->getCacheTags())
            ->remember(
                $this->getCacheKey(),
                $this->getCacheDuration(),
                function () {
                    if (static::EXPORT_METHOD['filtered'] === $this->getExportType()) {
                        $this
                            ->applySearch()
                            ->applyFilterByColumn()
                            ->applyFilters()
                            ->applyRange()
                            ->applySorting();
                    }

                    return $this->getData();
                }
            );

        return Excel::download(new PaginationExport(
            $this->data,
            $this->config['export']['headings']
            ?? $this->data->count() ? array_keys($this->data[0]->toArray()) : [],
            $this->config['export']['headings'] ?? function ($data) {
                return array_values($data->toArray());
            },
        ), $this->getExportFilename());
    }


    protected function applySearch(): Pagination
    {
        $searchQuery = $this->getSearchQuery();
        if (!is_string($searchQuery)) {
            return $this;
        }

        $searchColumns = $this->getSearchColumns();
        if (is_callable($searchColumns)) {
            $searchColumns($this->query, $searchQuery);
            return $this;
        }

        foreach ($searchColumns as $index => $column) {
            $this->addSearchIndex($column, $index === 0);
        }
        return $this;
    }

    protected function applyFilterByColumn(): Pagination
    {
        foreach ($this->getFilterColumns() as $column) {
            if (request()->has($column)) {
                $this->query->where($column, request()->query($column));
            }
        }
        return $this;
    }

    protected function applyFilters(): Pagination
    {
        if ($filter = $this->getFilter()) {
            $filter($this->query);
        }
        return $this;
    }

    protected function applyRange(): Pagination
    {
        if (!$rangeColumn = $this->getRangeColumn()) {
            return $this;
        }

        if ($rangeStart = $this->getRangeStart()) {
            $this->query->where($rangeColumn, '>=', $rangeStart);
        }

        if ($rangeEnd = $this->getRangeEnd()) {
            $this->query->where($rangeColumn, '<=', $rangeEnd);
        }

        return $this;
    }

    protected function applySorting(): Pagination
    {
        $this->query->reorder($this->getOrderBy(), $this->getOrder());
        return $this;
    }

    protected function applyLimit(): Pagination
    {
        $this->query
            ->offset($this->getOffsetStart())
            ->limit($this->getOffsetEnd());
        return $this;
    }


    protected function getRangeColumn(): ?string
    {
        return request()->query('range');
    }

    protected function getRangeStart(): ?string
    {
        return request()->query('range_start');
    }

    protected function getRangeEnd(): ?string
    {
        return request()->query('range_end');
    }

    protected function getOrderBy(): string
    {
        return request()->query('order_by') ?? self::ORDER_BY;
    }

    protected function getOrder(): string
    {
        $order = request()->query('order');
        return in_array($order, ['asc', 'desc']) ? $order : self::ORDER;
    }

    protected function getFilter(): ?Closure
    {
        $filter = request()->query('filter');
        return $filter ? $this->getFilterByName($filter) : null;
    }

    protected function getFilterByName(string $filterName): ?Closure
    {
        $filters = $this->config['filters'] ?? [];
        return in_array($filterName, $filters) ? $filters[$filterName] : null;
    }

    protected function getFilterColumns(): array
    {
        return $this->config['filter_columns'] ?? [];
    }

    protected function getSearchQuery(): ?string
    {
        return request()->query('search');
    }

    protected function getSearchColumns(): array
    {
        return $this->config['search_columns'] ?? [];
    }

    protected function addSearchIndex(string $column, bool $isFirstIndex = false): Pagination
    {
        $searchQuery = $this->getSearchQuery();
        $whereMethod = $isFirstIndex ? 'where' : 'orWhere';
        $this->query->$whereMethod(
            $column,
            'like',
            "%$searchQuery%",
        );
        return $this;
    }

    protected function getData(): Collection|array
    {
        return $this->query->get();
    }

    protected function getExportFilename(): string
    {
        return 'export-' . now() . '.xlsx';
    }

    protected function getOffsetStart(): int
    {
        return ($this->getPage() - 1) * $this->getPageLimit();
    }

    protected function getPage(): int
    {
        $page = request()->query('page');
        return (int) (is_string($page) ? $page : 1);
    }

    protected function getPageLimit(): int
    {
        $pageLimit = request()->query('limit');
        return (int)is_string($pageLimit) ? $pageLimit : self::LIMIT;
    }

    protected function getExportType(): string|null
    {
        return request()->query('export');
    }

    protected function getOffsetEnd(): int
    {
        return $this->getPage() * $this->getPageLimit();
    }

    #[ArrayShape([
        'total' => 'int',
        'per_page' => 'int',
        'current_page' => 'int',
        'last_page' => 'float',
        'from' => 'int',
        'to' => 'int',
        'order' => 'string',
        'order_by' => 'string'
    ])]
    protected function getMeta(): array
    {
        return [
            'total' => $this->data->count(),
            'per_page' => $this->getPageLimit(),
            'current_page' => $this->getPage(),
            'last_page' => $this->getPages(),
            'from' => $this->getOffsetStart() + ($this->data->count() ? 1 : 0),
            'to' => $this->getOffsetStart() + $this->data->count(),
            'order' => $this->getOrder(),
            'order_by' => $this->getOrderBy(),
        ];
    }

    protected function getPages(): float
    {
        return ceil($this->data->count() / $this->getPageLimit());
    }

    protected function getCacheDuration(): int
    {
        return $this->config['cacheDuration'] ?? static::CACHE_DURATION;
    }

    protected function getCacheKey(): string
    {
        $tableName = $this->query->getModel()->getTable();
        $columnFilters = array_map(function ($column) {
            return $column . '=' . request()->query($column);
        }, $this->getFilterColumns());
        $keys = [
            "table=$tableName",
            "page={$this->getPage()}",
            'query=' . Str::snake($this->getSearchQuery()),
            "filter={$this->getFilter()}",
            "range={$this->getRangeColumn()}:{$this->getRangeStart()}-{$this->getRangeEnd()}",
            "order-by={$this->getOrderBy()}",
            "order={$this->getOrder()}",
            "limit={$this->getPageLimit()}",
            "export={$this->getExportType()}",
            'column-filters=' . implode(',', $columnFilters)
        ];
        return implode('&', $keys);
    }

    protected function getCacheTags(): array
    {
        $tableName = $this->query->getModel()->getTable();
        return $this->config['cacheTags'] ?? [$tableName];
    }
}
