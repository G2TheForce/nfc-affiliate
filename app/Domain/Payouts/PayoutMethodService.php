<?php

namespace App\Domain\Payouts;

use App\Domain\Audit\AuditLogger;
use App\Enums\PayoutMethodType;
use App\Models\AffiliatePayoutMethod;
use App\Models\AffiliateProfile;
use App\Models\User;
use App\Notifications\Payouts\PayoutMethodChanged;
use Illuminate\Support\Facades\DB;

/**
 * Saves where an affiliate is paid. A payout already requested keeps the
 * details it was requested with, so a change here (for example by someone
 * who took over the account) cannot redirect money that is in progress.
 */
class PayoutMethodService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{type: string, account_name: string, bank_name?: ?string, branch?: ?string, account_number?: ?string, wallet_id?: ?string}  $data
     */
    public function save(AffiliateProfile $affiliate, array $data, User $actor): AffiliatePayoutMethod
    {
        $type = PayoutMethodType::from($data['type']);
        $details = $type->isWallet()
            ? ['wallet_id' => $data['wallet_id']]
            : ['bank_name' => $data['bank_name'], 'branch' => $data['branch'] ?? null, 'account_number' => $data['account_number']];
        $identifier = $type->isWallet() ? $data['wallet_id'] : $data['account_number'];
        $masked = ($type->isWallet() ? $type->label() : $data['bank_name']).' ••••'.substr((string) $identifier, -4);

        return DB::transaction(function () use ($affiliate, $type, $data, $details, $masked, $actor) {
            $method = AffiliatePayoutMethod::query()->where('affiliate_profile_id', $affiliate->id)->lockForUpdate()->first()
                ?? new AffiliatePayoutMethod;
            $previous = $method->exists ? $method->masked : null;

            $method->forceFill([
                'affiliate_profile_id' => $affiliate->id,
                'type' => $type,
                'account_name' => $data['account_name'],
                'details' => $details,
                'masked' => $masked,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->log('payout_method.saved', $affiliate, [
                'type' => $type->value,
                'previous' => $previous,
                'current' => $masked,
            ], $actor);
            $affiliate->user->notify((new PayoutMethodChanged($masked))->afterCommit());

            return $method;
        });
    }

    /**
     * Full details for finance making the transfer.
     *
     * @return array<string, ?string>
     */
    public static function describe(PayoutMethodType $type, string $accountName, array $details): array
    {
        return ['type' => $type->value, 'type_label' => $type->label(), 'account_name' => $accountName] + $details;
    }
}
