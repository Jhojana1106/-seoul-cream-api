<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\SyncPermissionsRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\AccessControl\RoleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roleService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $roles = $this->roleService->list(
            $request->string('search')->toString() ?: null,
            (int) $request->integer('per_page', 15)
        );

        return ApiResponse::paginated(
            RoleResource::collection($roles),
            'Roles obtenidos'
        );
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return ApiResponse::success(
            new RoleResource($role),
            'Rol creado correctamente',
            201
        );
    }

    public function show(int $role): JsonResponse
    {
        return ApiResponse::success(
            new RoleResource($this->roleService->find($role)),
            'Rol obtenido'
        );
    }

    public function update(UpdateRoleRequest $request, int $role): JsonResponse
    {
        $model = $this->roleService->find($role);
        $updated = $this->roleService->update($model, $request->validated());

        return ApiResponse::success(
            new RoleResource($updated),
            'Rol actualizado correctamente'
        );
    }

    public function destroy(int $role): JsonResponse
    {
        $model = $this->roleService->find($role);
        $this->roleService->delete($model);

        return ApiResponse::success(null, 'Rol eliminado correctamente');
    }

    public function syncPermissions(SyncPermissionsRequest $request, int $role): JsonResponse
    {
        $model = $this->roleService->find($role);
        $updated = $this->roleService->syncPermissions($model, $request->validated('permissions'));

        return ApiResponse::success(
            new RoleResource($updated),
            'Permisos del rol sincronizados'
        );
    }
}
