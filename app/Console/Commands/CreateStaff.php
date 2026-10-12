<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\CreatesAccounts;
use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates an Affiliate Manager, Finance Manager or Customer Support account.
 * Super administrators are created with affiliate:create-admin instead.
 */
class CreateStaff extends Command
{
    use CreatesAccounts;

    protected $signature = 'affiliate:create-staff
        {--role= : affiliate_manager, finance_manager or support}
        {--name= : Full name}
        {--email= : Email address}
        {--password-stdin : Read the password from standard input (for scripted setups)}';

    protected $description = 'Create a staff account (Affiliate Manager, Finance Manager or Customer Support)';

    public function handle(AuditLogger $audit): int
    {
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $allowed = [Role::AffiliateManager, Role::FinanceManager, Role::Support];
        $role = Role::tryFrom((string) $this->option('role'));
        if (! in_array($role, $allowed, true)) {
            $this->error('--role must be one of: '.implode(', ', array_map(fn (Role $r) => $r->value, $allowed)).'.');

            return self::FAILURE;
        }

        $account = $this->askForAccount();
        if ($account === null) {
            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($account, $role, $audit) {
            $user = User::create($account);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole($role->value);
            $audit->log('admin.staff_created', $user, ['via' => 'console', 'role' => $role->value], null);

            return $user;
        });

        $this->info("{$role->label()} {$user->email} created.");

        return self::SUCCESS;
    }
}
