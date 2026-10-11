<?php

namespace App\Domain\Reporting;

use App\Enums\Permission;

/**
 * A report: columns and a row stream. Rows are generated lazily (chunked
 * queries or bounded aggregates), so exports never hold a whole table.
 */
abstract class Report
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** Permission needed to view (staff) — null for affiliate reports. */
    abstract public function permission(): ?Permission;

    /**
     * @return list<Column>
     */
    abstract public function columns(): array;

    /**
     * @return iterable<array<string, mixed>>
     */
    abstract public function rows(Period $period, Filters $filters): iterable;

    /** False for point-in-time reports such as current balances. */
    public function usesPeriod(): bool
    {
        return true;
    }

    /** Which staff filters apply. */
    public function filters(): array
    {
        return ['affiliate_id'];
    }

    /** Exports with customer or account data are flagged in the audit log. */
    public function sensitive(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'key' => $this->key(),
            'title' => $this->title(),
            'description' => $this->description(),
            'uses_period' => $this->usesPeriod(),
            'filters' => $this->filters(),
            'columns' => array_map(fn (Column $c) => $c->toArray(), $this->columns()),
        ];
    }
}
