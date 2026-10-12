<?php

namespace App\Console\Commands;

use App\Domain\Payments\ProviderPaymentService;
use Illuminate\Console\Command;

/**
 * Asks eSewa and Khalti about online payments that have not finished, so an
 * order is still marked paid when the customer closed the browser before
 * returning to the store, or the callback was lost.
 */
class RecheckPayments extends Command
{
    protected $signature = 'payments:recheck {--limit=100}';

    protected $description = 'Check unfinished online payments with the provider';

    public function handle(ProviderPaymentService $payments): int
    {
        $result = $payments->recheckDue((int) $this->option('limit'));
        $this->info("Checked {$result['checked']} payment attempts, {$result['completed']} now paid.");

        return self::SUCCESS;
    }
}
