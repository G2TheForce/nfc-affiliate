<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_verified_super_admin_with_typed_password(): void
    {
        $this->artisan('affiliate:create-admin', ['--name' => 'Owner', '--email' => 'Owner@Store.test'])
            ->expectsQuestion('Password (min 12 characters, mixed case and a number)', 'OwnerPassword99')
            ->expectsQuestion('Confirm password', 'OwnerPassword99')
            ->assertSuccessful();

        $admin = User::where('email', 'owner@store.test')->firstOrFail();
        $this->assertTrue($admin->hasRole(Role::SuperAdmin->value));
        $this->assertTrue($admin->hasVerifiedEmail());
        $this->assertTrue(Hash::check('OwnerPassword99', $admin->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.bootstrap_created', 'subject_id' => $admin->public_id]);
    }

    public function test_password_can_be_read_from_stdin_for_scripted_setup(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "ScriptedPass123\n");
        rewind($stream);
        $this->app->instance('admin.stdin', $stream);

        $this->artisan('affiliate:create-admin', [
            '--name' => 'Owner', '--email' => 'owner@store.test', '--password-stdin' => true,
        ])->assertSuccessful();

        $this->assertTrue(Hash::check('ScriptedPass123', User::where('email', 'owner@store.test')->value('password')));
    }

    public function test_rejects_weak_password(): void
    {
        $this->artisan('affiliate:create-admin', ['--name' => 'Owner', '--email' => 'owner@store.test'])
            ->expectsQuestion('Password (min 12 characters, mixed case and a number)', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@store.test']);
    }

    public function test_rejects_mismatched_confirmation(): void
    {
        $this->artisan('affiliate:create-admin', ['--name' => 'Owner', '--email' => 'owner@store.test'])
            ->expectsQuestion('Password (min 12 characters, mixed case and a number)', 'OwnerPassword99')
            ->expectsQuestion('Confirm password', 'OwnerPassword98')
            ->assertFailed();
    }

    public function test_refuses_second_super_admin_without_flag(): void
    {
        $this->seed();
        $this->userWithRole(Role::SuperAdmin);

        $this->artisan('affiliate:create-admin', ['--name' => 'Two', '--email' => 'two@store.test'])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'two@store.test']);
    }

    public function test_production_seeder_creates_no_users(): void
    {
        $this->seed();

        $this->assertSame(0, User::count());
    }
}
