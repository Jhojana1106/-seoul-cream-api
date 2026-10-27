<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\AssignRolesRequest;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Services\User\UserService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->list(
            $request->string('search')->toString() ?: null,
            (int) $request->integer('per_page', 15)
        );

        return ApiResponse::paginated(
            UserResource::collection($users),
            'Usuarios obtenidos'
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return ApiResponse::success(
            new UserResource($user),
            'Usuario creado correctamente',
            201
        );
    }

    public function show(int $user): JsonResponse
    {
        return ApiResponse::success(
            new UserResource($this->userService->find($user)),
            'Usuario obtenido'
        );
    }

    public function update(UpdateUserRequest $request, int $user): JsonResponse
    {
        $model = $this->userService->find($user);
        $updated = $this->userService->update($model, $request->validated());

        return ApiResponse::success(
            new UserResource($updated),
            'Usuario actualizado correctamente'
        );
    }

    public function destroy(int $user): JsonResponse
    {
        $model = $this->userService->find($user);
        $this->userService->delete($model);

        return ApiResponse::success(null, 'Usuario eliminado correctamente');
    }

    public function assignRoles(AssignRolesRequest $request, int $user): JsonResponse
    {
        $model = $this->userService->find($user);
        $updated = $this->userService->syncRoles($model, $request->validated('roles'));

        return ApiResponse::success(
            new UserResource($updated),
            'Roles del usuario sincronizados'
        );
    }
}
