<?php

namespace App\Models;

use App\Enums\AttributionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderAttribution extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['source' => AttributionSource::class, 'clicked_at' => 'datetime', 'window_days' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    public function click(): BelongsTo
    {
        return $this->belongsTo(ReferralClick::class, 'referral_click_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
