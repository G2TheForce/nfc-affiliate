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
 * Bootstraps the first Super Administrator. There is no default password:
 * the operator types one, and it must meet the password policy.
 */
class CreateAdmin extends Command
{
    use CreatesAccounts;

    protected $signature = 'affiliate:create-admin
        {--name= : Full name}
        {--email= : Email address}
        {--additional : Allow creating another super administrator when one already exists}
        {--password-stdin : Read the password from standard input (for scripted setups)}';

    protected $description = 'Create a Super Administrator account (first-run bootstrap)';

    public function handle(AuditLogger $audit): int
    {
        $this->callSilently('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        if (User::role(Role::SuperAdmin->value)->exists() && ! $this->option('additional')) {
            $this->error('A super administrator already exists. Use --additional to create another one.');

            return self::FAILURE;
        }

        $account = $this->askForAccount();
        if ($account === null) {
            return self::FAILURE;
        }
        ['name' => $name, 'email' => $email, 'password' => $password] = $account;

        $user = DB::transaction(function () use ($name, $email, $password, $audit) {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(Role::SuperAdmin->value);
            $audit->log('admin.bootstrap_created', $user, ['via' => 'console'], null);

            return $user;
        });

        $this->info("Super administrator {$user->email} created.");

        return self::SUCCESS;
    }
}
