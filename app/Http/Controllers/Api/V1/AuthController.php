<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdateAvatarRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return ApiResponse::success([
            'access_token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_in' => $result['expires_in'],
            'user' => new UserResource($result['user']),
        ], 'Sesión iniciada correctamente');
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $result = $this->authService->register($data);

        return ApiResponse::success([
            'access_token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_in' => $result['expires_in'],
            'user' => new UserResource($result['user']),
        ], 'Cuenta creada', 201);
    }

    public function me(): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->authService->me()),
            'Perfil obtenido'
        );
    }

    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return ApiResponse::success(null, 'Sesión cerrada correctamente');
    }

    public function refresh(): JsonResponse
    {
        $result = $this->authService->refresh();

        return ApiResponse::success([
            'access_token' => $result['token'],
            'token_type' => $result['token_type'],
            'expires_in' => $result['expires_in'],
            'user' => new UserResource($result['user']),
        ], 'Token renovado');
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();
        $updated = $this->authService->updateProfile($user, $request->validated());

        return ApiResponse::success(
            new UserResource($updated),
            'Perfil actualizado correctamente'
        );
    }

    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();
        $updated = $this->authService->updateAvatar($user, $request->file('avatar'));

        return ApiResponse::success(
            new UserResource($updated),
            'Foto de perfil actualizada'
        );
    }

    public function removeAvatar(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();
        $updated = $this->authService->removeAvatar($user);

        return ApiResponse::success(
            new UserResource($updated),
            'Foto de perfil eliminada'
        );
    }
}
