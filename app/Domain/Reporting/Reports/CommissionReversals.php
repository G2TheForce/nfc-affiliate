<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\LedgerEntryType;
use App\Enums\Permission;
use Illuminate\Support\Facades\DB;

class CommissionReversals extends Report
{
    public function key(): string
    {
        return 'commission-reversals';
    }

    public function title(): string
    {
        return 'Commission reversals';
    }

    public function description(): string
    {
        return 'Commission taken back in the period by refunds or staff voids, with the refund reference.';
    }

    public function permission(): Permission
    {
        return Permission::CommissionsView;
    }

    public function columns(): array
    {
        return [
            new Column('created_at', 'Reversed', 'datetime'),
            new Column('reason', 'Reason'),
            new Column('affiliate', 'Affiliate'),
            new Column('commission_id', 'Commission ID'),
            new Column('order_number', 'Order'),
            new Column('refund_reference', 'Refund reference'),
            new Column('amount_minor', 'Amount', 'money'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $query = DB::table('commission_ledger_entries as e')
            ->join('affiliate_profiles', 'affiliate_profiles.id', '=', 'e.affiliate_profile_id')
            ->leftJoin('commissions', 'commissions.id', '=', 'e.commission_id')
            ->leftJoin('orders', 'orders.id', '=', 'commissions.order_id')
            ->leftJoin('refunds', 'refunds.id', '=', 'e.refund_id')
            ->whereIn('e.type', [LedgerEntryType::RefundReversal->value, LedgerEntryType::Void->value])
            ->whereBetween('e.created_at', $period->utc())
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('e.affiliate_profile_id', $id))
            ->select('e.id', 'e.created_at', 'e.type', 'e.amount_minor', 'affiliate_profiles.display_name', 'commissions.public_id as commission_id', 'orders.order_number', 'refunds.reference');

        foreach ($query->lazyById(500, 'e.id', 'id') as $e) {
            yield [
                'created_at' => $e->created_at,
                'reason' => LedgerEntryType::from($e->type)->label(),
                'affiliate' => $e->display_name,
                'commission_id' => $e->commission_id,
                'order_number' => $e->order_number,
                'refund_reference' => $e->reference,
                'amount_minor' => (int) $e->amount_minor,
            ];
        }
    }
}
