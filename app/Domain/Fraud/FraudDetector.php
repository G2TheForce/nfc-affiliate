<?php

namespace App\Domain\Fraud;

use App\Domain\Audit\AuditLogger;
use App\Enums\AffiliateStatus;
use App\Enums\FraudFlagStatus;
use App\Enums\FraudSignal;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Models\AffiliatePayoutMethod;
use App\Models\FraudFlag;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\Fraud\FraudFlagsRaised;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Raises fraud flags for staff review (spec section 15). Thresholds come from
 * config('affiliate.fraud'). A flag never holds, voids or blocks anything;
 * reviewers act with the existing commission and payout tools.
 *
 * While a flag for the same signal and affiliate is open, or was raised in
 * the last 30 days, the same signal is not raised again.
 */
class FraudDetector
{
    private const QUIET_DAYS = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Daily scan over every affiliate who can earn or is suspended.
     *
     * @return Collection<int, FraudFlag> flags raised by this run
     */
    public function scan(?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $config = config('affiliate.fraud');
        $day = $now->copy()->timezone((string) config('affiliate.business_timezone'))->toDateString();
        $affiliateIds = DB::table('affiliate_profiles')
            ->whereIn('status', [AffiliateStatus::Approved->value, AffiliateStatus::Suspended->value])
            ->pluck('id');
        $raised = collect();

        $month = $now->copy()->subDays(30);
        $uniqueClicks = $this->countBy(DB::table('referral_clicks')->where('is_unique', true)->where('created_at', '>=', $month), 'affiliate_profile_id', $affiliateIds);
        $paidOrders = $this->countBy($this->paidReferred($month, $now), 'order_attributions.affiliate_profile_id', $affiliateIds);
        $clickOrders = $this->countBy($this->paidReferred($month, $now)->where('order_attributions.source', 'click'), 'order_attributions.affiliate_profile_id', $affiliateIds);

        $quarter = $now->copy()->subDays(90);
        $quarterOrders = $this->countBy($this->paidReferred($quarter, $now), 'order_attributions.affiliate_profile_id', $affiliateIds);
        $quarterRefunded = $this->countBy(
            $this->paidReferred($quarter, $now)->whereIn('orders.status', [OrderStatus::Refunded->value, OrderStatus::PartiallyRefunded->value]),
            'order_attributions.affiliate_profile_id', $affiliateIds,
        );

        $week = $now->copy()->subDays(7);
        $recentEarnings = $this->sumBy(DB::table('commissions')->where('created_at', '>=', $week), $affiliateIds);
        $earlierEarnings = $this->sumBy(DB::table('commissions')->where('created_at', '>=', $now->copy()->subDays(63))->where('created_at', '<', $week), $affiliateIds);

        foreach ($affiliateIds as $id) {
            $clicks = $uniqueClicks[$id] ?? 0;
            $fromClicks = $clickOrders[$id] ?? 0;
            if ($fromClicks >= $config['conversion_min_orders'] && $fromClicks * 100 > $config['conversion_max_percent'] * max($clicks, 1)) {
                $raised->push($this->raise($id, FraudSignal::HighConversion, 'medium', [
                    'period_days' => 30, 'unique_clicks' => $clicks, 'orders_from_clicks' => $fromClicks,
                    'conversion_percent' => round($fromClicks * 100 / max($clicks, 1), 1),
                    'threshold_percent' => $config['conversion_max_percent'],
                ], "{$day}"));
            }

            if ($clicks >= $config['clicks_without_orders'] && ($paidOrders[$id] ?? 0) === 0) {
                $raised->push($this->raise($id, FraudSignal::ClicksWithoutOrders, 'low', [
                    'period_days' => 30, 'unique_clicks' => $clicks, 'paid_orders' => 0, 'threshold_clicks' => $config['clicks_without_orders'],
                ], "{$day}"));
            }

            $orders = $quarterOrders[$id] ?? 0;
            $refunded = $quarterRefunded[$id] ?? 0;
            if ($orders >= $config['refund_rate_min_orders'] && $refunded * 100 >= $config['refund_rate_max_percent'] * $orders) {
                $raised->push($this->raise($id, FraudSignal::RefundRate, 'medium', [
                    'period_days' => 90, 'paid_orders' => $orders, 'refunded_orders' => $refunded,
                    'refund_percent' => round($refunded * 100 / $orders, 1), 'threshold_percent' => $config['refund_rate_max_percent'],
                ], "{$day}"));
            }

            $recent = $recentEarnings[$id] ?? 0;
            $weeklyAverage = intdiv($earlierEarnings[$id] ?? 0, 8);
            if ($recent >= $config['earnings_spike_min_minor'] && ($weeklyAverage === 0 || $recent >= $config['earnings_spike_multiple'] * $weeklyAverage)) {
                $currency = (string) config('affiliate.currency.default');
                $raised->push($this->raise($id, FraudSignal::RapidEarnings, 'medium', [
                    'last_7_days' => Money::format($recent, $currency),
                    'weekly_average_before' => Money::format($weeklyAverage, $currency),
                    'threshold_multiple' => $config['earnings_spike_multiple'],
                ], "{$day}"));
            }
        }

        $raised = $raised->filter()->values();
        $this->notifyReviewers($raised, payoutRelated: false);

        return $raised;
    }

