<?php

namespace App\Models;

use App\Enums\FraudFlagStatus;
use App\Enums\FraudSignal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FraudFlag extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'affiliate_profile_id', 'reviewed_by', 'dedupe_key'];

    protected static function booted(): void
    {
        static::creating(function (FraudFlag $flag) {
            $flag->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'signal' => FraudSignal::class,
            'status' => FraudFlagStatus::class,
            'details' => 'array',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
