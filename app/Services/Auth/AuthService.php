<?php

namespace App\Services\Auth;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AuthService
{
    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{token: string, token_type: string, expires_in: int, user: User}
     */
    public function login(array $credentials): array
    {
        if (! $token = Auth::guard('api')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales inválidas.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::guard('api')->user();

        if (! $user->isActive()) {
            Auth::guard('api')->logout();

            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está desactivada.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $user->load(['roles.permissions', 'permissions']);

        return $this->tokenPayload($token, $user);
    }

    /**
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $data
     * @return array{token: string, token_type: string, expires_in: int, user: User}
     */
    public function register(array $data): array
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
            ]);

            $role = Role::findOrCreate(SystemRole::Customer->value, config('access.guard', 'api'));
            $user->assignRole($role);

            return $user;
        });

        $token = Auth::guard('api')->login($user);
        $user->forceFill(['last_login_at' => now()])->save();
        $user->load(['roles.permissions', 'permissions']);

        return $this->tokenPayload($token, $user);
    }

    public function me(): User
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();
        $user->load(['roles.permissions', 'permissions']);

        return $user;
    }

    public function logout(): void
    {
        Auth::guard('api')->logout();
    }

    /**
     * @return array{token: string, token_type: string, expires_in: int, user: User}
     */
    public function refresh(): array
    {
        $token = Auth::guard('api')->refresh();

        /** @var User $user */
        $user = Auth::guard('api')->setToken($token)->user();
        $user->load(['roles.permissions', 'permissions']);

        return $this->tokenPayload($token, $user);
    }

    /**
     * @param  array{name?: string, email?: string, phone?: string|null, document_number?: string|null, current_password?: string, password?: string}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        if (! empty($data['password'])) {
            if (empty($data['current_password']) || ! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['La contraseña actual no es correcta.'],
                ]);
            }
        }

        $payload = collect($data)->only([
            'name',
            'email',
            'phone',
            'document_number',
        ])->all();

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $user->update($payload);

        return $user->fresh(['roles.permissions', 'permissions']);
    }

    public function updateAvatar(User $user, UploadedFile $file): User
    {
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $file->store('avatars/'.$user->id, 'public');
        $user->update(['avatar' => $path]);

        return $user->fresh(['roles.permissions', 'permissions']);
    }

    public function removeAvatar(User $user): User
    {
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
            $user->update(['avatar' => null]);
        }

        return $user->fresh(['roles.permissions', 'permissions']);
    }

    /**
     * @return array{token: string, token_type: string, expires_in: int, user: User}
     */
    private function tokenPayload(string $token, User $user): array
    {
        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => (int) Auth::guard('api')->factory()->getTTL() * 60,
            'user' => $user,
        ];
    }
}
