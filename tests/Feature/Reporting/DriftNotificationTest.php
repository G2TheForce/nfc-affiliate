<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commissions\ReconciliationService;
use App\Enums\Role;
use App\Notifications\Finance\ReconciliationDriftDetected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DriftNotificationTest extends TestCase
{
    use RefreshDatabase;
    use ReportingTestHelpers;

    public function test_finance_is_alerted_when_reconciliation_finds_drift_and_not_otherwise(): void
    {
        Notification::fake();
        $finance = $this->finance();
        $manager = $this->userWithRole(Role::AffiliateManager);
        $affiliate = $this->approvedAffiliate();

        app(ReconciliationService::class)->run();
        Notification::assertNothingSent();

        DB::table('commission_ledger_entries')->insert([
            'affiliate_profile_id' => $affiliate->id, 'type' => 'adjustment', 'from_bucket' => null, 'to_bucket' => 'available',
            'amount_minor' => 500, 'currency' => 'NPR', 'idempotency_key' => 'drift', 'created_at' => now(),
        ]);
        $run = app(ReconciliationService::class)->run();

        Notification::assertSentTo($finance, ReconciliationDriftDetected::class, fn ($n) => $n->runId === $run->public_id && $n->issues === count($run->issues));
        Notification::assertNotSentTo($manager, ReconciliationDriftDetected::class);
    }
}
