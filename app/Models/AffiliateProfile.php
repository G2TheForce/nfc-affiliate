<?php

namespace App\Models;

use App\Enums\AffiliateStatus;
use Database\Factories\AffiliateProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AffiliateProfile extends Model
{
    /** @use HasFactory<AffiliateProfileFactory> */
    use HasFactory;

    /**
     * Fields a member may set. Status, referral identifiers and approval
     * columns are only ever changed by AffiliateMembershipService.
     *
     * @var list<string>
     */
    protected $fillable = [
        'display_name',
        'country_code',
        'city',
        'address_line',
        'promotion_channels',
        'website_urls',
        'audience_description',
    ];

    protected $hidden = ['id', 'user_id', 'approved_by'];

    protected static function booted(): void
    {
        static::creating(function (AffiliateProfile $profile) {
            $profile->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => AffiliateStatus::class,
            'promotion_channels' => 'array',
            'website_urls' => 'array',
            'applied_at' => 'datetime',
            'approved_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(AffiliateStatusHistory::class)->orderByDesc('id');
    }

    public function termsAcceptances(): HasMany
    {
        return $this->hasMany(AffiliateTermsAcceptance::class)->orderByDesc('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(AffiliateNote::class)->orderByDesc('id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(AffiliateLink::class)->orderByDesc('id');
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(ReferralClick::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function hasAcceptedTerms(AffiliateTermsVersion $version): bool
    {
        return $this->termsAcceptances()->where('terms_version_id', $version->id)->exists();
    }
}
