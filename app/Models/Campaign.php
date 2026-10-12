<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A dated promotion. Its commission comes from commission rules with scope
 * "campaign"; orders reach it through a campaign link or coupon.
 */
class Campaign extends Model
{
    protected $fillable = ['name', 'description', 'starts_at', 'ends_at', 'budget_minor', 'is_active', 'all_affiliates'];

    protected $hidden = ['id', 'created_by'];

    protected $attributes = ['is_active' => true, 'all_affiliates' => true];

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            $campaign->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'budget_minor' => 'integer',
            'is_active' => 'boolean',
            'all_affiliates' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    public function scopeRunningAt(Builder $query, CarbonInterface $at): void
    {
        $query->where('is_active', true)
            ->where('starts_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    public function scopeOpenTo(Builder $query, AffiliateProfile $affiliate): void
    {
        $query->where(fn ($q) => $q
            ->where('all_affiliates', true)
            ->orWhereHas('affiliates', fn ($q) => $q->whereKey($affiliate->id)));
    }

    public function isRunningAt(CarbonInterface $at): bool
    {
        return $this->is_active && $this->starts_at->lte($at) && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    public function includes(AffiliateProfile $affiliate): bool
    {
        return $this->all_affiliates || $this->affiliates()->whereKey($affiliate->id)->exists();
    }

    public function affiliates(): BelongsToMany
    {
        return $this->belongsToMany(AffiliateProfile::class, 'campaign_affiliate');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'campaign_product');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(AffiliateLink::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(OrderAttribution::class);
    }
}