    /**
     * Checks a payout request as it is made. Called inside the request's
     * transaction; notices go out after commit.
     *
     * @return Collection<int, FraudFlag>
     */
    public function checkPayoutRequest(Payout $payout, ?AffiliatePayoutMethod $method): Collection
    {
        $config = config('affiliate.fraud');
        $raised = collect();

        if ($payout->amount_minor >= $config['large_payout_minor']) {
            $raised->push($this->raise($payout->affiliate_profile_id, FraudSignal::LargePayout, 'low', [
                'amount' => Money::format($payout->amount_minor, $payout->currency),
                'threshold' => Money::format($config['large_payout_minor'], $payout->currency),
            ], "payout:{$payout->public_id}", $payout, quiet: false));
        }

        // Details replaced (not first set) shortly before asking for money.
        if ($method && $method->updated_at && $method->created_at
            && $method->updated_at->gt($method->created_at->copy()->addMinute())
            && $method->updated_at->gte(now()->subDays($config['detail_change_days']))) {
            $raised->push($this->raise($payout->affiliate_profile_id, FraudSignal::PayoutAfterDetailChange, 'high', [
                'details_changed_at' => $method->updated_at->toIso8601String(),
                'destination' => $method->masked,
                'window_days' => $config['detail_change_days'],
            ], "payout:{$payout->public_id}", $payout, quiet: false));
        }

        $raised = $raised->filter()->values();
        $this->notifyReviewers($raised, payoutRelated: true);

        return $raised;
    }

    public function review(FraudFlag $flag, User $actor, FraudFlagStatus $outcome, string $note): FraudFlag
    {
        return DB::transaction(function () use ($flag, $actor, $outcome, $note) {
            $locked = FraudFlag::query()->whereKey($flag->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FraudFlagStatus::Open) {
                throw ValidationException::withMessages(['status' => 'This flag has already been reviewed.']);
            }
            $locked->forceFill([
                'status' => $outcome,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();
            $this->audit->log('fraud_flag.reviewed', $locked, ['signal' => $locked->signal->value, 'outcome' => $outcome->value, 'note' => $note], $actor);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function raise(int $affiliateId, FraudSignal $signal, string $severity, array $details, string $key, ?Payout $subject = null, bool $quiet = true): ?FraudFlag
    {
        if ($quiet && FraudFlag::query()
            ->where('affiliate_profile_id', $affiliateId)
            ->where('signal', $signal->value)
            ->where(fn ($q) => $q->where('status', FraudFlagStatus::Open->value)->orWhere('detected_at', '>=', now()->subDays(self::QUIET_DAYS)))
            ->exists()) {
            return null;
        }

        $flag = new FraudFlag;
        $flag->forceFill([
            'affiliate_profile_id' => $affiliateId,
            'signal' => $signal,
            'severity' => $severity,
            'details' => $details,
            'subject_type' => $subject ? 'payout' : null,
            'subject_id' => $subject?->public_id,
            'dedupe_key' => "{$signal->value}:{$affiliateId}:{$key}",
            'status' => FraudFlagStatus::Open,
            'detected_at' => now(),
        ]);

        try {
            // A savepoint, so a duplicate does not abort a surrounding transaction.
            DB::transaction(fn () => $flag->save());
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        $this->audit->log('fraud_flag.raised', $flag, ['signal' => $signal->value, 'severity' => $severity] + $details, null);

        return $flag;
    }

    /**
     * @param  Collection<int, FraudFlag>  $flags
     */
    private function notifyReviewers(Collection $flags, bool $payoutRelated): void
    {
        if ($flags->isEmpty()) {
            return;
        }
        $permissions = $payoutRelated ? [Permission::FraudReview->value, Permission::PayoutsReview->value] : [Permission::FraudReview->value];
        $reviewers = User::permission($permissions)->where('status', 'active')->get();
        $lines = (new EloquentCollection($flags->all()))->load('affiliate')->map(fn (FraudFlag $f) => $f->signal->label().': '.$f->affiliate->display_name)->all();
        Notification::send($reviewers, (new FraudFlagsRaised($lines))->afterCommit());
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, int>
     */
    private function countBy($query, string $column, Collection $ids): array
    {
        return $query->whereIn($column, $ids)->groupBy($column)->selectRaw("{$column} as aid, count(*) as n")
            ->pluck('n', 'aid')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return array<int, int>
     */
    private function sumBy($query, Collection $ids): array
    {
        return $query->whereIn('affiliate_profile_id', $ids)->groupBy('affiliate_profile_id')
            ->selectRaw('affiliate_profile_id as aid, sum(amount_minor) as n')
            ->pluck('n', 'aid')->map(fn ($n) => (int) $n)->all();
    }

    private function paidReferred(CarbonInterface $from, CarbonInterface $to)
    {
        return DB::table('order_attributions')
            ->join('orders', 'orders.id', '=', 'order_attributions.order_id')
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$from, $to]);
    }
}
