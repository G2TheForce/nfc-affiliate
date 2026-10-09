<?php

namespace Tests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles and permissions are reference data every test relies on.
     * RefreshDatabase migrates (and seeds) once per run, so this must be
     * set for every test class, not only some of them.
     */
    protected bool $seed = true;

    protected function userWithRole(Role $role, array $attributes = []): User
    {
        return User::factory()->withRole($role)->create($attributes);
    }
}
