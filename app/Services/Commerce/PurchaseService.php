<?php

namespace App\Services\Commerce;

use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $order = PurchaseOrder::query()->create([
                'number' => $this->number(),
                'supplier_id' => $data['supplier_id'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'user_id' => $actor->id,
            ]);

            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'ingredient_id' => $item['ingredient_id'],
                    'quantity_ordered' => $item['quantity_ordered'],
                    'quantity_received' => 0,
                    'unit_cost' => $item['unit_cost'] ?? 0,
                ]);
            }

            return $order->load(['supplier', 'items.ingredient.unit', 'user']);
        });
    }

    public function markOrdered(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['Solo una orden en borrador puede marcarse como pedida.'],
            ]);
        }

        $order->update([
            'status' => 'ordered',
            'ordered_at' => now(),
        ]);

        return $order->fresh(['supplier', 'items.ingredient.unit', 'user']);
    }

    /**
     * @param  list<array{id: int, quantity: mixed, lot_code?: string|null, expires_on?: string|null}>  $lines
     */
    public function receive(PurchaseOrder $order, array $lines, User $actor): PurchaseOrder
    {
        if (! in_array($order->status, ['ordered', 'partial'], true)) {
            throw ValidationException::withMessages([
                'status' => ['La orden debe estar pedida antes de recibir mercancía.'],
            ]);
        }

        return DB::transaction(function () use ($order, $lines, $actor) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            foreach ($lines as $line) {
                $item = PurchaseOrderItem::query()
                    ->where('purchase_order_id', $order->id)
                    ->lockForUpdate()
                    ->find($line['id'] ?? 0);

                if (! $item) {
                    throw ValidationException::withMessages([
                        'items' => ['Una línea no pertenece a esta orden.'],
                    ]);
                }

                $quantity = Quantity::of($line['quantity'] ?? 0);
                if (! Quantity::gt($quantity, 0)) {
                    continue;
                }

                $this->inventory->receiveIngredient($item->ingredient_id, $quantity, [
                    'user_id' => $actor->id,
                    'reason' => 'Recepción de orden '.$order->number,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $order->id,
                    'lot_code' => $line['lot_code'] ?? null,
                    'expires_on' => $line['expires_on'] ?? null,
                    'unit_cost' => $item->unit_cost,
                    'idempotency_key' => $line['idempotency_key'] ?? null,
                ]);

                $item->quantity_received = Quantity::add($item->quantity_received, $quantity);
                $item->save();

                Ingredient::query()->whereKey($item->ingredient_id)->update([
                    'cost_per_unit' => $item->unit_cost,
                ]);
            }

            $order->load('items');
            $allReceived = $order->items->every(
                fn (PurchaseOrderItem $item) => Quantity::gte($item->quantity_received, $item->quantity_ordered)
            );
            $anyReceived = $order->items->contains(
                fn (PurchaseOrderItem $item) => Quantity::gt($item->quantity_received, 0)
            );

            $order->status = $allReceived ? 'received' : ($anyReceived ? 'partial' : $order->status);
            if ($allReceived) {
                $order->received_at = now();
            }
            $order->save();

            return $order->fresh(['supplier', 'items.ingredient.unit', 'user']);
        });
    }

    private function number(): string
    {
        do {
            $number = 'OC-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (PurchaseOrder::query()->where('number', $number)->exists());

        return $number;
    }
}
