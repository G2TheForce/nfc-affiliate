<?php

namespace Tests\Concurrency;

use App\Domain\Commissions\CommissionLedger;
use App\Models\Commission;
use App\Models\CommissionLedgerEntry;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Payout;
use App\Models\Refund;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Feature\Payouts\PayoutTestHelpers;
use Tests\TestCase;

/**
 * Real races, not simulated ones: the same money action fired in parallel
 * at a multi-worker server backed by MySQL. Not part of the default suite
 * (SQLite cannot lock rows); run it with docs/load-and-concurrency.md.
 */
class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;
    use PayoutTestHelpers;

    private const PARALLEL = 8;

    private static ?Process $server = null;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Concurrency tests need MySQL (see docs/load-and-concurrency.md).');
        }
        config(['affiliate.holding_period_days' => 0]);
        $port = (int) (getenv('CONCURRENCY_PORT') ?: 8099);
        $this->base = "http://127.0.0.1:{$port}";
        $this->startServer($port);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(3);
        self::$server = null;
        parent::tearDownAfterClass();
    }

    private function startServer(int $port): void
    {
        if (self::$server?->isRunning()) {
            return;
        }
        $env = [
            'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'PHP_CLI_SERVER_WORKERS' => (string) self::PARALLEL,
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'),
            // Shared limiter state across workers; each simulated customer has its own address.
            'CACHE_STORE' => 'database', 'TRUSTED_PROXIES' => '*', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'SESSION_DRIVER' => 'array', 'AFFILIATE_HOLDING_PERIOD_DAYS' => '0',
        ];
        self::$server = new Process(['php', 'artisan', 'serve', '--host=127.0.0.1', "--port={$port}", '--no-reload'], base_path(), $env);
        self::$server->start();
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents("http://127.0.0.1:{$port}/up") !== false) {
                return;
            }
            usleep(200_000);
        }
        $this->fail('The test server did not start: '.self::$server->getErrorOutput());
    }

    /**
     * Fires all requests at once and waits for every answer.
     *
     * @param  list<array{method: string, path: string, body?: array<string, mixed>, headers?: list<string>}>  $requests
     * @return list<array{status: int, json: mixed}>
     */
    private function burst(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => $r) {
            $h = curl_init($this->base.$r['path']);
            curl_setopt_array($h, [
                CURLOPT_CUSTOMREQUEST => $r['method'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json', 'X-Forwarded-For: 198.51.100.'.($i + 1)], $r['headers'] ?? []),
                CURLOPT_POSTFIELDS => isset($r['body']) ? json_encode($r['body']) : null,
            ]);
            curl_multi_add_handle($multi, $h);
            $handles[] = $h;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 1.0);
        } while ($running > 0);

        return array_map(function ($h) use ($multi) {
            $out = ['status' => curl_getinfo($h, CURLINFO_HTTP_CODE), 'json' => json_decode((string) curl_multi_getcontent($h), true)];
            curl_multi_remove_handle($multi, $h);

            return $out;
        }, $handles);
    }

    private function tokenFor(string $email): string
    {
        $res = $this->burst([['method' => 'POST', 'path' => '/api/v1/auth/login', 'body' => ['email' => $email, 'password' => 'Password1234', 'device_name' => 'concurrency']]])[0];
        $this->assertSame(200, $res['status'], json_encode($res['json']));

        return $res['json']['token'] ?? $res['json']['data']['token'];
    }

    /** @return array<int, int> status => count */
    private function statuses(array $responses): array
    {
        $counts = array_count_values(array_column($responses, 'status'));
        ksort($counts);
        fwrite(STDERR, '  statuses '.json_encode($counts)."\n");

        return $counts;
    }

    private function checkoutBody(string $productId, string $referral, array $extra = []): array
    {
        return array_merge([
            'items' => [['product_id' => $productId, 'quantity' => 1]],
            'customer_name' => 'Race Customer', 'customer_email' => 'race-'.Str::random(6).'@example.com', 'customer_phone' => '+9779812345678',
            'shipping_city' => 'Kathmandu', 'shipping_address' => 'Baneshwor 10', 'referral_code' => $referral,
        ], $extra);
    }

    public function test_same_idempotency_key_in_parallel_creates_one_order(): void
    {
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 100000]);
        $body = $this->checkoutBody($product->public_id, $affiliate->referral_code, ['customer_email' => 'same@example.com']);
        $key = 'race-'.Str::uuid();

        $responses = $this->burst(array_fill(0, self::PARALLEL, ['method' => 'POST', 'path' => '/api/v1/store/orders', 'body' => $body, 'headers' => ["Idempotency-Key: {$key}"]]));
        $counts = $this->statuses($responses);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, $counts[201] ?? 0, 'exactly one request created the order');
        $ids = collect($responses)->filter(fn ($r) => in_array($r['status'], [200, 201], true))->pluck('json.data.id')->unique();
        $this->assertCount(1, $ids, 'every successful answer names the same order');
    }

    public function test_a_single_use_coupon_is_redeemed_once_under_parallel_checkouts(): void
    {
        $affiliate = $this->approvedAffiliate();
        $product = $this->product(['price_minor' => 100000]);
        Coupon::factory()->create(['code' => 'ONLYONCE', 'discount_type' => 'fixed', 'discount_value' => 10000, 'usage_limit' => 1]);

        $responses = $this->burst(array_map(fn ($i) => [
            'method' => 'POST', 'path' => '/api/v1/store/orders',
            'body' => $this->checkoutBody($product->public_id, $affiliate->referral_code, ['coupon_code' => 'ONLYONCE']),
            'headers' => ['Idempotency-Key: race-'.Str::uuid()],
        ], range(1, self::PARALLEL)));
        $counts = $this->statuses($responses);

        $this->assertSame(1, $counts[201] ?? 0);
        $this->assertSame(1, Order::query()->whereNotNull('coupon_id')->count());
        $this->assertSame(1, DB::table('coupon_redemptions')->count());
    }

    public function test_parallel_payment_confirmations_record_one_payment_and_one_commission(): void
    {
        $this->rule(['rate_bp' => 1000]);
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product(['price_minor' => 100000]), 1);
        $token = $this->tokenFor($this->finance()->email);

        $responses = $this->burst(array_map(fn ($i) => [
            'method' => 'POST', 'path' => "/api/v1/admin/orders/{$order->public_id}/payments",
            'body' => ['method' => 'bank_transfer', 'reference' => "NIC-RACE-{$i}", 'amount_minor' => $order->total_minor],
            'headers' => ["Authorization: Bearer {$token}"],
        ], range(1, self::PARALLEL)));
        $counts = $this->statuses($responses);

        $this->assertSame(1, $counts[201] ?? 0);
        $this->assertSame(1, OrderPayment::query()->count());
        $this->assertSame(1, Commission::query()->count());
        $this->assertSame('paid', $order->refresh()->status->value);
    }

    public function test_parallel_full_refunds_never_refund_more_than_was_paid(): void
    {
        $this->rule(['rate_bp' => 1000]);
        $order = $this->attributedOrder($this->approvedAffiliate(), $this->product(['price_minor' => 100000]), 1);
        $this->pay($order)->assertCreated();
        $item = $order->items()->sole();
        $token = $this->tokenFor($this->finance()->email);

        $responses = $this->burst(array_map(fn ($i) => [
            'method' => 'POST', 'path' => "/api/v1/admin/orders/{$order->public_id}/refunds",
            'body' => ['reference' => "RF-RACE-{$i}", 'reason' => 'Parallel refund test', 'items' => [['item_id' => $item->public_id, 'amount_minor' => $item->line_total_minor]]],
            'headers' => ["Authorization: Bearer {$token}"],
        ], range(1, self::PARALLEL)));
        $counts = $this->statuses($responses);

        $this->assertSame(1, $counts[201] ?? 0);
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame($item->line_total_minor, (int) Refund::query()->sum('amount_minor'));
        $this->assertSame(Commission::query()->sole()->amount_minor, Commission::query()->sole()->reversed_minor);
    }

    public function test_parallel_payout_requests_reserve_the_balance_once(): void
    {
        $affiliate = $this->approvedAffiliate();
        $this->earn($affiliate, 2000000);
        $this->saveBankMethod($affiliate)->assertOk();
        $available = app(CommissionLedger::class)->balances($affiliate->id)['available'];
        $this->assertGreaterThan(0, $available);
        $token = $this->tokenFor($affiliate->user->email);

        $responses = $this->burst(array_fill(0, self::PARALLEL, ['method' => 'POST', 'path' => '/api/v1/affiliate/payouts', 'headers' => ["Authorization: Bearer {$token}"]]));
        $counts = $this->statuses($responses);

        $this->assertSame(1, $counts[201] ?? 0);
        $this->assertSame(1, Payout::query()->count());
        $balances = app(CommissionLedger::class)->balances($affiliate->id);
        $this->assertSame(0, $balances['available']);
        $this->assertSame($available, $balances['reserved']);
    }

    public function test_maturation_run_twice_at_once_moves_each_commission_once(): void
    {
        $this->rule(['rate_bp' => 1000]);
        $affiliate = $this->approvedAffiliate();
        // Setup only: 20 checkouts from one address would hit the checkout limit.
        $this->withoutMiddleware(ThrottleRequests::class);
        for ($i = 0; $i < 20; $i++) {
            $this->pay($this->attributedOrder($affiliate, $this->product(['price_minor' => 100000]), 1))->assertCreated();
        }
        // Make them all due now (eligibility starts at a Nepal day boundary).
        DB::table('commissions')->update(['eligible_at' => now()->subMinute()]);
        $env = ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_HOST' => config('database.connections.mysql.host'), 'AFFILIATE_HOLDING_PERIOD_DAYS' => '0',
            'APP_ENV' => 'local', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'];
        $runs = array_map(fn () => new Process(['php', 'artisan', 'affiliate:mature-commissions'], base_path(), $env), range(1, 3));
        array_walk($runs, fn (Process $p) => $p->start());
        array_walk($runs, fn (Process $p) => $p->wait());
        foreach ($runs as $p) {
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
        }

        $this->assertSame(20, Commission::query()->where('status', 'available')->count());
        // Each commission is approved once and released once: no duplicate ledger moves.
        $dupes = CommissionLedgerEntry::query()->selectRaw('commission_id, type, COUNT(*) as n')->whereNotNull('commission_id')
            ->groupBy('commission_id', 'type')->havingRaw('COUNT(*) > 1')->count();
        $this->assertSame(0, $dupes);
        Artisan::call('affiliate:reconcile-commissions');
        $this->assertStringContainsString('clean', strtolower(Artisan::output()));
    }
}
