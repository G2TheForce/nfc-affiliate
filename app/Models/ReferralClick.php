<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReferralClick extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'affiliate_profile_id', 'affiliate_link_id', 'product_id', 'visitor_hash', 'ip_hash'];

    protected static function booted(): void
    {
        static::creating(function (ReferralClick $click) {
            $click->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['is_unique' => 'boolean', 'set_attribution' => 'boolean', 'created_at' => 'datetime'];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class, 'affiliate_link_id');
    }
}
