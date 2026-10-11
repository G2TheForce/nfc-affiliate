<?php

namespace App\Models;

use App\Enums\PayoutMethodType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where an affiliate wants to be paid. The account number or wallet id lives
 * only in the encrypted `details` column; screens and the audit log use
 * `masked`.
 */
class AffiliatePayoutMethod extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'affiliate_profile_id', 'details', 'updated_by'];

    protected function casts(): array
    {
        return [
            'type' => PayoutMethodType::class,
            'details' => 'encrypted:array',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }
}
