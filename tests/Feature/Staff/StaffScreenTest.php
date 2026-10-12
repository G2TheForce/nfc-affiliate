<?php

namespace Tests\Feature\Staff;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\Auth\StaffInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_invites_staff_who_set_their_own_password_and_sign_in(): void
    {
        Notification::fake();
        $admin = $this->userWithRole(Role::SuperAdmin);

        $this->actingAs($admin)->postJson('/api/v1/admin/staff', ['name' => 'Sita Finance', 'email' => ' Sita@Example.com ', 'role' => 'finance_manager'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'sita@example.com')
            ->assertJsonPath('data.role', 'finance_manager')
            ->assertJsonPath('data.status', 'active');

        $sita = User::where('email', 'sita@example.com')->sole();
        $this->assertTrue($sita->hasRole('finance_manager'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.staff_created', 'actor_id' => $admin->id]);

        $token = null;
        Notification::assertSentTo($sita, StaffInvitation::class, function (StaffInvitation $n) use (&$token, $sita) {
            $token = $n->token;
            $mail = (string) $n->toMail($sita)->render();

            return str_contains($mail, 'Finance Manager') && str_contains($mail, '/reset-password?token=');
        });

        auth()->forgetGuards();
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'sita@example.com', 'password' => 'BrandNewPass123', 'password_confirmation' => 'BrandNewPass123',
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'sita@example.com', 'password' => 'BrandNewPass123', 'device_name' => 'test'])->assertOk();
    }

    public function test_list_shows_only_staff_and_marks_the_viewer(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $this->userWithRole(Role::Support, ['name' => 'Ram Support']);
        $this->userWithRole(Role::Affiliate);
        $this->userWithRole(Role::Customer);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/staff')->assertOk();
        $this->assertEqualsCanonicalizing(['super_admin', 'support'], array_column($response->json('data'), 'role'));
        $this->assertSame([true, false], array_map(fn ($u) => $u['is_you'], collect($response->json('data'))->sortBy('role')->values()->all()));
        $this->assertContains('finance_manager', array_column($response->json('roles'), 'value'));
        $this->assertNotContains('affiliate', array_column($response->json('roles'), 'value'));
    }

    public function test_disabling_signs_the_person_out_and_guards_hold(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $support = $this->userWithRole(Role::Support);
        $support->createToken('app');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $support->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$support->public_id}/status", ['status' => 'disabled', 'reason' => 'Left the company'])
            ->assertOk()->assertJsonPath('data.status', 'disabled');
        $this->assertSame(0, $support->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $support->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.status_changed', 'actor_id' => $admin->id]);

        $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$support->public_id}/invitation")
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$admin->public_id}/status", ['status' => 'disabled', 'reason' => 'Testing myself'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $affiliate = $this->userWithRole(Role::Affiliate);
        $this->actingAs($admin)->postJson("/api/v1/admin/staff/{$affiliate->public_id}/status", ['status' => 'disabled', 'reason' => 'Not staff'])
            ->assertNotFound();
    }

    public function test_only_super_admins_manage_staff_and_affiliate_roles_cannot_be_given(): void
    {
        $admin = $this->userWithRole(Role::SuperAdmin);
        $this->actingAs($admin)->postJson('/api/v1/admin/staff', ['name' => 'Hari', 'email' => 'hari@example.com', 'role' => 'affiliate'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->actingAs($admin)->postJson('/api/v1/admin/staff', ['name' => 'Dup', 'email' => strtoupper($admin->email), 'role' => 'support'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->actingAs($this->userWithRole(Role::AffiliateManager))->getJson('/api/v1/admin/staff')->assertForbidden();
        $this->actingAs($this->userWithRole(Role::FinanceManager))->postJson('/api/v1/admin/staff', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'super_admin'])->assertForbidden();
    }
}
