<?php

namespace Tests\Feature\Authorization;

use App\Domain\Audit\AuditLogger;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Authorization\PermissionMatrix;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public static function staffAccess(): array
    {
        return [
            'super admin' => [Role::SuperAdmin, 200, 200],
            'affiliate manager' => [Role::AffiliateManager, 200, 403],
            'finance manager' => [Role::FinanceManager, 200, 403],
            'support' => [Role::Support, 200, 403],
            'affiliate' => [Role::Affiliate, 403, 403],
            'customer' => [Role::Customer, 403, 403],
        ];
    }

    #[DataProvider('staffAccess')]
    public function test_admin_routes_enforce_permissions_on_the_server(Role $role, int $ping, int $auditLogs): void
    {
        $user = $this->userWithRole($role);

        $this->actingAs($user)->getJson('/api/v1/admin/ping')->assertStatus($ping);
        $this->actingAs($user)->getJson('/api/v1/admin/audit-logs')->assertStatus($auditLogs);
    }

    public function test_guest_cannot_reach_admin_routes(): void
    {
        $this->getJson('/api/v1/admin/ping')->assertUnauthorized();
    }

    public function test_unverified_staff_cannot_reach_admin_routes(): void
    {
        $admin = User::factory()->unverified()->withRole(Role::SuperAdmin)->create();

        $this->actingAs($admin)->getJson('/api/v1/admin/ping')->assertForbidden();
        // Same answer without an Accept: application/json header (no redirect to a missing route).
        $this->actingAs($admin)->get('/api/v1/admin/ping')->assertStatus(302);
    }

    public function test_user_with_no_role_is_denied(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/admin/ping')->assertForbidden();
    }

    public function test_finance_permissions_do_not_include_system_configuration(): void
    {
        $finance = PermissionMatrix::permissionsFor(Role::FinanceManager);

        foreach ([Permission::SettingsManage, Permission::IntegrationsManage, Permission::AdminsManage] as $p) {
            $this->assertNotContains($p->value, $finance);
        }
    }

    public function test_support_cannot_touch_money(): void
    {
        $support = PermissionMatrix::permissionsFor(Role::Support);

        foreach ([Permission::CommissionsAdjust, Permission::PayoutsReview, Permission::PayoutsMarkPaid] as $p) {
            $this->assertNotContains($p->value, $support);
        }
    }

    public function test_affiliates_and_customers_hold_no_staff_permissions(): void
    {
        foreach ([Role::Affiliate, Role::Customer] as $role) {
            $this->assertNotContains(Permission::AdminAccess->value, PermissionMatrix::permissionsFor($role));
            $this->assertFalse($this->userWithRole($role)->isStaff());
        }
    }

    public function test_seeder_is_idempotent_and_resyncs_tampered_roles(): void
    {
        RoleModel::findByName(Role::Support->value)->givePermissionTo(Permission::PayoutsMarkPaid->value);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse(RoleModel::findByName(Role::Support->value)->hasPermissionTo(Permission::PayoutsMarkPaid->value));
        $this->assertSame(count(Role::cases()), RoleModel::count());
    }

    public function test_audit_log_endpoint_returns_paginated_entries_without_secrets(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        app(AuditLogger::class)->log('test.event', $admin, ['password' => 'Secret123', 'note' => 'ok'], $admin);

        $this->actingAs($admin)->getJson('/api/v1/admin/audit-logs?action=test.event')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'test.event')
            ->assertJsonPath('data.0.properties.password', '[redacted]')
            ->assertJsonPath('data.0.properties.note', 'ok')
            ->assertJsonPath('data.0.actor.id', $admin->public_id)
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total']]);

        $this->assertSame(1, AuditLog::where('action', 'test.event')->count());
    }

    public function test_permissions_matrix_document_is_in_sync(): void
    {
        $this->artisan('affiliate:permissions-matrix --check')->assertSuccessful();
    }
}
