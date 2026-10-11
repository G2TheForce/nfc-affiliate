<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commissions\CommissionLedger;
use App\Domain\Reporting\Filters;
use App\Domain\Reporting\Metrics;
use App\Enums\AffiliateStatus;
use App\Enums\CommissionStatus;
use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Affiliate\DashboardController as AffiliateDashboard;
use App\Http\Controllers\Controller;
use App\Models\AffiliateProfile;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function __construct(private readonly Metrics $metrics, private readonly CommissionLedger $ledger) {}

    /** Operational overview for the owner and staff with reports.view. */
    public function admin(Request $request): array
    {
        $period = AffiliateDashboard::period($request);
        $filters = self::filters($request);
        $user = $request->user();

        $statusCounts = AffiliateProfile::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $count = fn (AffiliateStatus $s) => (int) ($statusCounts[$s->value] ?? 0);

        $data = [
            'period' => $period->describe(),
            'filters' => $request->only(['affiliate_id', 'product_id', 'coupon_id']),
            'currency' => config('affiliate.currency.default'),
            'affiliates' => [
                'total' => $count(AffiliateStatus::Approved) + $count(AffiliateStatus::Suspended) + $count(AffiliateStatus::Deactivated),
                'pending_applications' => $count(AffiliateStatus::Pending),
                'active' => $count(AffiliateStatus::Approved),
                'suspended' => $count(AffiliateStatus::Suspended),
                'deactivated' => $count(AffiliateStatus::Deactivated),
                'applied_in_period' => AffiliateProfile::query()->whereBetween('applied_at', $period->utc())->count(),
                'approved_in_period' => AffiliateProfile::query()->whereBetween('approved_at', $period->utc())->count(),
            ],
            'totals' => $this->metrics->referralTotals($period, $filters),
            'series' => $this->metrics->series($period, $filters),
            'top_affiliates' => $this->metrics->affiliates($period, $filters),
            'top_products' => $this->metrics->products($period, $filters),
            'campaigns' => $this->metrics->campaigns($period, $filters),
            'definitions' => Metrics::DEFINITIONS,
        ];

        if ($user->can(Permission::CommissionsView->value)) {
            $data['liabilities'] = $this->ledger->balances($filters->affiliateId);
            $data['refund_reversals_minor'] = $this->metrics->ledgerFlows($period, $filters->affiliateId)['refund_reversed_minor'];
        }
        if ($user->can(Permission::PayoutsView->value)) {
            $data['payouts'] = $this->payoutQueue($filters->affiliateId);
        }
        if ($user->can(Permission::AuditLogsView->value)) {
            $data['recent_events'] = AuditLog::query()->with('actor:id,name')->latest('id')->limit(8)->get()
                ->map(fn (AuditLog $log) => [
                    'action' => $log->action,
                    'actor' => $log->actor?->name,
                    'subject_type' => $log->subject_type,
                    'created_at' => $log->created_at?->toIso8601String(),
                ]);
        }

        return ['data' => $data];
    }

    /** Money view for finance: liabilities, flows, the payout queue and reconciliation. */
    public function finance(Request $request): array
    {
        $period = AffiliateDashboard::period($request);
        $user = $request->user();

        $owed = DB::table('commission_ledger_entries')
            ->selectRaw("affiliate_profile_id,
                SUM(CASE WHEN to_bucket = 'available' THEN amount_minor ELSE 0 END) - SUM(CASE WHEN from_bucket = 'available' THEN amount_minor ELSE 0 END) as available,
                SUM(CASE WHEN to_bucket = 'reserved' THEN amount_minor ELSE 0 END) - SUM(CASE WHEN from_bucket = 'reserved' THEN amount_minor ELSE 0 END) as reserved")
            ->groupBy('affiliate_profile_id');
        $topOwed = DB::query()->fromSub($owed, 'b')
            ->join('affiliate_profiles', 'affiliate_profiles.id', '=', 'b.affiliate_profile_id')
            ->whereRaw('b.available + b.reserved <> 0')
            ->orderByRaw('b.available + b.reserved DESC')
            ->limit(10)
            ->get(['affiliate_profiles.public_id', 'affiliate_profiles.display_name', 'affiliate_profiles.referral_code', 'b.available', 'b.reserved'])
            ->map(fn ($r) => [
                'affiliate_id' => $r->public_id,
                'display_name' => $r->display_name,
                'referral_code' => $r->referral_code,
                'available_minor' => (int) $r->available,
                'reserved_minor' => (int) $r->reserved,
            ]);

        $refunds = DB::table('refunds')->whereBetween('completed_at', $period->utc())
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount_minor), 0) as total')->first();
        $lastRun = ReconciliationRun::query()->latest('id')->first();

        return ['data' => [
            'period' => $period->describe(),
            'currency' => config('affiliate.currency.default'),
            'balances' => $this->ledger->balances(),
            'on_hold_minor' => (int) DB::table('commissions')->whereNotNull('held_at')
                ->whereIn('status', [CommissionStatus::Pending->value, CommissionStatus::Approved->value])
                ->selectRaw('COALESCE(SUM(amount_minor - reversed_minor), 0) as total')->value('total'),
            'flows' => $this->metrics->ledgerFlows($period),
            'series' => $this->metrics->ledgerSeries($period),
            'payouts' => $this->payoutQueue(null) + [
                'paid_in_period' => DB::table('payouts')->where('status', PayoutStatus::Paid->value)->whereBetween('paid_at', $period->utc())->count(),
            ],
            'refunds' => ['count' => (int) $refunds->n, 'amount_minor' => (int) $refunds->total],
            'top_owed' => $topOwed,
            'reconciliation' => $user->can(Permission::ReconciliationView->value) && $lastRun ? [
                'result' => $lastRun->result,
                'finished_at' => $lastRun->finished_at?->toIso8601String(),
                'issues' => count($lastRun->issues ?? []),
            ] : null,
            'definitions' => Metrics::DEFINITIONS,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function payoutQueue(?int $affiliateId): array
    {
        $rows = DB::table('payouts')
            ->when($affiliateId, fn ($q, $id) => $q->where('affiliate_profile_id', $id))
            ->whereIn('status', [PayoutStatus::Requested->value, PayoutStatus::Approved->value])
            ->selectRaw('status, COUNT(*) as n, COALESCE(SUM(amount_minor), 0) as total, MIN(requested_at) as oldest')
            ->groupBy('status')->get()->keyBy('status');
        $requested = $rows[PayoutStatus::Requested->value] ?? null;
        $approved = $rows[PayoutStatus::Approved->value] ?? null;

        return [
            'awaiting_review' => ['count' => (int) ($requested->n ?? 0), 'amount_minor' => (int) ($requested->total ?? 0)],
            'approved_unpaid' => ['count' => (int) ($approved->n ?? 0), 'amount_minor' => (int) ($approved->total ?? 0)],
            'oldest_requested_at' => $requested?->oldest ? CarbonImmutable::parse($requested->oldest, 'UTC')->toIso8601String() : null,
        ];
    }

    public static function filters(Request $request): Filters
    {
        $data = $request->validate([
            'affiliate_id' => ['nullable', 'string', 'ulid'],
            'product_id' => ['nullable', 'string', 'ulid'],
            'coupon_id' => ['nullable', 'string', 'ulid'],
        ]);
        $resolve = function (string $model, string $key) use ($data): ?int {
            if (empty($data[$key])) {
                return null;
            }
            $id = $model::query()->where('public_id', $data[$key])->value('id');
            if (! $id) {
                throw ValidationException::withMessages([$key => 'Not found.']);
            }

            return (int) $id;
        };

        return new Filters(
            $resolve(AffiliateProfile::class, 'affiliate_id'),
            $resolve(Product::class, 'product_id'),
            $resolve(Coupon::class, 'coupon_id'),
        );
    }
}
