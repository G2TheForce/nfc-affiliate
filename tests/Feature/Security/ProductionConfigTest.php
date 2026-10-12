<?php

namespace Tests\Feature\Security;

use App\Providers\AppServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Settings that only matter in production, where the test suite normally
 * never runs: the CSP, proxies, scheduler locks and notification retries.
 */
class ProductionConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function asProduction(): void
    {
        $this->app['env'] = 'production';
    }

    public function test_production_csp_lets_the_order_page_post_to_esewa_only_when_esewa_is_on(): void
    {
        $this->asProduction();
        config(['payments.providers.esewa.enabled' => false]);
        $csp = $this->get('/up')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self';", $csp.';');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        config(['payments.providers.esewa.enabled' => true, 'payments.providers.esewa.mode' => 'production']);
        $csp = $this->get('/up')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self' https://epay.esewa.com.np", $csp);
        $this->assertStringNotContainsString('rc-epay', $csp);
    }

    public function test_trusted_proxies_come_from_config_so_they_survive_config_cache(): void
    {
        config(['app.trusted_proxies' => '10.0.0.0/8']);
        (new AppServiceProvider($this->app))->boot();
        Route::get('/api/v1/_test/ip', fn (Request $r) => ['ip' => $r->ip(), 'secure' => $r->isSecure()]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->getJson('/api/v1/_test/ip', ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->assertJson(['ip' => '203.0.113.7', 'secure' => true]);
        // A request not from the proxy cannot spoof its address.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->getJson('/api/v1/_test/ip', ['X-Forwarded-For' => '203.0.113.7'])
            ->assertJson(['ip' => '198.51.100.9']);
    }

    public function test_scheduled_jobs_recover_from_a_killed_run_and_run_on_one_server(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => trim(str($e->command)->after('artisan')->replace(["'", '"'], '')));

        foreach (['payments:recheck' => 10, 'affiliate:mature-commissions' => 55, 'affiliate:reconcile-commissions' => 120, 'affiliate:prune-referral-data' => 120] as $command => $minutes) {
            $event = $events->first(fn ($e, $key) => str_starts_with($key, $command));
            $this->assertNotNull($event, "$command is scheduled");
            $this->assertTrue($event->withoutOverlapping, "$command does not overlap");
            $this->assertSame($minutes, $event->expiresAt, "$command lock expires after $minutes minutes");
            $this->assertTrue($event->onOneServer, "$command runs on one server");
        }
    }

    public function test_every_queued_notification_retries_with_backoff_and_a_timeout(): void
    {
        $files = Finder::create()->files()->in(app_path('Notifications'))->name('*.php');
        $checked = 0;
        foreach ($files as $file) {
            $class = 'App\\Notifications\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }
            $props = (new \ReflectionClass($class))->getDefaultProperties();
            $this->assertGreaterThanOrEqual(3, $props['tries'] ?? 1, "$class retries");
            $this->assertNotEmpty($props['backoff'] ?? null, "$class backs off");
            $this->assertLessThan(config('queue.connections.database.retry_after'), $props['timeout'] ?? PHP_INT_MAX, "$class times out before the job is retried");
            $checked++;
        }
        $this->assertGreaterThan(10, $checked);
    }

    public function test_secure_defaults_for_sessions_and_tokens(): void
    {
        $session = require config_path('session.php');
        $this->assertTrue($session['http_only']);
        $this->assertNotNull(config('sanctum.expiration'));
        $this->assertLessThanOrEqual(60 * 24 * 30, config('sanctum.expiration'));
    }
}
