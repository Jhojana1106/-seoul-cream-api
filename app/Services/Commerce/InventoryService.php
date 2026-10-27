<?php

namespace App\Services\Commerce;

use App\Models\DisplayCase;
use App\Models\DisplayCaseItem;
use App\Models\Ingredient;
use App\Models\IngredientLot;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * @param  array{user_id?: int|null, reason?: string|null, reference_type?: string|null, reference_id?: int|null, idempotency_key?: string|null}  $audit
     * @return list<array{location: string, location_id: int|null, portions: string}>
     */
    public function consumeFinished(Product $product, mixed $portions, ?int $preferredCaseId, array $audit): array
    {
        $needed = Quantity::of($portions);
        if (! Quantity::gt($needed, 0)) {
            throw ValidationException::withMessages([
                'quantity' => ['La cantidad a descontar debe ser mayor que cero.'],
            ]);
        }

        if ($preferredCaseId) {
            $taken = $this->takeFromCase($preferredCaseId, $product->id, $needed, $audit);
            if (Quantity::gt($taken['remaining'], 0)) {
                throw ValidationException::withMessages([
                    'stock' => ['La vitrina no tiene porciones suficientes de este sabor.'],
                ]);
            }

            return $taken['allocations'];
        }

        $product = $this->lockProduct($product->id);
        $fromWarehouse = Quantity::min($product->warehouse_portions, $needed);
        $allocations = [];

        if (Quantity::gt($fromWarehouse, 0)) {
            $product->warehouse_portions = Quantity::sub($product->warehouse_portions, $fromWarehouse);
            $product->save();
            $this->record('finished', $product->id, 'sale', $fromWarehouse, 'warehouse', null, $audit);
            $allocations[] = [
                'location' => 'warehouse',
                'location_id' => null,
                'portions' => $fromWarehouse,
            ];
        }

        $remaining = Quantity::sub($needed, $fromWarehouse);
        if (Quantity::gt($remaining, 0)) {
            $items = DisplayCaseItem::query()
                ->where('product_id', $product->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                if (! Quantity::gt($remaining, 0)) {
                    break;
                }

                $take = Quantity::min($item->quantity, $remaining);
                if (! Quantity::gt($take, 0)) {
                    continue;
                }

                $item->quantity = Quantity::sub($item->quantity, $take);
                $item->save();
                $this->record('finished', $product->id, 'sale', $take, 'display_case', $item->display_case_id, $audit);
                $allocations[] = [
                    'location' => 'display_case',
                    'location_id' => $item->display_case_id,
                    'portions' => $take,
                ];
                $remaining = Quantity::sub($remaining, $take);
            }
        }

        if (Quantity::gt($remaining, 0)) {
            throw ValidationException::withMessages([
                'stock' => ['No hay porciones suficientes para vender este sabor.'],
            ]);
        }

        return $allocations;
    }

    /**
     * @param  list<array{location: string, location_id: int|null, portions: string|float}>  $allocations
     * @param  array{user_id?: int|null, reason?: string|null, reference_type?: string|null, reference_id?: int|null, idempotency_key?: string|null}  $audit
     */
    public function restoreFinished(Product $product, array $allocations, array $audit): void
    {
        if ($audit['idempotency_key'] ?? null) {
            if (StockMovement::query()->where('idempotency_key', $audit['idempotency_key'])->exists()) {
                return;
            }
        }

        $product = $this->lockProduct($product->id);

        foreach ($allocations as $index => $allocation) {
            $portions = Quantity::of($allocation['portions'] ?? 0);
            if (! Quantity::gt($portions, 0)) {
                continue;
            }

            $movementAudit = $audit;
            if (($audit['idempotency_key'] ?? null) && $index > 0) {
                $movementAudit['idempotency_key'] = $audit['idempotency_key'].':'.$index;
            }

            if (($allocation['location'] ?? null) === 'display_case' && ! empty($allocation['location_id'])) {
                $case = DisplayCase::query()->lockForUpdate()->find($allocation['location_id']);
                if ($case) {
                    $item = DisplayCaseItem::query()
                        ->where('display_case_id', $case->id)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $item) {
                        $item = new DisplayCaseItem([
                            'display_case_id' => $case->id,
                            'product_id' => $product->id,
                            'quantity' => 0,
                        ]);
                    }

                    $item->quantity = Quantity::add($item->quantity, $portions);
                    $item->save();
                    $this->record('finished', $product->id, 'sale_reversal', $portions, 'display_case', $case->id, $movementAudit);

                    continue;
                }
            }

            $product->warehouse_portions = Quantity::add($product->warehouse_portions, $portions);
            $product->save();
            $this->record('finished', $product->id, 'sale_reversal', $portions, 'warehouse', null, $movementAudit);
        }
    }

    /**
     * @param  array{user_id?: int|null, reason?: string|null, idempotency_key?: string|null}  $audit
     */
    public function receiveFinished(int $productId, mixed $quantity, array $audit): StockMovement
    {
        return DB::transaction(function () use ($productId, $quantity, $audit) {
            $this->assertIdempotent($audit['idempotency_key'] ?? null);
            $amount = $this->positive($quantity, 'quantity');
            $product = $this->lockProduct($productId);
            $product->warehouse_portions = Quantity::add($product->warehouse_portions, $amount);
            $product->save();

            return $this->record('finished', $product->id, 'receipt', $amount, 'warehouse', null, $audit);
        });
    }

    /**
     * @param  array{user_id?: int|null, reason: string, idempotency_key?: string|null, lot_code?: string|null, expires_on?: string|null, unit_cost?: mixed}  $audit
     */
    public function receiveIngredient(int $ingredientId, mixed $quantity, array $audit): StockMovement
    {
        return DB::transaction(function () use ($ingredientId, $quantity, $audit) {
            $this->assertIdempotent($audit['idempotency_key'] ?? null);
            $amount = $this->positive($quantity, 'quantity');
            $ingredient = $this->lockIngredient($ingredientId);
            $ingredient->stock_quantity = Quantity::add($ingredient->stock_quantity, $amount);
            if (isset($audit['unit_cost']) && Quantity::gt($audit['unit_cost'], 0, 4)) {
                $ingredient->cost_per_unit = Quantity::of($audit['unit_cost'], 4);
            }
            $ingredient->save();

            if (! empty($audit['lot_code']) || ! empty($audit['expires_on'])) {
                IngredientLot::query()->create([
                    'ingredient_id' => $ingredient->id,
                    'lot_code' => $audit['lot_code'] ?: 'SIN-LOTE',
                    'quantity' => $amount,
                    'unit_cost' => Quantity::of($audit['unit_cost'] ?? $ingredient->cost_per_unit, 4),
                    'expires_on' => $audit['expires_on'] ?? null,
                    'received_at' => now(),
                ]);
            }

            return $this->record('ingredient', $ingredient->id, 'receipt', $amount, 'warehouse', null, $audit + [
                'lot_code' => $audit['lot_code'] ?? null,
                'expires_on' => $audit['expires_on'] ?? null,
            ]);
        });
    }

    /**
     * @param  array{user_id?: int|null, reason: string, idempotency_key?: string|null}  $audit
     */
    public function adjust(string $itemType, int $itemId, string $direction, mixed $quantity, array $audit): StockMovement
    {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw ValidationException::withMessages([
                'direction' => ['La dirección del ajuste debe ser entrada o salida.'],
            ]);
        }

        return DB::transaction(function () use ($itemType, $itemId, $direction, $quantity, $audit) {
            $this->assertIdempotent($audit['idempotency_key'] ?? null);
            $amount = $this->positive($quantity, 'quantity');

            if ($itemType === 'finished') {
                $product = $this->lockProduct($itemId);
                if ($direction === 'out') {
                    $this->guardEnough($product->warehouse_portions, $amount, 'No se puede dejar el almacén de producto terminado en negativo.');
                    $product->warehouse_portions = Quantity::sub($product->warehouse_portions, $amount);
                } else {
                    $product->warehouse_portions = Quantity::add($product->warehouse_portions, $amount);
                }
                $product->save();

                return $this->record('finished', $product->id, 'adjustment', $amount, 'warehouse', null, $audit);
            }

            if ($itemType !== 'ingredient') {
                throw ValidationException::withMessages([
                    'item_type' => ['El tipo de ítem no es válido.'],
                ]);
            }

            $ingredient = $this->lockIngredient($itemId);
            if ($direction === 'out') {
                $this->consumeIngredientQuantity($ingredient, $amount, $audit, 'adjustment');

                return StockMovement::query()->where('idempotency_key', $audit['idempotency_key'] ?? null)->first()
                    ?? StockMovement::query()->latest('id')->firstOrFail();
            }

            $ingredient->stock_quantity = Quantity::add($ingredient->stock_quantity, $amount);
            $ingredient->save();

            return $this->record('ingredient', $ingredient->id, 'adjustment', $amount, 'warehouse', null, $audit);
        });
    }

    /**
     * @param  array{user_id?: int|null, reason: string, idempotency_key?: string|null}  $audit
     */
    public function waste(string $itemType, int $itemId, mixed $quantity, ?int $displayCaseId, array $audit): void
    {
        DB::transaction(function () use ($itemType, $itemId, $quantity, $displayCaseId, $audit) {
            $this->assertIdempotent($audit['idempotency_key'] ?? null);
            $amount = $this->positive($quantity, 'quantity');

            if ($itemType === 'ingredient') {
                $ingredient = $this->lockIngredient($itemId);
                $this->consumeIngredientQuantity($ingredient, $amount, $audit, 'waste');

                return;
            }

            $product = $this->lockProduct($itemId);
            if ($displayCaseId) {
                $item = DisplayCaseItem::query()
                    ->where('display_case_id', $displayCaseId)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw ValidationException::withMessages([
                        'display_case_id' => ['Ese sabor no está en la vitrina.'],
                    ]);
                }

                $this->guardEnough($item->quantity, $amount, 'La merma supera la cantidad de la vitrina.');
                $item->quantity = Quantity::sub($item->quantity, $amount);
                $item->save();
                $this->record('finished', $product->id, 'waste', $amount, 'display_case', $displayCaseId, $audit);

                return;
            }

            $this->guardEnough($product->warehouse_portions, $amount, 'La merma supera el almacén de producto terminado.');
            $product->warehouse_portions = Quantity::sub($product->warehouse_portions, $amount);
            $product->save();
            $this->record('finished', $product->id, 'waste', $amount, 'warehouse', null, $audit);
        });
    }

    /**
     * @param  array{user_id?: int|null, reason: string, idempotency_key?: string|null}  $audit
     */
    public function transfer(int $productId, mixed $quantity, string $from, ?int $fromId, string $to, ?int $toId, array $audit): void
    {
        if ($from === $to && $fromId === $toId) {
            throw ValidationException::withMessages([
                'to' => ['El origen y el destino deben ser distintos.'],
            ]);
        }

        DB::transaction(function () use ($productId, $quantity, $from, $fromId, $to, $toId, $audit) {
            $this->assertIdempotent($audit['idempotency_key'] ?? null);
            $amount = $this->positive($quantity, 'quantity');
            $product = $this->lockProduct($productId);

            $this->decreaseLocation($product, $from, $fromId, $amount, $audit, 'transfer_out');
            $this->increaseLocation($product, $to, $toId, $amount, $audit, 'transfer_in');
        });
    }

    /**
     * @param  array{user_id?: int|null, reason?: string|null, reference_type?: string|null, reference_id?: int|null, idempotency_key?: string|null}  $audit
     */
    public function consumeIngredientQuantity(Ingredient $ingredient, mixed $quantity, array $audit, string $movementType): void
    {
        $amount = $this->positive($quantity, 'quantity');
        $ingredient = $ingredient->exists ? $this->lockIngredient($ingredient->id) : $ingredient;
        $this->guardEnough($ingredient->stock_quantity, $amount, 'El ingrediente no tiene existencia suficiente.');
        $ingredient->stock_quantity = Quantity::sub($ingredient->stock_quantity, $amount);
        $ingredient->save();

        $remaining = $amount;
        $lots = IngredientLot::query()
            ->where('ingredient_id', $ingredient->id)
            ->where('quantity', '>', 0)
            ->orderByRaw('CASE WHEN expires_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_on')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if (! Quantity::gt($remaining, 0)) {
                break;
            }

            $take = Quantity::min($lot->quantity, $remaining);
            $lot->quantity = Quantity::sub($lot->quantity, $take);
            $lot->save();
            $remaining = Quantity::sub($remaining, $take);
        }

        $this->record('ingredient', $ingredient->id, $movementType, $amount, 'warehouse', null, $audit);
    }

    public function addFinished(Product $product, mixed $quantity, array $audit, string $movementType = 'production_output'): void
    {
        $amount = $this->positive($quantity, 'quantity');
        $product = $this->lockProduct($product->id);
        $product->warehouse_portions = Quantity::add($product->warehouse_portions, $amount);
        $product->save();
        $this->record('finished', $product->id, $movementType, $amount, 'warehouse', null, $audit);
    }

    private function takeFromCase(int $caseId, int $productId, string $needed, array $audit): array
    {
        $case = DisplayCase::query()->lockForUpdate()->find($caseId);
        if (! $case) {
            throw ValidationException::withMessages([
                'display_case_id' => ['La vitrina no existe.'],
            ]);
        }

        $item = DisplayCaseItem::query()
            ->where('display_case_id', $case->id)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        $available = $item ? Quantity::of($item->quantity) : '0.000';
        if (Quantity::lt($available, $needed)) {
            return ['remaining' => Quantity::sub($needed, $available), 'allocations' => []];
        }

        $item->quantity = Quantity::sub($item->quantity, $needed);
        $item->save();
        $this->record('finished', $productId, 'sale', $needed, 'display_case', $case->id, $audit);

        return [
            'remaining' => '0.000',
            'allocations' => [[
                'location' => 'display_case',
                'location_id' => $case->id,
                'portions' => $needed,
            ]],
        ];
    }

    private function decreaseLocation(Product $product, string $location, ?int $locationId, string $amount, array $audit, string $type): void
    {
        if ($location === 'warehouse') {
            $this->guardEnough($product->warehouse_portions, $amount, 'El almacén no tiene porciones suficientes.');
            $product->warehouse_portions = Quantity::sub($product->warehouse_portions, $amount);
            $product->save();
            $this->record('finished', $product->id, $type, $amount, 'warehouse', null, $audit);

            return;
        }

        $item = $this->caseItem($locationId, $product->id);
        $this->guardEnough($item->quantity, $amount, 'La vitrina no tiene porciones suficientes.');
        $item->quantity = Quantity::sub($item->quantity, $amount);
        $item->save();
        $this->record('finished', $product->id, $type, $amount, 'display_case', $locationId, $audit);
    }

    private function increaseLocation(Product $product, string $location, ?int $locationId, string $amount, array $audit, string $type): void
    {
        if ($location === 'warehouse') {
            $product->warehouse_portions = Quantity::add($product->warehouse_portions, $amount);
            $product->save();
            $this->record('finished', $product->id, $type, $amount, 'warehouse', null, $this->withoutIdempotency($audit));

            return;
        }

        $case = DisplayCase::query()->lockForUpdate()->find($locationId);
        if (! $case || ! $case->is_active) {
            throw ValidationException::withMessages([
                'to_id' => ['La vitrina de destino no está disponible.'],
            ]);
        }

        $item = DisplayCaseItem::query()
            ->where('display_case_id', $case->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        $current = $item ? Quantity::of($item->quantity) : '0.000';
        $used = Quantity::of(DisplayCaseItem::query()->where('display_case_id', $case->id)->sum('quantity'));
        if ($item) {
            $used = Quantity::sub($used, $item->quantity);
        }

        if ($case->capacity_portions !== null) {
            $next = Quantity::add(Quantity::add($used, $current), $amount);
            if (Quantity::gt($next, $case->capacity_portions)) {
                throw ValidationException::withMessages([
                    'quantity' => ['La reposición supera la capacidad disponible de la vitrina.'],
                ]);
            }
        }

        if (! $item) {
            $item = new DisplayCaseItem([
                'display_case_id' => $case->id,
                'product_id' => $product->id,
            ]);
        }

        $item->quantity = Quantity::add($current, $amount);
        $item->save();
        $this->record('finished', $product->id, $type, $amount, 'display_case', $case->id, $this->withoutIdempotency($audit));
    }

    private function caseItem(?int $caseId, int $productId): DisplayCaseItem
    {
        if (! $caseId) {
            throw ValidationException::withMessages([
                'from_id' => ['Indica la vitrina.'],
            ]);
        }

        $item = DisplayCaseItem::query()
            ->where('display_case_id', $caseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw ValidationException::withMessages([
                'product_id' => ['Ese sabor no está asignado a la vitrina.'],
            ]);
        }

        return $item;
    }

    private function lockProduct(int $id): Product
    {
        $product = Product::query()->lockForUpdate()->find($id);
        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => ['El sabor no existe.'],
            ]);
        }

        return $product;
    }

    private function lockIngredient(int $id): Ingredient
    {
        $ingredient = Ingredient::query()->lockForUpdate()->find($id);
        if (! $ingredient) {
            throw ValidationException::withMessages([
                'ingredient_id' => ['El ingrediente no existe.'],
            ]);
        }

        return $ingredient;
    }

    private function positive(mixed $quantity, string $field): string
    {
        $amount = Quantity::of($quantity);
        if (! Quantity::gt($amount, 0)) {
            throw ValidationException::withMessages([
                $field => ['La cantidad debe ser mayor que cero.'],
            ]);
        }

        return $amount;
    }

    private function guardEnough(mixed $available, string $needed, string $message): void
    {
        if (Quantity::lt($available, $needed)) {
            throw ValidationException::withMessages([
                'quantity' => [$message],
            ]);
        }
    }

    private function assertIdempotent(?string $key): void
    {
        if ($key && StockMovement::query()->where('idempotency_key', $key)->exists()) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Este movimiento ya fue registrado.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $audit
     * @return array<string, mixed>
     */
    private function withoutIdempotency(array $audit): array
    {
        unset($audit['idempotency_key']);

        return $audit;
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function record(
        string $itemType,
        int $itemId,
        string $movementType,
        string $quantity,
        string $location,
        ?int $locationId,
        array $audit
    ): StockMovement {
        return StockMovement::query()->create([
            'item_type' => $itemType,
            'item_id' => $itemId,
            'movement_type' => $movementType,
            'quantity' => $quantity,
            'location' => $location,
            'location_id' => $locationId,
            'lot_code' => $audit['lot_code'] ?? null,
            'expires_on' => $audit['expires_on'] ?? null,
            'reason' => $audit['reason'] ?? null,
            'user_id' => $audit['user_id'] ?? null,
            'reference_type' => $audit['reference_type'] ?? null,
            'reference_id' => $audit['reference_id'] ?? null,
            'idempotency_key' => $audit['idempotency_key'] ?? null,
        ]);
    }
}
