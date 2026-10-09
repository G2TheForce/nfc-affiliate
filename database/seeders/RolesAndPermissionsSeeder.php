<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Support\Authorization\PermissionMatrix;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to run in production on every deploy. Roles end up with
 * exactly the permissions listed in PermissionMatrix.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            Role::findOrCreate($roleEnum->value, 'web')
                ->syncPermissions(PermissionMatrix::permissionsFor($roleEnum));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
