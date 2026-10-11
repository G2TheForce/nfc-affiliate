<?php

namespace App\Domain\Commissions;

use App\Enums\CommissionStatus;
use App\Models\AffiliateProfile;
use App\Models\Commission;
use App\Notifications\Affiliate\CommissionsAvailable;

/**
 * Scheduled step: approves pending commissions whose holding period has
 * passed and, when auto-release is on, adds them to the available balance.
 * Each commission moves in its own transaction, so one failure never blocks
 * the rest and a re-run picks up where the last one stopped.
 */
class CommissionMaturation
{
    public function __construct(private readonly CommissionLifecycle $lifecycle) {}

    /**
     * @return array{approved: int, released: int}
     */
    public function run(): array
    {
        $approved = 0;
        $released = 0;
        $autoRelease = (bool) config('affiliate.commissions.auto_release');
        /** @var array<int, int> $availableByAffiliate */
        $availableByAffiliate = [];

        Commission::query()
            ->where('status', CommissionStatus::Pending->value)
            ->whereNull('held_at')
            ->where('eligible_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($commissions) use (&$approved, &$released, &$availableByAffiliate, $autoRelease) {
                foreach ($commissions as $commission) {
                    if (! $this->lifecycle->approve($commission)) {
                        continue;
                    }
                    $approved++;

                    if ($autoRelease && $this->lifecycle->release($commission)) {
                        $released++;
                        $fresh = $commission->fresh();
                        $availableByAffiliate[$fresh->affiliate_profile_id] = ($availableByAffiliate[$fresh->affiliate_profile_id] ?? 0) + $fresh->netMinor();
                    }
                }
            });

        if ($availableByAffiliate !== []) {
            AffiliateProfile::query()->with('user')->whereIn('id', array_keys($availableByAffiliate))->get()
                ->each(fn (AffiliateProfile $affiliate) => $affiliate->user->notify(
                    new CommissionsAvailable($availableByAffiliate[$affiliate->id], (string) config('affiliate.currency.default')),
                ));
        }

        return ['approved' => $approved, 'released' => $released];
    }
}
