<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderWorkflowService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function transition(Order $order, string $to, User $actor, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $to, $actor, $note) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($to, Order::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['El estado no existe.'],
                ]);
            }

            $allowed = Order::TRANSITIONS[$order->status] ?? [];
            if (! in_array($to, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ['Esa transición no está permitida desde el estado actual.'],
                ]);
            }

            if ($to === 'cancelled' && ! $actor->can('orders.cancel')) {
                throw ValidationException::withMessages([
                    'status' => ['No tienes permiso para anular pedidos.'],
                ]);
            }

            if ($to !== 'cancelled' && ! $actor->can('orders.update') && ! $actor->can('pos.access')) {
                throw ValidationException::withMessages([
                    'status' => ['No tienes permiso para actualizar el pedido.'],
                ]);
            }

            $from = $order->status;
            $order->status = $to;

            if ($to === 'cancelled') {
                $this->reverseInventory($order, $actor, 'cancel-order-'.$order->id);
            }

            $order->save();

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'user_id' => $actor->id,
                'note' => $note,
                'created_at' => now(),
            ]);

            return $order->fresh(['items', 'histories.user', 'paymentMethod', 'employee']);
        });
    }

    public function markPayment(Order $order, string $paymentStatus, User $actor): Order
    {
        if (! in_array($paymentStatus, ['unpaid', 'paid'], true)) {
            throw ValidationException::withMessages([
                'payment_status' => ['El estado de pago no es válido.'],
            ]);
        }

        if ($order->payment_status === 'refunded') {
            throw ValidationException::withMessages([
                'payment_status' => ['Un pedido devuelto no vuelve a marcarse como pagado desde aquí.'],
            ]);
        }

        $order->payment_status = $paymentStatus;
        $order->save();

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'user_id' => $actor->id,
            'note' => $paymentStatus === 'paid'
                ? 'Pago confirmado por el personal. No proviene de una pasarela.'
                : 'Pago marcado como pendiente.',
            'created_at' => now(),
        ]);

        return $order->fresh(['items', 'histories.user', 'paymentMethod', 'employee']);
    }

    public function refund(Order $order, User $actor, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $note) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status === 'cancelled' || $order->payment_status === 'refunded') {
                throw ValidationException::withMessages([
                    'order' => ['Este pedido ya fue anulado o devuelto.'],
                ]);
            }

            if (! $actor->can('pos.refund') && ! $actor->can('orders.cancel')) {
                throw ValidationException::withMessages([
                    'order' => ['No tienes permiso para registrar la devolución.'],
                ]);
            }

            $this->reverseInventory($order, $actor, 'refund-order-'.$order->id);
            $order->payment_status = 'refunded';
            if ($order->status !== 'completed') {
                $from = $order->status;
                $order->status = 'cancelled';
            } else {
                $from = $order->status;
            }
            $order->save();

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $order->status,
                'user_id' => $actor->id,
                'note' => $note ?: 'Devolución registrada. El inventario volvió al origen de la venta.',
                'created_at' => now(),
            ]);

            return $order->fresh(['items', 'histories.user', 'paymentMethod', 'employee']);
        });
    }

    public function cancelOwnPending(Order $order, User $customer): Order
    {
        if ((int) $order->user_id !== (int) $customer->id) {
            abort(404);
        }

        if (! in_array($order->status, ['pending', 'confirmed'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Este pedido ya no se puede cancelar desde la cuenta.'],
            ]);
        }

        return DB::transaction(function () use ($order, $customer) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $from = $locked->status;
            if (! in_array($from, ['pending', 'confirmed'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Este pedido ya no se puede cancelar desde la cuenta.'],
                ]);
            }

            $this->reverseInventory($locked, $customer, 'customer-cancel-'.$locked->id);
            $locked->status = 'cancelled';
            $locked->save();

            OrderStatusHistory::query()->create([
                'order_id' => $locked->id,
                'from_status' => $from,
                'to_status' => 'cancelled',
                'user_id' => $customer->id,
                'note' => 'Cancelado por el cliente.',
                'created_at' => now(),
            ]);

            return $locked->fresh(['items', 'histories.user', 'paymentMethod']);
        });
    }

    private function reverseInventory(Order $order, User $actor, string $key): void
    {
        if ($order->inventory_reversed_at) {
            return;
        }

        $order->loadMissing('items');
        foreach ($order->items as $index => $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = Product::withTrashed()->find($item->product_id);
            if (! $product) {
                continue;
            }

            $this->inventory->restoreFinished(
                $product,
                $item->allocations ?: [[
                    'location' => 'warehouse',
                    'location_id' => null,
                    'portions' => (string) $item->portions,
                ]],
                [
                    'user_id' => $actor->id,
                    'reason' => 'Reversión de inventario por anulación o devolución',
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'idempotency_key' => $key.':item:'.$item->id,
                ]
            );
        }

        $order->inventory_reversed_at = now();
    }
}
