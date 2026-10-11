<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AffiliateRegistrations extends Report
{
    public function key(): string
    {
        return 'affiliate-registrations';
    }

    public function title(): string
    {
        return 'Affiliate registrations';
    }

    public function description(): string
    {
        return 'Applications made in the period, their current status and how long approval took.';
    }

    public function permission(): Permission
    {
        return Permission::AffiliatesView;
    }

    public function filters(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new Column('applied_at', 'Applied', 'datetime'),
            new Column('display_name', 'Affiliate'),
            new Column('referral_code', 'Referral code'),
            new Column('status', 'Current status'),
            new Column('approved_at', 'Approved', 'datetime'),
            new Column('days_to_approval', 'Days to approval', 'int'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        foreach (DB::table('affiliate_profiles')->whereBetween('applied_at', $period->utc())->orderBy('id')->lazyById(500) as $p) {
            yield [
                'applied_at' => $p->applied_at,
                'display_name' => $p->display_name,
                'referral_code' => $p->referral_code,
                'status' => $p->status,
                'approved_at' => $p->approved_at,
                'days_to_approval' => $p->approved_at ? (int) CarbonImmutable::parse($p->applied_at)->diffInDays(CarbonImmutable::parse($p->approved_at)) : null,
            ];
        }
    }
}
