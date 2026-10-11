<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use Illuminate\Support\Facades\DB;

class MyPayouts extends Report
{
    public function key(): string
    {
        return 'my-payouts';
    }

    public function title(): string
    {
        return 'My payouts';
    }

    public function description(): string
    {
        return 'Payout requests made in the period, their outcome and transfer reference.';
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
            new Column('requested_at', 'Requested', 'datetime'),
            new Column('amount_minor', 'Amount', 'money'),
            new Column('status', 'Status'),
            new Column('destination', 'Paid to'),
            new Column('paid_at', 'Sent', 'datetime'),
            new Column('payment_reference', 'Transfer reference'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $query = DB::table('payouts')->where('affiliate_profile_id', $filters->affiliateId)->whereBetween('requested_at', $period->utc());

        foreach ($query->lazyById(500) as $p) {
            yield [
                'requested_at' => $p->requested_at,
                'amount_minor' => (int) $p->amount_minor,
                'status' => $p->status,
                'destination' => $p->destination_masked,
                'paid_at' => $p->paid_at,
                'payment_reference' => $p->payment_reference,
            ];
        }
    }
}
