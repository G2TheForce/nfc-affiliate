<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketingAsset extends Model
{
    public const KINDS = ['image', 'video', 'document', 'archive', 'text'];

    /** Accepted upload types: detected MIME type => [kind, stored extension]. SVG is refused because it can carry script. */
    public const TYPES = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'video/mp4' => ['video', 'mp4'],
        'application/pdf' => ['document', 'pdf'],
        'application/zip' => ['archive', 'zip'],
    ];

    protected $fillable = ['title', 'description', 'caption', 'campaign_id', 'product_id', 'is_active'];

    protected $hidden = ['id', 'path', 'campaign_id', 'product_id', 'created_by'];

    protected $attributes = ['is_active' => true, 'download_count' => 0];

    protected static function booted(): void
    {
        static::creating(function (MarketingAsset $asset) {
            $asset->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_active' => 'boolean',
            'download_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function hasFile(): bool
    {
        return $this->path !== null;
    }

    /** A safe download name: the title plus the extension of the stored file. */
    public function downloadName(): string
    {
        $base = Str::slug($this->title) ?: 'asset';

        return $base.'.'.pathinfo((string) $this->path, PATHINFO_EXTENSION);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
