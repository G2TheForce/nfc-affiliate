<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe: only reference data. No demo users or financial
     * records are ever created here. Development data lives in
     * DevelopmentSeeder and refuses to run in production.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
