<?php

namespace App\Console\Commands;

use App\Domain\Fraud\FraudDetector;
use Illuminate\Console\Command;

class DetectFraud extends Command
{
    protected $signature = 'affiliate:detect-fraud';

    protected $description = 'Raise fraud flags for unusual conversion, clicks, refunds and earnings (for staff review)';

    public function handle(FraudDetector $detector): int
    {
        $flags = $detector->scan();
        $this->info($flags->isEmpty() ? 'No new fraud flags.' : "{$flags->count()} new fraud flag(s) raised for review.");

        return self::SUCCESS;
    }
}
