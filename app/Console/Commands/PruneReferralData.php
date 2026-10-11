<?php

namespace App\Console\Commands;

use App\Models\ReferralClick;
use Illuminate\Console\Command;

/**
 * Enforces the click retention policy (config/affiliate.php privacy):
 * visitor and IP hashes are cleared after the metadata period, and click
 * rows are deleted after the click retention period. Attributed orders keep
 * their click time on order_attributions.
 */
class PruneReferralData extends Command
{
    protected $signature = 'affiliate:prune-referral-data';

    protected $description = 'Clear old click metadata and delete clicks past the retention period';

    public function handle(): int
    {
        $metadataCutoff = now()->subDays((int) config('affiliate.privacy.click_metadata_retention_days'));
        $deleteCutoff = now()->subDays((int) config('affiliate.privacy.click_retention_days'));

        $cleared = ReferralClick::query()
            ->where('created_at', '<', $metadataCutoff)
            ->where(fn ($q) => $q->whereNotNull('visitor_hash')->orWhereNotNull('ip_hash'))
            ->update(['visitor_hash' => null, 'ip_hash' => null]);

        $deleted = ReferralClick::query()->where('created_at', '<', $deleteCutoff)->delete();

        $this->info("Cleared metadata on {$cleared} clicks; deleted {$deleted} clicks.");

        return self::SUCCESS;
    }
}
