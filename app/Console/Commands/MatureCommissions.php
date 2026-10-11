<?php

namespace App\Console\Commands;

use App\Domain\Commissions\CommissionMaturation;
use Illuminate\Console\Command;

class MatureCommissions extends Command
{
    protected $signature = 'affiliate:mature-commissions';

    protected $description = 'Approve commissions past their holding period and release them to available balances';

    public function handle(CommissionMaturation $maturation): int
    {
        $result = $maturation->run();
        $this->info("Approved {$result['approved']} and released {$result['released']} commissions.");

        return self::SUCCESS;
    }
}
