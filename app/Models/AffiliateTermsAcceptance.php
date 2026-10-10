<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateTermsAcceptance extends Model
{
    public $timestamps = false;

    protected $fillable = ['terms_version_id', 'accepted_at', 'ip_hash', 'user_agent'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(AffiliateTermsVersion::class, 'terms_version_id');
    }
}
