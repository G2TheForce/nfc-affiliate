<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\PayoutStatus;
use App\Enums\Permission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payouts requested in the period, or (outstanding) every open request now.
 * Destinations are masked; full account details never leave the payout screen.
 */
class Payouts extends Report
{
    public function __construct(private readonly bool $outstanding = false) {}

    public function key(): string
    {
        return $this->outstanding ? 'outstanding-payouts' : 'payouts';
    }

    public function title(): string
    {
        return $this->outstanding ? 'Outstanding payout requests' : 'Payouts';
    }

    public function description(): string
    {
        return $this->outstanding
            ? 'Payout requests waiting for review or for the transfer, oldest first.'
            : 'Payouts requested in the period with their outcome and transfer reference. Paid rows are the paid commissions.';
    }

    public function permission(): Permission
    {
        return Permission::PayoutsView;
    }

    public function usesPeriod(): bool
    {
        return ! $this->outstanding;
    }

    public function columns(): array
    {
        return [
            new Column('requested_at', 'Requested', 'datetime'),
            new Column('payout_id', 'Payout ID'),
            new Column('affiliate', 'Affiliate'),
            new Column('amount_minor', 'Amount', 'money'),
            new Column('status', 'Status'),
            new Column('method', 'Method'),
            new Column('destination', 'Destination (masked)'),
            new Column('reviewed_at', 'Reviewed', 'datetime'),
            new Column('paid_at', 'Paid', 'datetime'),
            new Column('payment_reference', 'Transfer reference'),
            new Column('reason', 'Rejection or failure reason'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $query = DB::table('payouts')
            ->join('affiliate_profiles', 'affiliate_profiles.id', '=', 'payouts.affiliate_profile_id')
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('payouts.affiliate_profile_id', $id))
            ->when(
                $this->outstanding,
                fn (Builder $q) => $q->whereIn('payouts.status', [PayoutStatus::Requested->value, PayoutStatus::Approved->value]),
                fn (Builder $q) => $q->whereBetween('payouts.requested_at', $period->utc()),
            )
            ->select('payouts.*', 'affiliate_profiles.display_name');

        foreach ($query->lazyById(500, 'payouts.id', 'id') as $p) {
            yield [
                'requested_at' => $p->requested_at,
                'payout_id' => $p->public_id,
                'affiliate' => $p->display_name,
                'amount_minor' => (int) $p->amount_minor,
                'status' => $p->status,
                'method' => $p->method_type,
                'destination' => $p->destination_masked,
                'reviewed_at' => $p->reviewed_at,
                'paid_at' => $p->paid_at,
                'payment_reference' => $p->payment_reference,
                'reason' => $p->failure_reason ?? ($p->status === PayoutStatus::Rejected->value ? $p->review_note : null),
            ];
        }
    }
}
