<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * DEVELOPMENT ONLY. Creates one verified user per role with emails ending in
 *
 * @dev.invalid so they can never be confused with real accounts.
 *
 *   php artisan db:seed --class=DevelopmentSeeder
 */
class DevelopmentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DevelopmentSeeder must never run in production.');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        foreach (Role::cases() as $role) {
            $user = User::firstOrCreate(
                ['email' => str_replace('_', '-', $role->value).'@dev.invalid'],
                ['name' => '[DEV] '.$role->label(), 'password' => 'DevPassword123'],
            );
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role->value]);
        }

        $this->command?->warn('Development users created with password DevPassword123 (local use only).');
    }
}
