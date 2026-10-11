<?php

namespace App\Console\Commands;

use App\Domain\Commissions\ReconciliationService;
use Illuminate\Console\Command;

class ReconcileCommissions extends Command
{
    protected $signature = 'affiliate:reconcile-commissions';

    protected $description = 'Re-derive commission balances from source records and record any drift';

    public function handle(ReconciliationService $reconciliation): int
    {
        $run = $reconciliation->run();
        $issues = count($run->issues);
        $this->line("Checked {$run->commissions_checked} commissions and {$run->entries_checked} ledger entries: {$run->result}".($issues ? " ({$issues} issues)" : '').'.');

        return $run->result === 'clean' ? self::SUCCESS : self::FAILURE;
    }
}
