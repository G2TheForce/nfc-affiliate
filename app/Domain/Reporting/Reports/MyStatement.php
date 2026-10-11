<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\LedgerEntryType;
use Illuminate\Support\Facades\DB;

/** Every ledger movement on the affiliate's balance in the period. */
class MyStatement extends Report
{
    private const BUCKETS = ['pending' => 'Pending', 'approved' => 'Approved', 'available' => 'Available', 'reserved' => 'In payout request', 'paid' => 'Paid', 'reversed' => 'Reversed'];

    public function key(): string
    {
        return 'my-statement';
    }

    public function title(): string
    {
        return 'My statement';
    }

    public function description(): string
    {
        return 'Every movement of your commission balance in the period.';
    }

    public function permission(): null
    {
        return null;
    }

    public function filters(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new Column('created_at', 'Date', 'datetime'),
            new Column('description', 'Description'),
            new Column('from', 'From'),
            new Column('to', 'To'),
            new Column('amount_minor', 'Amount', 'money'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $query = DB::table('commission_ledger_entries')->where('affiliate_profile_id', $filters->affiliateId)->whereBetween('created_at', $period->utc());

        foreach ($query->lazyById(500) as $e) {
            yield [
                'created_at' => $e->created_at,
                'description' => LedgerEntryType::from($e->type)->label(),
                'from' => $e->from_bucket ? self::BUCKETS[$e->from_bucket] : 'Earned',
                'to' => $e->to_bucket ? self::BUCKETS[$e->to_bucket] : 'Deducted',
                'amount_minor' => (int) $e->amount_minor,
            ];
        }
    }
}
