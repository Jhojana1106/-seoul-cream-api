<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Permission\StorePermissionRequest;
use App\Http\Requests\Permission\UpdatePermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Services\AccessControl\PermissionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function __construct(
        private readonly PermissionService $permissionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $permissions = $this->permissionService->list(
            $request->string('search')->toString() ?: null,
            $request->string('module')->toString() ?: null,
            (int) $request->integer('per_page', 50)
        );

        return ApiResponse::paginated(
            PermissionResource::collection($permissions),
            'Permisos obtenidos'
        );
    }

    /**
     * Catálogo agrupado por módulo — ideal para el UI de asignación de permisos.
     */
    public function catalog(): JsonResponse
    {
        $catalog = $this->permissionService->catalog()->map(
            fn ($permissions) => PermissionResource::collection($permissions)
        );

        return ApiResponse::success($catalog, 'Catálogo de permisos');
    }

    public function store(StorePermissionRequest $request): JsonResponse
    {
        $permission = $this->permissionService->create($request->validated());

        return ApiResponse::success(
            new PermissionResource($permission),
            'Permiso creado correctamente',
            201
        );
    }

    public function show(int $permission): JsonResponse
    {
        return ApiResponse::success(
            new PermissionResource($this->permissionService->find($permission)),
            'Permiso obtenido'
        );
    }

    public function update(UpdatePermissionRequest $request, int $permission): JsonResponse
    {
        $model = $this->permissionService->find($permission);
        $updated = $this->permissionService->update($model, $request->validated());

        return ApiResponse::success(
            new PermissionResource($updated),
            'Permiso actualizado correctamente'
        );
    }

    public function destroy(int $permission): JsonResponse
    {
        $model = $this->permissionService->find($permission);
        $this->permissionService->delete($model);

        return ApiResponse::success(null, 'Permiso eliminado correctamente');
    }
}
