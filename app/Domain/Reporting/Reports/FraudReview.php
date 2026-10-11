<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\Permission;
use Illuminate\Support\Facades\DB;

/**
 * Fraud-review outcomes: attributions blocked at checkout (self-referral,
 * ineligible affiliate) and commission holds, releases and voids.
 */
class FraudReview extends Report
{
    private const ACTIONS = [
        'commission.held' => 'Commission put on hold',
        'commission.hold_released' => 'Hold released',
        'commission.voided' => 'Commission voided',
    ];

    public function key(): string
    {
        return 'fraud-review';
    }

    public function title(): string
    {
        return 'Fraud review outcomes';
    }

    public function description(): string
    {
        return 'Attributions blocked at checkout, and commissions held, released or voided by staff in the period.';
    }

    public function permission(): Permission
    {
        return Permission::FraudReview;
    }

    public function columns(): array
    {
        return [
            new Column('occurred_at', 'When', 'datetime'),
            new Column('event', 'Outcome'),
            new Column('affiliate', 'Affiliate'),
            new Column('reference', 'Order or commission'),
            new Column('reason', 'Reason'),
            new Column('actor', 'By'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        if (! $filters->affiliateId) {
            $blocked = DB::table('orders')->where('attribution_status', 'blocked')->whereBetween('placed_at', $period->utc())
                ->select('id', 'placed_at', 'order_number', 'attribution_block_reason');
            foreach ($blocked->lazyById(500) as $o) {
                yield [
                    'occurred_at' => $o->placed_at,
                    'event' => 'Attribution blocked at checkout',
                    'affiliate' => null,
                    'reference' => $o->order_number,
                    'reason' => $o->attribution_block_reason,
                    'actor' => 'System',
                ];
            }
        }

        $logs = DB::table('audit_logs')
            ->join('commissions', 'commissions.public_id', '=', 'audit_logs.subject_id')
            ->join('affiliate_profiles', 'affiliate_profiles.id', '=', 'commissions.affiliate_profile_id')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->where('audit_logs.subject_type', 'Commission')
            ->whereIn('audit_logs.action', array_keys(self::ACTIONS))
            ->whereBetween('audit_logs.created_at', $period->utc())
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('commissions.affiliate_profile_id', $id))
            ->select('audit_logs.id', 'audit_logs.created_at', 'audit_logs.action', 'audit_logs.properties', 'commissions.public_id', 'affiliate_profiles.display_name', 'users.name');

        foreach ($logs->lazyById(500, 'audit_logs.id', 'id') as $log) {
            $props = json_decode((string) $log->properties, true) ?: [];
            yield [
                'occurred_at' => $log->created_at,
                'event' => self::ACTIONS[$log->action],
                'affiliate' => $log->display_name,
                'reference' => $log->public_id,
                'reason' => $props['reason'] ?? $props['note'] ?? null,
                'actor' => $log->name,
            ];
        }
    }
}
