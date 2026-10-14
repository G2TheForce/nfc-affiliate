<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardProfileDailyStat extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        // "day" stays a plain Y-m-d string so lookups match on every database.
        return ['taps' => 'integer', 'saves' => 'integer'];
    }
}
