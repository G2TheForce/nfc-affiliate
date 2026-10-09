<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Bootstraps the first Super Administrator. There is no default password:
 * the operator types one, and it must meet the password policy.
 */
class CreateAdmin extends Command
{
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

        $name = $this->option('name') ?: text('Full name', required: true);
        $email = mb_strtolower(trim($this->option('email') ?: text('Email address', required: true)));
        if ($this->option('password-stdin')) {
            // Avoids putting the password in shell history or process lists.
            $password = $confirmation = rtrim((string) fgets($this->stdin()), "\r\n");
        } else {
            $password = password('Password (min 12 characters, mixed case and a number)', required: true);
            $confirmation = password('Confirm password', required: true);
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

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

    /**
     * @return resource
     */
    protected function stdin()
    {
        return app()->bound('admin.stdin') ? app('admin.stdin') : STDIN;
    }
}
