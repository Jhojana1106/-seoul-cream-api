<?php

namespace App\Services\AccessControl;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionService
{
    public function __construct(
        private readonly PermissionRegistrar $permissionRegistrar
    ) {}

    public function list(?string $search = null, ?string $module = null, int $perPage = 50): LengthAwarePaginator
    {
        return Permission::query()
            ->where('guard_name', config('access.guard', 'api'))
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($module, fn ($q) => $q->where('name', 'like', "{$module}.%"))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Agrupa permisos por módulo (users, sales, stations...) para el UI dinámico.
     *
     * @return Collection<string, Collection<int, Permission>>
     */
    public function catalog(): Collection
    {
        return Permission::query()
            ->where('guard_name', config('access.guard', 'api'))
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => explode('.', $permission->name)[0] ?? 'otros');
    }

    public function find(int $id): Permission
    {
        return Permission::query()
            ->where('guard_name', config('access.guard', 'api'))
            ->findOrFail($id);
    }

    /**
     * @param  array{name: string}  $data
     */
    public function create(array $data): Permission
    {
        $permission = Permission::create([
            'name' => $data['name'],
            'guard_name' => config('access.guard', 'api'),
        ]);

        $this->permissionRegistrar->forgetCachedPermissions();

        return $permission;
    }

    /**
     * @param  array{name: string}  $data
     */
    public function update(Permission $permission, array $data): Permission
    {
        $permission->update(['name' => $data['name']]);
        $this->permissionRegistrar->forgetCachedPermissions();

        return $permission->fresh();
    }

    public function delete(Permission $permission): void
    {
        if ($permission->roles()->count() > 0) {
            throw ValidationException::withMessages([
                'permission' => ['No se puede eliminar un permiso asignado a roles.'],
            ]);
        }

        $permission->delete();
        $this->permissionRegistrar->forgetCachedPermissions();
    }
}
