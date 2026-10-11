<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\LedgerEntryType;
use App\Enums\Permission;
use Illuminate\Support\Facades\DB;

class Refunds extends Report
{
    public function key(): string
    {
        return 'refunds';
    }

    public function title(): string
    {
        return 'Refunds';
    }

    public function description(): string
    {
        return 'Refunds completed in the period and the commission each one reversed. Chargebacks arrive with payment provider integration.';
    }

    public function permission(): Permission
    {
        return Permission::CommissionsView;
    }

    public function columns(): array
    {
        return [
            new Column('completed_at', 'Refunded', 'datetime'),
            new Column('reference', 'Refund reference'),
            new Column('order_number', 'Order'),
            new Column('amount_minor', 'Amount', 'money'),
            new Column('shipping_minor', 'Of which delivery', 'money'),
            new Column('reason', 'Reason'),
            new Column('affiliate', 'Attributed affiliate'),
            new Column('commission_reversed_minor', 'Commission reversed', 'money'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $reversed = DB::table('commission_ledger_entries')
            ->where('type', LedgerEntryType::RefundReversal->value)
            ->selectRaw('refund_id, SUM(amount_minor) as total')
            ->groupBy('refund_id');

        $query = DB::table('refunds')
            ->join('orders', 'orders.id', '=', 'refunds.order_id')
            ->leftJoin('order_attributions', 'order_attributions.order_id', '=', 'refunds.order_id')
            ->leftJoin('affiliate_profiles', 'affiliate_profiles.id', '=', 'order_attributions.affiliate_profile_id')
            ->leftJoinSub($reversed, 'r', 'r.refund_id', '=', 'refunds.id')
            ->whereBetween('refunds.completed_at', $period->utc())
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('order_attributions.affiliate_profile_id', $id))
            ->select('refunds.id', 'refunds.completed_at', 'refunds.reference', 'orders.order_number', 'refunds.amount_minor', 'refunds.shipping_minor', 'refunds.reason',
                'affiliate_profiles.display_name', 'r.total as reversed');

        foreach ($query->lazyById(500, 'refunds.id', 'id') as $r) {
            yield [
                'completed_at' => $r->completed_at,
                'reference' => $r->reference,
                'order_number' => $r->order_number,
                'amount_minor' => (int) $r->amount_minor,
                'shipping_minor' => (int) $r->shipping_minor,
                'reason' => $r->reason,
                'affiliate' => $r->display_name,
                'commission_reversed_minor' => (int) ($r->reversed ?? 0),
            ];
        }
    }
}
