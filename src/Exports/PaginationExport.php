<?php

namespace Cheezytony\Pagination\Exports;

use Closure;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PaginationExport implements FromCollection, WithHeadings, ShouldAutoSize, WithMapping
{
    public function __construct(protected Collection $data, protected array $headings, protected Closure $mapping)
    {
    }

    /**
     * @return Collection
     */
    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function map($row): array
    {
        return call_user_func_array($this->mapping, [$row]);
    }
}
