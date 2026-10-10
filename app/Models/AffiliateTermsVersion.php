<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Published terms are never edited: a change means publishing a new version.
 */
class AffiliateTermsVersion extends Model
{
    protected $fillable = ['version', 'title', 'body', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published terms cannot be edited; publish a new version.'));
    }

    public static function current(): ?self
    {
        return static::query()
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }
}
