<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CommissionPlan extends Model
{
    protected $fillable = ['name', 'description'];

    protected $hidden = ['id'];

    protected static function booted(): void
    {
        static::creating(function (CommissionPlan $plan) {
            $plan->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function affiliates(): HasMany
    {
        return $this->hasMany(AffiliateProfile::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CommissionRule::class);
    }
}
