<?php

namespace Tests\Feature\Settings;

use App\Domain\Settings\SystemSettings;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_changes_a_setting_and_it_applies_and_is_audited(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $before = Cache::get('illuminate:queue:restart');

        $this->actingAs($admin)->patchJson('/api/v1/admin/settings', ['settings' => [
            ['key' => 'payouts.minimum_minor', 'value' => 250000],
            ['key' => 'attribution.coupon_overrides_click', 'value' => false],
            ['key' => 'attribution.window_days', 'value' => (int) config('affiliate.attribution.window_days')],
        ]])->assertOk()->assertJsonPath('changed', ['payouts.minimum_minor', 'attribution.coupon_overrides_click']);

        $this->assertSame(250000, config('affiliate.payouts.minimum_minor'));
        $this->assertFalse(config('affiliate.attribution.coupon_overrides_click'));
        $this->assertDatabaseHas('system_settings', ['key' => 'payouts.minimum_minor', 'updated_by' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'setting.changed', 'actor_id' => $admin->id]);
        $this->assertNotSame($before, Cache::get('illuminate:queue:restart'));

        $row = collect($this->actingAs($admin)->getJson('/api/v1/admin/settings')->assertOk()->json('data'))->firstWhere('key', 'payouts.minimum_minor');
        $this->assertSame(250000, $row['value']);
        $this->assertSame(100000, $row['default']);
        $this->assertTrue($row['overridden']);
        $this->assertSame($admin->name, $row['updated_by']);
    }

    public function test_saved_settings_survive_a_fresh_boot(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $this->actingAs($admin)->patchJson('/api/v1/admin/settings', ['settings' => [['key' => 'commissions.holding_period_days', 'value' => 21]]])->assertOk();

        // Simulate a new process: config back to the file default, then the boot step.
        config(['affiliate.commissions.holding_period_days' => 14]);
        Cache::flush();
        app(SystemSettings::class)->apply();

        $this->assertSame(21, config('affiliate.commissions.holding_period_days'));
    }

    public function test_reset_returns_to_the_env_default(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $this->actingAs($admin)->patchJson('/api/v1/admin/settings', ['settings' => [['key' => 'store.shipping_flat_minor', 'value' => 15000]]])->assertOk();
        $this->assertSame(15000, config('affiliate.store.shipping_flat_minor'));

        $this->actingAs($admin)->deleteJson('/api/v1/admin/settings/store.shipping_flat_minor')->assertOk();

        $this->assertSame(0, config('affiliate.store.shipping_flat_minor'));
        $this->assertDatabaseMissing('system_settings', ['key' => 'store.shipping_flat_minor']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'setting.reset']);
    }

    public function test_values_are_checked_against_limits_and_unknown_keys_are_refused(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);

        $this->actingAs($admin)->patchJson('/api/v1/admin/settings', ['settings' => [
            ['key' => 'attribution.window_days', 'value' => 0],
            ['key' => 'attribution.policy', 'value' => 'random'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['settings.0.value', 'settings.1.value']);

        $this->actingAs($admin)->patchJson('/api/v1/admin/settings', ['settings' => [['key' => 'app.key', 'value' => 'x']]])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.0.key');
        $this->assertDatabaseCount('system_settings', 0);

        $this->actingAs($admin)->deleteJson('/api/v1/admin/settings/app.key')->assertNotFound();
    }

    public function test_only_super_admins_change_settings(): void
    {
        $this->actingAs($this->userWithRole(Role::AffiliateManager))->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::FinanceManager))
            ->patchJson('/api/v1/admin/settings', ['settings' => [['key' => 'payouts.minimum_minor', 'value' => 1]]])->assertForbidden();
    }
}
