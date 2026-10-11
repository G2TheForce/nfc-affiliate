<?php

use Illuminate\Support\Facades\Schedule;

// Privacy retention for referral clicks (see config/affiliate.php).
Schedule::command('affiliate:prune-referral-data')->dailyAt('03:15')->timezone(config('affiliate.business_timezone'))->withoutOverlapping();
