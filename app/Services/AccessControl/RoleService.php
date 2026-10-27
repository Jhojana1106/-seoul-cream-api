<?php

namespace App\Services\AccessControl;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    public function __construct(
        private readonly PermissionRegistrar $permissionRegistrar
    ) {}

    public function list(?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        return Role::query()
            ->where('guard_name', config('access.guard', 'api'))
            ->with('permissions')
            ->withCount('users')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function find(int $id): Role
    {
        return Role::query()
            ->where('guard_name', config('access.guard', 'api'))
            ->with('permissions')
            ->withCount('users')
            ->findOrFail($id);
    }

    /**
     * @param  array{name: string, permissions?: list<string|int>}  $data
     */
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => config('access.guard', 'api'),
            ]);

            if (! empty($data['permissions'])) {
                $this->syncPermissions($role, $data['permissions']);
            }

            $this->permissionRegistrar->forgetCachedPermissions();

            return $role->load('permissions')->loadCount('users');
        });
    }

    /**
     * @param  array{name?: string, permissions?: list<string|int>}  $data
     */
    public function update(Role $role, array $data): Role
    {
        $this->assertNotProtected($role);

        return DB::transaction(function () use ($role, $data) {
            if (isset($data['name'])) {
                $role->update(['name' => $data['name']]);
            }

            if (array_key_exists('permissions', $data)) {
                $this->syncPermissions($role, $data['permissions'] ?? []);
            }

            $this->permissionRegistrar->forgetCachedPermissions();

            return $role->fresh(['permissions'])->loadCount('users');
        });
    }

    public function delete(Role $role): void
    {
        $this->assertNotProtected($role);

        if ($role->users()->count() > 0) {
            throw ValidationException::withMessages([
                'role' => ['No se puede eliminar un rol asignado a usuarios.'],
            ]);
        }

        $role->delete();
        $this->permissionRegistrar->forgetCachedPermissions();
    }

    /**
     * @param  list<string|int>  $permissions
     */
    public function syncPermissions(Role $role, array $permissions): Role
    {
        $this->assertNotProtected($role);

        $permissionModels = Permission::query()
            ->where('guard_name', config('access.guard', 'api'))
            ->where(function ($q) use ($permissions) {
                $q->whereIn('id', $permissions)->orWhereIn('name', $permissions);
            })
            ->get();

        $role->syncPermissions($permissionModels);
        $this->permissionRegistrar->forgetCachedPermissions();

        return $role->load('permissions')->loadCount('users');
    }

    private function assertNotProtected(Role $role): void
    {
        if (in_array($role->name, config('access.protected_roles', []), true)) {
            throw ValidationException::withMessages([
                'role' => ['Este rol del sistema está protegido y no puede modificarse.'],
            ]);
        }
    }
}
