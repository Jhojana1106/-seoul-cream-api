<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = config('access.guard', 'api');
        $modules = config('access.permissions', []);

        foreach ($modules as $permissions) {
            foreach (array_keys($permissions) as $name) {
                Permission::findOrCreate($name, $guard);
            }
        }
    }
}
