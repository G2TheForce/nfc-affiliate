<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AffiliateLink extends Model
{
    protected $fillable = ['name', 'slug', 'product_id', 'utm_source', 'utm_medium', 'utm_campaign', 'is_active', 'campaign_id'];

    protected $hidden = ['id', 'affiliate_profile_id', 'product_id', 'campaign_id'];

    protected static function booted(): void
    {
        static::creating(function (AffiliateLink $link) {
            $link->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
