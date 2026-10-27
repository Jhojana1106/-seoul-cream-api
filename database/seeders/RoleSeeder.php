<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = config('access.guard', 'api');
        $allPermissions = Permission::where('guard_name', $guard)->get();

        $superAdmin = Role::findOrCreate(SystemRole::SuperAdmin->value, $guard);
        $superAdmin->syncPermissions($allPermissions);

        $admin = Role::findOrCreate(SystemRole::Admin->value, $guard);
        $admin->syncPermissions(
            $allPermissions->reject(fn (Permission $p) => str_starts_with($p->name, 'permissions.delete'))
        );

        Role::findOrCreate(SystemRole::Customer->value, $guard)->syncPermissions([]);

        $cashier = Role::findOrCreate(SystemRole::Cashier->value, $guard);
        $cashier->syncPermissions(
            $allPermissions->whereIn('name', [
                'dashboard.view',
                'orders.view',
                'orders.update',
                'pos.access',
                'pos.discount',
                'pos.refund',
                'products.view',
                'customers.view',
                'cash.manage',
            ])
        );

        $production = Role::findOrCreate(SystemRole::Production->value, $guard);
        $production->syncPermissions(
            $allPermissions->whereIn('name', [
                'dashboard.view',
                'products.view',
                'display-cases.view',
                'display-cases.update',
                'inventory.view',
                'inventory.move',
                'ingredients.view',
                'recipes.view',
                'recipes.create',
                'recipes.update',
                'production.view',
                'production.create',
            ])
        );
    }
}
