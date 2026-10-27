<?php

namespace App\Services\Commerce;

use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductionService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  array{batches: mixed, produced_at?: string|null, expires_on?: string|null, notes?: string|null, lot_code?: string|null}  $data
     */
    public function produce(Recipe $recipe, array $data, User $actor): ProductionBatch
    {
        return DB::transaction(function () use ($recipe, $data, $actor) {
            $recipe->load('items.ingredient', 'product');
            if (! $recipe->is_active) {
                throw ValidationException::withMessages([
                    'recipe_id' => ['La receta está inactiva.'],
                ]);
            }
            if (Quantity::lte($recipe->yield_quantity, 0)) {
                throw ValidationException::withMessages([
                    'recipe_id' => ['La receta no tiene un rendimiento configurado.'],
                ]);
            }
            if ($recipe->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'recipe_id' => ['La receta no tiene ingredientes.'],
                ]);
            }

            $batches = Quantity::of($data['batches']);
            if (! Quantity::gt($batches, 0)) {
                throw ValidationException::withMessages([
                    'batches' => ['Indica cuántas preparaciones vas a registrar.'],
                ]);
            }

            $costsComplete = true;
            $estimated = '0.0000';
            foreach ($recipe->items as $item) {
                $needed = Quantity::mul($item->quantity, $batches);
                if (Quantity::lte($item->ingredient->cost_per_unit, 0, 4)) {
                    $costsComplete = false;
                } else {
                    $estimated = Quantity::add($estimated, Quantity::mul($needed, $item->ingredient->cost_per_unit, 4), 4);
                }
            }

            $producedAt = isset($data['produced_at']) ? Carbon::parse($data['produced_at']) : now();
            $expiresOn = null;
            if ($recipe->shelf_life_hours) {
                $expiresOn = $producedAt->copy()->addHours($recipe->shelf_life_hours);
            } elseif (! empty($data['expires_on'])) {
                $expiresOn = Carbon::parse($data['expires_on']);
            }

            $quantity = Quantity::mul($recipe->yield_quantity, $batches);
            $lot = $data['lot_code'] ?? ('PR-'.$producedAt->format('ymd').'-'.Str::upper(Str::random(4)));

            $batch = ProductionBatch::query()->create([
                'recipe_id' => $recipe->id,
                'product_id' => $recipe->product_id,
                'batches' => $batches,
                'quantity_produced' => $quantity,
                'yield_unit' => $recipe->yield_unit,
                'produced_at' => $producedAt,
                'expires_on' => $expiresOn,
                'lot_code' => $lot,
                'user_id' => $actor->id,
                'notes' => $data['notes'] ?? null,
                'estimated_cost' => $costsComplete ? Quantity::money($estimated) : null,
                'costs_complete' => $costsComplete,
            ]);

            foreach ($recipe->items as $index => $item) {
                $needed = Quantity::mul($item->quantity, $batches);
                $this->inventory->consumeIngredientQuantity($item->ingredient, $needed, [
                    'user_id' => $actor->id,
                    'reason' => 'Consumo por producción '.$batch->lot_code,
                    'reference_type' => 'production_batch',
                    'reference_id' => $batch->id,
                    'idempotency_key' => 'production-'.$batch->id.'-ingredient-'.$item->id,
                ], 'production_consume');
            }

            $this->inventory->addFinished($recipe->product, $quantity, [
                'user_id' => $actor->id,
                'reason' => 'Entrada de producto terminado '.$batch->lot_code,
                'reference_type' => 'production_batch',
                'reference_id' => $batch->id,
                'idempotency_key' => 'production-'.$batch->id.'-output',
            ]);

            return $batch->load(['recipe.items.ingredient', 'product', 'user']);
        });
    }
}
