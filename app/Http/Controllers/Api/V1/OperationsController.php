<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DisplayCase;
use App\Models\DisplayCaseEvent;
use App\Models\Ingredient;
use App\Models\IngredientLot;
use App\Models\ProductionBatch;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\TemperatureAlert;
use App\Models\TemperatureReading;
use App\Models\Unit;
use App\Services\Commerce\InventoryService;
use App\Services\Commerce\ProductionService;
use App\Services\Commerce\PurchaseService;
use App\Support\ApiResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ProductionService $production,
        private readonly PurchaseService $purchases,
    ) {}

    public function units(): JsonResponse
    {
        return ApiResponse::success(Unit::query()->where('is_active', true)->orderBy('name')->get(), 'Unidades');
    }

    public function ingredients(Request $request): JsonResponse
    {
        $query = Ingredient::query()->with('unit')->orderBy('name');
        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        return ApiResponse::success($query->get(), 'Ingredientes');
    }

    public function storeIngredient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('ingredients', 'sku')],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['stock_quantity'] = 0;

        return ApiResponse::success(Ingredient::query()->create($data)->load('unit'), 'Ingrediente creado', 201);
    }

    public function updateIngredient(Request $request, Ingredient $ingredient): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'unit_id' => ['sometimes', 'integer', Rule::exists('units', 'id')],
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('ingredients', 'sku')->ignore($ingredient->id)],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'cost_per_unit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $ingredient->update($data);

        return ApiResponse::success($ingredient->fresh('unit'), 'Ingrediente actualizado');
    }

    public function destroyIngredient(Ingredient $ingredient): JsonResponse
    {
        if (Quantity::gt($ingredient->stock_quantity, 0) || $ingredient->recipeItems()->exists()) {
            throw ValidationException::withMessages([
                'ingredient' => ['Desactívalo. Tiene existencia o está en una receta, y no se borra el stock en silencio.'],
            ]);
        }
        $ingredient->delete();

        return ApiResponse::success(null, 'Ingrediente eliminado');
    }

    public function kardex(Request $request): JsonResponse
    {
        $movements = StockMovement::query()
            ->with('user')
            ->when($request->filled('item_type'), fn ($q) => $q->where('item_type', $request->string('item_type')))
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', $request->integer('item_id')))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 30))));

        return ApiResponse::paginated(JsonResource::collection($movements), 'Kardex');
    }

    public function lots(Request $request): JsonResponse
    {
        $days = min(365, max(1, (int) $request->integer('days', 7)));
        $lots = IngredientLot::query()
            ->with('ingredient')
            ->where('quantity', '>', 0)
            ->when($request->boolean('expiring'), fn ($q) => $q->whereNotNull('expires_on')->whereDate('expires_on', '<=', now()->addDays($days)))
            ->orderBy('expires_on')
            ->get();

        return ApiResponse::success($lots, 'Lotes');
    }

    public function movement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_type' => ['required', Rule::in(['finished', 'ingredient'])],
            'item_id' => ['required', 'integer'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:240'],
            'lot_code' => ['nullable', 'string', 'max:80'],
            'expires_on' => ['nullable', 'date'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $audit = [
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'idempotency_key' => $this->key($request),
            'lot_code' => $data['lot_code'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? null,
        ];

        if ($data['direction'] === 'in' && $data['item_type'] === 'ingredient') {
            $movement = $this->inventory->receiveIngredient($data['item_id'], $data['quantity'], $audit);
        } elseif ($data['direction'] === 'in' && $data['item_type'] === 'finished') {
            $movement = $this->inventory->receiveFinished($data['item_id'], $data['quantity'], $audit);
        } else {
            $movement = $this->inventory->adjust($data['item_type'], $data['item_id'], 'out', $data['quantity'], $audit);
        }

        return ApiResponse::success($movement, 'Movimiento registrado', 201);
    }

    public function waste(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_type' => ['required', Rule::in(['finished', 'ingredient'])],
            'item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'display_case_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:240'],
        ]);

        $this->inventory->waste($data['item_type'], $data['item_id'], $data['quantity'], $data['display_case_id'] ?? null, [
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'idempotency_key' => $this->key($request),
        ]);

        return ApiResponse::success(null, 'Merma registrada', 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'from' => ['required', Rule::in(['warehouse', 'display_case'])],
            'from_id' => ['nullable', 'integer'],
            'to' => ['required', Rule::in(['warehouse', 'display_case'])],
            'to_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:240'],
        ]);

        $this->inventory->transfer(
            $data['product_id'],
            $data['quantity'],
            $data['from'],
            $data['from_id'] ?? null,
            $data['to'],
            $data['to_id'] ?? null,
            [
                'user_id' => $request->user()->id,
                'reason' => $data['reason'],
                'idempotency_key' => $this->key($request),
            ]
        );

        return ApiResponse::success(null, 'Transferencia registrada', 201);
    }

    public function cases(): JsonResponse
    {
        $cases = DisplayCase::query()
            ->with(['items.product', 'openAlerts', 'latestReading'])
            ->withSum('items as used_portions', 'quantity')
            ->orderBy('name')
            ->get()
            ->map(fn (DisplayCase $case) => $this->presentCase($case));

        return ApiResponse::success($cases, 'Vitrinas');
    }

    public function storeCase(Request $request): JsonResponse
    {
        $data = $this->caseData($request, true);

        return ApiResponse::success($this->presentCase(DisplayCase::query()->create($data)), 'Vitrina creada', 201);
    }

    public function updateCase(Request $request, DisplayCase $displayCase): JsonResponse
    {
        $displayCase->update($this->caseData($request, false));

        return ApiResponse::success($this->presentCase($displayCase->fresh(['items.product', 'openAlerts', 'latestReading'])), 'Vitrina actualizada');
    }

    public function destroyCase(DisplayCase $displayCase): JsonResponse
    {
        if (Quantity::gt($displayCase->items()->sum('quantity'), 0)) {
            throw ValidationException::withMessages([
                'display_case' => ['La vitrina todavía tiene producto. Transfiérelo antes de eliminarla.'],
            ]);
        }
        $displayCase->delete();

        return ApiResponse::success(null, 'Vitrina eliminada');
    }

    public function showCase(DisplayCase $displayCase): JsonResponse
    {
        $displayCase->load(['items.product', 'openAlerts.user', 'latestReading', 'events.user', 'readings' => fn ($q) => $q->limit(20)]);

        return ApiResponse::success([
            'case' => $this->presentCase($displayCase),
            'events' => $displayCase->events,
            'readings' => $displayCase->readings,
            'movements' => StockMovement::query()
                ->where('location', 'display_case')
                ->where('location_id', $displayCase->id)
                ->latest()
                ->limit(40)
                ->get(),
        ], 'Vitrina');
    }

    public function temperature(Request $request, DisplayCase $displayCase): JsonResponse
    {
        $data = $request->validate([
            'celsius' => ['required', 'numeric', 'between:-40,40'],
            'recorded_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:280'],
        ]);

        $reading = TemperatureReading::query()->create([
            'display_case_id' => $displayCase->id,
            'celsius' => $data['celsius'],
            'recorded_at' => $data['recorded_at'] ?? now(),
            'user_id' => $request->user()->id,
            'source' => 'manual',
            'notes' => $data['notes'] ?? null,
        ]);

        $alert = null;
        $range = $displayCase->temp_min !== null && $displayCase->temp_max !== null;
        if ($range && (Quantity::lt($data['celsius'], $displayCase->temp_min, 2) || Quantity::gt($data['celsius'], $displayCase->temp_max, 2))) {
            $alert = TemperatureAlert::query()->create([
                'display_case_id' => $displayCase->id,
                'temperature_reading_id' => $reading->id,
                'celsius' => $data['celsius'],
                'message' => 'Temperatura fuera del rango configurado.',
                'recorded_at' => $reading->recorded_at,
                'user_id' => $request->user()->id,
            ]);
        }

        return ApiResponse::success([
            'reading' => $reading,
            'alert' => $alert,
            'source' => 'manual',
            'range_configured' => $range,
        ], 'Temperatura registrada', 201);
    }

    public function acknowledgeAlert(Request $request, TemperatureAlert $alert): JsonResponse
    {
        $alert->update([
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->user()->id,
        ]);

        return ApiResponse::success($alert->fresh(), 'Alerta revisada');
    }

    public function caseEvent(Request $request, DisplayCase $displayCase): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['cleaning', 'maintenance'])],
            'performed_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $event = DisplayCaseEvent::query()->create([
            'display_case_id' => $displayCase->id,
            'type' => $data['type'],
            'performed_at' => $data['performed_at'] ?? now(),
            'user_id' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($data['type'] === 'cleaning') {
            $displayCase->update(['needs_cleaning' => false]);
        }

        return ApiResponse::success($event->load('user'), 'Evento registrado', 201);
    }

    public function recipes(): JsonResponse
    {
        return ApiResponse::success(
            Recipe::query()->with(['product', 'items.ingredient.unit'])->latest()->get(),
            'Recetas'
        );
    }

    public function storeRecipe(Request $request): JsonResponse
    {
        $data = $this->recipeData($request);

        $recipe = DB::transaction(function () use ($data) {
            $recipe = Recipe::query()->create(collect($data)->except('items')->all());
            $recipe->items()->createMany($data['items']);

            return $recipe->load(['product', 'items.ingredient.unit']);
        });

        return ApiResponse::success($recipe, 'Receta creada', 201);
    }

    public function updateRecipe(Request $request, Recipe $recipe): JsonResponse
    {
        $data = $this->recipeData($request, false);
        DB::transaction(function () use ($recipe, $data) {
            $recipe->update(collect($data)->except('items')->all());
            if (array_key_exists('items', $data)) {
                $recipe->items()->delete();
                $recipe->items()->createMany($data['items']);
            }
        });

        return ApiResponse::success($recipe->fresh(['product', 'items.ingredient.unit']), 'Receta actualizada');
    }

    public function destroyRecipe(Recipe $recipe): JsonResponse
    {
        if ($recipe->batches()->exists()) {
            $recipe->update(['is_active' => false]);

            return ApiResponse::success($recipe->fresh(), 'La receta tiene producción. Quedó inactiva.');
        }
        $recipe->delete();

        return ApiResponse::success(null, 'Receta eliminada');
    }

    public function produce(Request $request, Recipe $recipe): JsonResponse
    {
        $data = $request->validate([
            'batches' => ['required', 'numeric', 'gt:0'],
            'produced_at' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lot_code' => ['nullable', 'string', 'max:80'],
        ]);

        return ApiResponse::success(
            $this->production->produce($recipe, $data, $request->user()),
            'Producción registrada',
            201
        );
    }

    public function batches(): JsonResponse
    {
        return ApiResponse::success(
            ProductionBatch::query()->with(['product', 'recipe', 'user'])->latest('produced_at')->limit(100)->get(),
            'Producción'
        );
    }

    public function suppliers(): JsonResponse
    {
        return ApiResponse::success(Supplier::query()->orderBy('name')->get(), 'Proveedores');
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success(Supplier::query()->create($data), 'Proveedor creado', 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $supplier->update($data);

        return ApiResponse::success($supplier->fresh(), 'Proveedor actualizado');
    }

    public function destroySupplier(Supplier $supplier): JsonResponse
    {
        if ($supplier->purchaseOrders()->exists()) {
            throw ValidationException::withMessages([
                'supplier' => ['El proveedor tiene órdenes. Desactívalo en lugar de eliminarlo.'],
            ]);
        }
        $supplier->delete();

        return ApiResponse::success(null, 'Proveedor eliminado');
    }

    public function purchaseOrders(): JsonResponse
    {
        return ApiResponse::success(
            PurchaseOrder::query()->with(['supplier', 'items.ingredient', 'user'])->latest()->get(),
            'Compras'
        );
    }

    public function storePurchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')],
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        return ApiResponse::success($this->purchases->create($data, $request->user()), 'Orden creada', 201);
    }

    public function orderPurchase(PurchaseOrder $purchaseOrder): JsonResponse
    {
        return ApiResponse::success($this->purchases->markOrdered($purchaseOrder), 'Orden marcada como pedida');
    }

    public function receivePurchase(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.lot_code' => ['nullable', 'string', 'max:80'],
            'items.*.expires_on' => ['nullable', 'date'],
        ]);

        $key = $this->key($request);
        $lines = collect($data['items'])->map(function ($line) use ($key) {
            $line['idempotency_key'] = $key ? $key.'-'.$line['id'] : null;

            return $line;
        })->all();

        return ApiResponse::success($this->purchases->receive($purchaseOrder, $lines, $request->user()), 'Recepción registrada');
    }

    /**
     * @return array<string, mixed>
     */
    private function caseData(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:40', Rule::unique('display_cases', 'code')->ignore($request->route('displayCase')?->id)],
            'location' => ['nullable', 'string', 'max:120'],
            'capacity_liters' => ['nullable', 'numeric', 'min:0'],
            'capacity_portions' => ['nullable', 'numeric', 'min:0'],
            'min_threshold_portions' => ['nullable', 'numeric', 'min:0'],
            'temp_min' => ['nullable', 'numeric', 'between:-40,40'],
            'temp_max' => ['nullable', 'numeric', 'between:-40,40'],
            'needs_cleaning' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function recipeData(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'product_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('products', 'id')],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'yield_quantity' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0'],
            'yield_unit' => ['nullable', 'string', 'max:40'],
            'instructions' => ['nullable', 'string'],
            'shelf_life_hours' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'items' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer', Rule::exists('ingredients', 'id')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['required', 'string', 'max:40'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCase(DisplayCase $case): array
    {
        $case->loadMissing(['items.product', 'openAlerts', 'latestReading']);
        $used = Quantity::of($case->used_portions ?? $case->items->sum('quantity'));
        $status = 'available';
        if ($case->openAlerts->isNotEmpty()) {
            $status = 'temperature';
        } elseif ($case->needs_cleaning) {
            $status = 'cleaning';
        } elseif ($case->capacity_portions !== null && Quantity::gt($used, $case->capacity_portions)) {
            $status = 'over_capacity';
        } elseif (Quantity::lte($used, 0)) {
            $status = 'empty';
        } elseif (Quantity::gt($case->min_threshold_portions, 0) && $case->items->contains(
            fn ($item) => Quantity::gt($item->quantity, 0) && Quantity::lte($item->quantity, $case->min_threshold_portions)
        )) {
            $status = 'low';
        }

        return [
            'id' => $case->id,
            'name' => $case->name,
            'code' => $case->code,
            'location' => $case->location,
            'capacity_liters' => $case->capacity_liters,
            'capacity_portions' => $case->capacity_portions,
            'min_threshold_portions' => $case->min_threshold_portions,
            'temp_min' => $case->temp_min,
            'temp_max' => $case->temp_max,
            'range_configured' => $case->temp_min !== null && $case->temp_max !== null,
            'needs_cleaning' => $case->needs_cleaning,
            'notes' => $case->notes,
            'is_active' => $case->is_active,
            'used_portions' => $used,
            'free_portions' => $case->capacity_portions !== null ? Quantity::sub($case->capacity_portions, $used) : null,
            'status' => $status,
            'latest_temperature' => $case->latestReading ? [
                'celsius' => $case->latestReading->celsius,
                'recorded_at' => $case->latestReading->recorded_at,
                'source' => 'manual',
                'user_id' => $case->latestReading->user_id,
            ] : null,
            'open_alerts' => $case->openAlerts->map(fn ($alert) => [
                'id' => $alert->id,
                'celsius' => $alert->celsius,
                'message' => $alert->message,
                'recorded_at' => $alert->recorded_at,
                'user' => $alert->user?->name,
            ])->values(),
            'items' => $case->items->map(function ($item) use ($case) {
                $itemStatus = 'available';
                if (Quantity::lte($item->quantity, 0)) {
                    $itemStatus = 'out';
                } elseif (Quantity::gt($case->min_threshold_portions, 0) && Quantity::lte($item->quantity, $case->min_threshold_portions)) {
                    $itemStatus = 'low';
                }

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'quantity' => $item->quantity,
                    'container_label' => $item->container_label,
                    'status' => $itemStatus,
                ];
            })->values(),
        ];
    }

    private function key(Request $request): ?string
    {
        $key = substr(trim((string) $request->header('Idempotency-Key')), 0, 80);

        return $key !== '' ? $key : null;
    }
}
