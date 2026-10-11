<?php

namespace App\Enums;

enum AttributionStatus: string
{
    case Attributed = 'attributed';
    case None = 'none';
    // A referral was present but not allowed, e.g. self-referral.
    case Blocked = 'blocked';
}
