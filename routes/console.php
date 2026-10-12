<?php

use Illuminate\Support\Facades\Schedule;

// Privacy retention for referral clicks (see config/affiliate.php).
Schedule::command('affiliate:prune-referral-data')->dailyAt('03:15')->timezone(config('affiliate.business_timezone'))->withoutOverlapping(120)->onOneServer();

// Commission lifecycle and ledger checks (ADR 0003, ADR 0008).
Schedule::command('affiliate:mature-commissions')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('affiliate:reconcile-commissions')->dailyAt('03:30')->timezone(config('affiliate.business_timezone'))->withoutOverlapping(120)->onOneServer();

// Online payments whose customer never came back to the store (ADR 0011).
Schedule::command('payments:recheck')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Lock lifetimes above are short so a run killed mid-way (deploy, reboot)
// does not block the next ones for a day.

// Housekeeping: expired API tokens and month-old failed jobs.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
