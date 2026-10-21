<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffAccountCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function stdin(string $password): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $password."\n");
        rewind($stream);
        $this->app->instance('admin.stdin', $stream);
    }

    public function test_creates_verified_staff_account_with_the_role(): void
    {
        $this->stdin('FinancePass123');

        $this->artisan('affiliate:create-staff', [
            '--role' => 'finance_manager', '--name' => 'Finance', '--email' => 'Finance@Store.test', '--password-stdin' => true,
        ])->assertSuccessful();

        $user = User::where('email', 'finance@store.test')->firstOrFail();
        $this->assertTrue($user->hasRole(Role::FinanceManager->value));
        $this->assertFalse($user->hasRole(Role::SuperAdmin->value));
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('FinancePass123', $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.staff_created', 'subject_id' => $user->public_id]);
    }

    public function test_refuses_super_admin_customer_and_unknown_roles(): void
    {
        foreach (['super_admin', 'affiliate', 'customer', 'owner', ''] as $role) {
            $this->artisan('affiliate:create-staff', ['--role' => $role, '--name' => 'X', '--email' => 'x@store.test'])
                ->assertFailed();
        }
        $this->assertDatabaseMissing('users', ['email' => 'x@store.test']);
    }

    public function test_refuses_weak_password_and_existing_email(): void
    {
        $this->stdin('short');
        $this->artisan('affiliate:create-staff', [
            '--role' => 'support', '--name' => 'S', '--email' => 's@store.test', '--password-stdin' => true,
        ])->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 's@store.test']);

        User::factory()->create(['email' => 'taken@store.test']);
        $this->stdin('SupportPass123');
        $this->artisan('affiliate:create-staff', [
            '--role' => 'support', '--name' => 'S', '--email' => 'taken@store.test', '--password-stdin' => true,
        ])->assertFailed();
    }

    public function test_disabling_signs_the_account_out_everywhere_and_can_be_undone(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['email' => 'leaver@store.test']);
        $user->assignRole(Role::Support->value);
        $user->createToken('phone');
        DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->artisan('affiliate:set-user-status', ['email' => 'leaver@store.test', 'status' => 'disabled', '--reason' => 'Left the company'])
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(UserStatus::Disabled, $user->status);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.status_changed', 'subject_id' => $user->public_id]);

        $this->artisan('affiliate:set-user-status', ['email' => 'leaver@store.test', 'status' => 'active', '--reason' => 'Rehired'])
            ->assertSuccessful();
        $this->assertSame(UserStatus::Active, $user->refresh()->status);
    }

    public function test_needs_a_reason_and_keeps_the_last_super_admin_active(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['email' => 'owner@store.test']);
        $admin->assignRole(Role::SuperAdmin->value);

        $this->artisan('affiliate:set-user-status', ['email' => 'owner@store.test', 'status' => 'disabled'])->assertFailed();
        $this->artisan('affiliate:set-user-status', ['email' => 'owner@store.test', 'status' => 'disabled', '--reason' => 'Testing it'])->assertFailed();
        $this->assertSame(UserStatus::Active, $admin->refresh()->status);

        $second = User::factory()->create();
        $second->assignRole(Role::SuperAdmin->value);
        $this->artisan('affiliate:set-user-status', ['email' => 'owner@store.test', 'status' => 'disabled', '--reason' => 'Owner change'])
            ->assertSuccessful();
    }

    public function test_verify_email_command_marks_the_address_verified_and_audits_it(): void
    {
        $user = User::factory()->create(['email' => 'ram@example.com', 'email_verified_at' => null]);

        $this->artisan('affiliate:verify-email', ['email' => ' Ram@Example.com '])->assertSuccessful();

        $this->assertTrue($user->refresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.email_verified', 'actor_id' => null]);
        $this->artisan('affiliate:verify-email', ['email' => 'ram@example.com'])->expectsOutputToContain('already verified')->assertSuccessful();
        $this->artisan('affiliate:verify-email', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
