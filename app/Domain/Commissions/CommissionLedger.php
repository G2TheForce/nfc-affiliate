<?php

namespace App\Domain\Commissions;

use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Models\CommissionLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of commission_ledger_entries (ADR 0003). Every entry has a
 * unique idempotency key, so replaying an event cannot post it twice.
 * Balances are always sums over entries; nothing stores a balance.
 */
class CommissionLedger
{
    /**
     * @param  array{commission_id?: ?int, commission_adjustment_id?: ?int, refund_id?: ?int}  $refs
     * @return bool false when an entry with this key already exists
     */
    public function post(
        int $affiliateProfileId,
        LedgerEntryType $type,
        ?LedgerBucket $from,
        ?LedgerBucket $to,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        array $refs = [],
        ?User $actor = null,
    ): bool {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Ledger amounts must be positive; the buckets give the direction.');
        }
        if ($from === $to) {
            throw new InvalidArgumentException('A ledger entry must move money between two different buckets.');
        }

        return CommissionLedgerEntry::query()->insertOrIgnore([
            'affiliate_profile_id' => $affiliateProfileId,
            'commission_id' => $refs['commission_id'] ?? null,
            'commission_adjustment_id' => $refs['commission_adjustment_id'] ?? null,
            'refund_id' => $refs['refund_id'] ?? null,
            'type' => $type->value,
            'from_bucket' => $from?->value,
            'to_bucket' => $to?->value,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'idempotency_key' => $idempotencyKey,
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]) === 1;
    }

    /**
     * Balance per bucket for one affiliate, or for the whole programme.
     *
     * @return array<string, int> keyed by bucket value, every bucket present
     */
    public function balances(?int $affiliateProfileId = null): array
    {
        $balances = array_fill_keys(array_map(fn (LedgerBucket $b) => $b->value, LedgerBucket::cases()), 0);

        $in = DB::table('commission_ledger_entries')
            ->when($affiliateProfileId, fn ($q, $id) => $q->where('affiliate_profile_id', $id))
            ->whereNotNull('to_bucket')
            ->groupBy('to_bucket')
            ->selectRaw('to_bucket as bucket, SUM(amount_minor) as total')
            ->pluck('total', 'bucket');
        $out = DB::table('commission_ledger_entries')
            ->when($affiliateProfileId, fn ($q, $id) => $q->where('affiliate_profile_id', $id))
            ->whereNotNull('from_bucket')
            ->groupBy('from_bucket')
            ->selectRaw('from_bucket as bucket, SUM(amount_minor) as total')
            ->pluck('total', 'bucket');

        foreach ($balances as $bucket => $_) {
            $balances[$bucket] = (int) ($in[$bucket] ?? 0) - (int) ($out[$bucket] ?? 0);
        }

        return $balances;
    }
}
