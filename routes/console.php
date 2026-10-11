<?php

use Illuminate\Support\Facades\Schedule;

// Privacy retention for referral clicks (see config/affiliate.php).
Schedule::command('affiliate:prune-referral-data')->dailyAt('03:15')->timezone(config('affiliate.business_timezone'))->withoutOverlapping();

// Commission lifecycle and ledger checks (ADR 0003, ADR 0008).
Schedule::command('affiliate:mature-commissions')->hourly()->withoutOverlapping();
Schedule::command('affiliate:reconcile-commissions')->dailyAt('03:30')->timezone(config('affiliate.business_timezone'))->withoutOverlapping();

// Online payments whose customer never came back to the store (ADR 0011).
Schedule::command('payments:recheck')->everyFiveMinutes()->withoutOverlapping();
