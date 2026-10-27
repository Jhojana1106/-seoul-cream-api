<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserService
{
    public function list(?string $search = null, int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->with('roles')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('document_number', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);
    }

    public function find(int $id): User
    {
        return User::query()
            ->with(['roles.permissions', 'permissions'])
            ->findOrFail($id);
    }

    /**
     * @param  array{name: string, email: string, password: string, phone?: string, document_number?: string, is_active?: bool, roles?: list<string|int>}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            if (! empty($data['roles'])) {
                $this->syncRoles($user, $data['roles']);
            }

            return $user->load(['roles.permissions', 'permissions']);
        });
    }

    /**
     * @param  array{name?: string, email?: string, password?: string, phone?: string|null, document_number?: string|null, is_active?: bool, roles?: list<string|int>}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $payload = collect($data)->only([
                'name',
                'email',
                'phone',
                'document_number',
                'is_active',
            ])->all();

            if (! empty($data['password'])) {
                $payload['password'] = $data['password'];
            }

            $user->update($payload);

            if (array_key_exists('roles', $data)) {
                $this->syncRoles($user, $data['roles'] ?? []);
            }

            return $user->fresh(['roles.permissions', 'permissions']);
        });
    }

    public function delete(User $user): void
    {
        if ($user->hasRole('super-admin') && User::role('super-admin')->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => ['No se puede eliminar el único super-admin del sistema.'],
            ]);
        }

        $user->delete();
    }

    /**
     * @param  list<string|int>  $roles
     */
    public function syncRoles(User $user, array $roles): User
    {
        $roleModels = Role::query()
            ->where('guard_name', config('access.guard', 'api'))
            ->where(function ($q) use ($roles) {
                $q->whereIn('id', $roles)->orWhereIn('name', $roles);
            })
            ->get();

        $user->syncRoles($roleModels);

        return $user->load(['roles.permissions', 'permissions']);
    }
}
