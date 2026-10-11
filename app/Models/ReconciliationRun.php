<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReconciliationRun extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'triggered_by'];

    protected static function booted(): void
    {
        static::creating(function (ReconciliationRun $run) {
            $run->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'issues' => 'array',
            'commissions_checked' => 'integer',
            'affiliates_checked' => 'integer',
            'entries_checked' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by')->withTrashed();
    }
}
