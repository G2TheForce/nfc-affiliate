<?php

namespace Tests\Feature\Reporting;

use App\Domain\Commissions\CommissionLedger;
use App\Enums\Role;
use App\Models\AffiliateProfile;
use App\Models\AuditLog;
use App\Models\Commission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
{
    use RefreshDatabase;
    use ReportingTestHelpers;

    private const RANGE = 'from=2026-09-01&to=2026-09-30';

    public function test_report_list_follows_permissions(): void
    {
        $finance = $this->finance();
        $keys = collect($this->actingAs($finance)->getJson('/api/v1/admin/reports')->assertOk()->json('data.reports'))->pluck('key');
        $this->assertContains('payout-liability', $keys);
        $this->assertContains('refunds', $keys);
        $this->assertNotContains('fraud-review', $keys, 'Finance does not review fraud.');
        $this->assertTrue($this->getJson('/api/v1/admin/reports')->json('data.can_export'));

        $manager = $this->userWithRole(Role::AffiliateManager);
        $keys = collect($this->actingAs($manager)->getJson('/api/v1/admin/reports')->json('data.reports'))->pluck('key');
        $this->assertContains('fraud-review', $keys);
        $this->assertFalse($this->getJson('/api/v1/admin/reports')->json('data.can_export'));

        $support = $this->userWithRole(Role::Support);
        $this->assertSame(['affiliate-registrations'], collect($this->actingAs($support)->getJson('/api/v1/admin/reports')->json('data.reports'))->pluck('key')->all());
        $this->actingAs($support)->getJson('/api/v1/admin/reports/refunds?'.self::RANGE)->assertForbidden();
        $this->actingAs($support)->getJson('/api/v1/admin/reports/payout-liability')->assertForbidden();
        $this->actingAs($manager)->get('/api/v1/admin/reports/affiliate-performance/export?format=csv&'.self::RANGE)->assertForbidden();
        $this->actingAs($finance)->getJson('/api/v1/admin/reports/nope')->assertNotFound();
        $this->actingAs($this->userWithRole(Role::Customer))->getJson('/api/v1/admin/reports')->assertForbidden();
    }

    public function test_previews_reconcile_with_the_dashboard_and_ledger(): void
    {
        $s = $this->scenario();
        $admin = $this->userWithRole(Role::SuperAdmin);
        $this->actingAs($admin);

        $dashboard = $this->getJson('/api/v1/admin/dashboard?'.self::RANGE)->json('data.totals');

        $perf = collect($this->getJson('/api/v1/admin/reports/affiliate-performance?'.self::RANGE)->assertOk()->json('data.rows'));
        $this->assertSame($dashboard['revenue_minor'], $perf->sum('revenue_minor'));
        $this->assertSame($dashboard['clicks'], $perf->sum('clicks'));

        $accruals = collect($this->getJson('/api/v1/admin/reports/commission-accruals?'.self::RANGE)->json('data.rows'));
        $this->assertSame(Commission::count(), $accruals->count());
        $this->assertSame($dashboard['commission_earned_minor'], $accruals->sum('amount_minor'));
        $this->assertSame('10%', $accruals->first()['rate']);

        $liability = collect($this->getJson('/api/v1/admin/reports/payout-liability')->assertOk()->assertJsonPath('data.period', null)->json('data.rows'));
        $balances = app(CommissionLedger::class)->balances();
        foreach (['pending', 'approved', 'available', 'reserved', 'paid', 'reversed'] as $bucket) {
            $this->assertSame($balances[$bucket], $liability->sum($bucket), $bucket);
        }

        $reversals = $this->getJson('/api/v1/admin/reports/commission-reversals?'.self::RANGE)->json('data.rows');
        $this->assertCount(1, $reversals);
        $this->assertSame(10000, $reversals[0]['amount_minor']);

        $refunds = $this->getJson('/api/v1/admin/reports/refunds?'.self::RANGE)->json('data.rows');
        $this->assertSame(10000, $refunds[0]['commission_reversed_minor']);
        $this->assertSame($s['a']->display_name, $refunds[0]['affiliate']);

        $retention = collect($this->getJson('/api/v1/admin/reports/affiliate-retention?'.self::RANGE)->json('data.rows'));
        $this->assertSame([['month' => '2026-09', 'active' => 2, 'first_time' => 2, 'retained' => 0, 'retention_rate' => null]], $retention->all());

        $registrations = $this->getJson('/api/v1/admin/reports/affiliate-registrations?'.self::RANGE)->json('data.rows');
        $this->assertCount(2, $registrations);
        $this->assertSame('approved', $registrations[0]['status']);
    }

    public function test_csv_export_is_streamed_formatted_and_audited(): void
    {
        $s = $this->scenario();
        $finance = $this->finance();

        $response = $this->actingAs($finance)->get('/api/v1/admin/reports/commission-accruals/export?format=csv&'.self::RANGE);
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('commission-accruals_2026-09-01_2026-09-30.csv', $response->headers->get('Content-Disposition'));

        $lines = $this->csv($response);
        $this->assertSame(['Order paid', 'Commission ID', 'Affiliate', 'Order', 'Product', 'Commission base', 'Rate', 'Commission', 'Reversed', 'Net', 'Status'], $lines[0]);
        $this->assertCount(Commission::count() + 1, $lines);
        // Money in rupees with two decimals; times in Nepal time.
        $first = collect(array_slice($lines, 1))->firstWhere(3, $s['paid']->order_number);
        $this->assertSame(['2026-09-01 09:45', '2000.00', '200.00', '100.00', '100.00'], [$first[0], $first[5], $first[7], $first[8], $first[9]]);

        $log = AuditLog::query()->where('action', 'report.exported')->sole();
        $this->assertSame($finance->id, $log->actor_id);
        $this->assertSame('commission-accruals', $log->properties['report']);
        $this->assertSame('csv', $log->properties['format']);
    }

    public function test_exports_neutralise_spreadsheet_formulas(): void
    {
        $this->travelTo('2026-09-01 04:00:00');
        $this->rule(['rate_bp' => 1000]);
        $evil = $this->approvedAffiliate();
        $evil->forceFill(['display_name' => '=HYPERLINK("http://evil.example","x")'])->save();
        $this->order($evil, $this->product(['price_minor' => 100000]), 1);
        $this->clicks($evil, 1, 1);

        $lines = $this->csv($this->actingAs($this->finance())->get('/api/v1/admin/reports/affiliate-performance/export?format=csv&'.self::RANGE));
        $this->assertSame('\'=HYPERLINK("http://evil.example","x")', $lines[1][0]);

        $xlsx = $this->actingAs($this->finance())->get('/api/v1/admin/reports/affiliate-performance/export?format=xlsx&'.self::RANGE);
        $sheet = $this->sheetXml($xlsx);
        $this->assertStringContainsString('<t>\'=HYPERLINK', html_entity_decode($sheet, ENT_QUOTES | ENT_XML1));
        $this->assertStringNotContainsString('<f>', $sheet);
    }

    public function test_xlsx_export_is_a_valid_workbook_with_numeric_money(): void
    {
        $this->scenario();
        $response = $this->actingAs($this->finance())->get('/api/v1/admin/reports/payout-liability/export?format=xlsx');
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->sheetXml($response);
        $this->assertStringContainsString('<t>Pending</t>', $sheet);
        $this->assertMatchesRegularExpression('/<c r="D2"[^>]*><v>\d+(\.\d+)?<\/v><\/c>/', $sheet, 'Money cells are numbers, not text.');
    }

    public function test_affiliate_exports_only_their_own_masked_data(): void
    {
        $s = $this->scenario();

        $this->assertSame(['my-commissions', 'my-orders', 'my-payouts', 'my-statement'],
            collect($this->actingAs($s['b']->user)->getJson('/api/v1/affiliate/reports')->assertOk()->json('data.reports'))->pluck('key')->all());

        $lines = $this->csv($this->actingAs($s['b']->user)->get('/api/v1/affiliate/reports/my-orders/export?format=csv&'.self::RANGE.'&affiliate_id='.$s['a']->public_id));
        $this->assertCount(2, $lines);
        $this->assertSame('…'.substr($s['other']->order_number, -4), $lines[1][1]);
        $this->assertStringNotContainsString('sita@example.com', implode(',', $lines[1]));

        $statement = $this->csv($this->actingAs($s['a']->user)->get('/api/v1/affiliate/reports/my-statement/export?format=csv&'.self::RANGE));
        $this->assertSame(['Date', 'Description', 'From', 'To', 'Amount'], $statement[0]);
        $this->assertCount(4, $statement, 'Two accruals and one refund reversal.');

        $this->actingAs($s['a']->user)->get('/api/v1/affiliate/reports/payout-liability/export?format=csv')->assertNotFound();
        $this->actingAs($s['a']->user)->getJson('/api/v1/affiliate/reports/my-orders/export?format=pdf')->assertUnprocessable()->assertJsonValidationErrors('format');
        $this->assertTrue(AuditLog::query()->where('action', 'report.exported')->where('actor_id', $s['a']->user->id)->exists());
    }

    public function test_fraud_review_lists_holds_and_blocked_attributions(): void
    {
        $s = $this->scenario();
        $manager = $this->userWithRole(Role::AffiliateManager);
        $commission = Commission::query()->where('affiliate_profile_id', $s['b']->id)->sole();
        $this->actingAs($manager)->postJson("/api/v1/admin/commissions/{$commission->public_id}/hold", ['reason' => 'Same address as the affiliate'])->assertOk();

        // A self-referral: the affiliate buys with their own code while signed in.
        $this->placeOrder($this->product(), null, ['referral_code' => $s['a']->referral_code], $s['a']->user)->assertCreated();

        $rows = collect($this->actingAs($manager)->getJson('/api/v1/admin/reports/fraud-review?'.self::RANGE)->assertOk()->json('data.rows'));
        $this->assertSame(['Attribution blocked at checkout', 'Commission put on hold'], $rows->pluck('event')->all());
        $this->assertSame('Same address as the affiliate', $rows[1]['reason']);
        $this->assertSame($manager->name, $rows[1]['actor']);
    }

    public function test_preview_is_capped_and_says_so(): void
    {
        $this->travelTo('2026-09-01 04:00:00');
        $a = $this->approvedAffiliate();
        for ($i = 0; $i < 101; $i++) {
            AffiliateProfile::factory()->create(['applied_at' => now()]);
        }
        $data = $this->actingAs($this->userWithRole(Role::SuperAdmin))->getJson('/api/v1/admin/reports/affiliate-registrations?'.self::RANGE)->json('data');
        $this->assertCount(100, $data['rows']);
        $this->assertTrue($data['truncated']);
        $this->assertNotNull($a);
    }

    /**
     * @return list<list<string>>
     */
    private function csv(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", substr($content, 3))));

        return array_values($rows);
    }

    private function sheetXml(TestResponse $response): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Export is a valid zip/xlsx file.');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $sheet;
    }
}
