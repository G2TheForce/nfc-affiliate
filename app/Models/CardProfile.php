<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A digital tap profile: what a customer's NFC card opens. Public at
 * /p/{handle} once published; contact details are what the owner chose
 * to show, nothing is taken from their account.
 */
class CardProfile extends Model
{
    public const THEMES = ['aurora', 'violet', 'teal', 'gold', 'graphite', 'ocean'];

    public const SOCIALS = ['facebook', 'instagram', 'linkedin', 'tiktok', 'youtube', 'x'];

    protected $fillable = [
        'handle', 'display_name', 'headline', 'company', 'bio', 'phone', 'whatsapp',
        'email', 'website', 'location', 'socials', 'theme', 'is_published',
    ];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'socials' => 'array',
            'is_published' => 'boolean',
            'taps_total' => 'integer',
            'saves_total' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(CardProfileDailyStat::class);
    }
}
