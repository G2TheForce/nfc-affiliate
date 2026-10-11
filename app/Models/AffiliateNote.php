<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff-only note. Never returned by affiliate-facing endpoints.
 */
class AffiliateNote extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['author_id', 'body'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id')->withTrashed();
    }
}
