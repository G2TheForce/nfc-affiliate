<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Column;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Period;
use App\Domain\Reporting\Report;
use App\Enums\LedgerBucket;
use App\Enums\Permission;
use Illuminate\Support\Facades\DB;

/**
 * Current balances per affiliate, straight from the ledger. The totals of
 * this report equal the programme balances on the finance dashboard.
 */
class PayoutLiability extends Report
{
    public function key(): string
    {
        return 'payout-liability';
    }

    public function title(): string
    {
        return 'Balances owed (payout liability)';
    }

    public function description(): string
    {
        return 'What each affiliate is owed right now, by stage: pending, approved, available, in a payout request, and paid to date.';
    }

    public function permission(): Permission
    {
        return Permission::CommissionsView;
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            new Column('display_name', 'Affiliate'),
            new Column('referral_code', 'Referral code'),
            new Column('status', 'Status'),
            new Column('pending', 'Pending', 'money'),
            new Column('approved', 'Approved', 'money'),
            new Column('available', 'Available', 'money'),
            new Column('reserved', 'In payout request', 'money'),
            new Column('paid', 'Paid to date', 'money'),
            new Column('reversed', 'Reversed to date', 'money'),
        ];
    }

    public function rows(Period $period, Filters $filters): iterable
    {
        $select = ['affiliate_profile_id'];
        foreach (LedgerBucket::cases() as $b) {
            $select[] = "SUM(CASE WHEN to_bucket = '{$b->value}' THEN amount_minor ELSE 0 END) - SUM(CASE WHEN from_bucket = '{$b->value}' THEN amount_minor ELSE 0 END) as {$b->value}";
        }
        $balances = DB::table('commission_ledger_entries')
            ->when($filters->affiliateId, fn ($q, $id) => $q->where('affiliate_profile_id', $id))
            ->selectRaw(implode(', ', $select))
            ->groupBy('affiliate_profile_id');

        $rows = DB::query()->fromSub($balances, 'b')
            ->join('affiliate_profiles', 'affiliate_profiles.id', '=', 'b.affiliate_profile_id')
            ->orderBy('affiliate_profiles.display_name')
            ->get();

        foreach ($rows as $r) {
            yield [
                'display_name' => $r->display_name,
                'referral_code' => $r->referral_code,
                'status' => $r->status,
            ] + collect(LedgerBucket::cases())->mapWithKeys(fn ($b) => [$b->value => (int) $r->{$b->value}])->all();
        }
    }
}
