<?php

namespace App\Models;

use App\Enums\ReferralEventType;
use Illuminate\Database\Eloquent\Model;

class ReferralEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['type' => ReferralEventType::class, 'occurred_at' => 'datetime', 'amount_minor' => 'integer'];
    }
}
