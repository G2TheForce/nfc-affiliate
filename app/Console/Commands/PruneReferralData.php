<?php

namespace App\Console\Commands;

use App\Models\ReferralClick;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Enforces the click retention policy (config/affiliate.php privacy):
 * visitor and IP hashes are cleared after the metadata period, and click
 * rows are deleted after the click retention period. Attributed orders keep
 * their click time on order_attributions.
 */
class PruneReferralData extends Command
{
    private const BATCH = 5000;

    protected $signature = 'affiliate:prune-referral-data';

    protected $description = 'Clear old click metadata and delete clicks past the retention period';

    public function handle(): int
    {
        $metadataCutoff = now()->subDays((int) config('affiliate.privacy.click_metadata_retention_days'));
        $deleteCutoff = now()->subDays((int) config('affiliate.privacy.click_retention_days'));

        // In id ranges, so a large backlog never holds one long transaction
        // and no batch rescans rows an earlier batch already handled.
        $cleared = $this->inBatches($metadataCutoff, fn ($q) => $q
            ->where(fn ($q) => $q->whereNotNull('visitor_hash')->orWhereNotNull('ip_hash'))
            ->update(['visitor_hash' => null, 'ip_hash' => null]));
        $deleted = $this->inBatches($deleteCutoff, fn ($q) => $q->delete());

        $this->info("Cleared metadata on {$cleared} clicks; deleted {$deleted} clicks.");

        return self::SUCCESS;
    }

    /** @param  callable(Builder): int  $apply */
    private function inBatches(\DateTimeInterface $cutoff, callable $apply): int
    {
        $old = ReferralClick::query()->where('created_at', '<', $cutoff);
        $first = (clone $old)->min('id');
        $last = (clone $old)->max('id');
        $total = 0;
        for ($from = (int) $first; $first !== null && $from <= $last; $from += self::BATCH) {
            $total += $apply((clone $old)->whereBetween('id', [$from, $from + self::BATCH - 1]));
        }

        return $total;
    }
}
