<?php

namespace App\Services\Commerce;

use App\Models\Address;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function place(array $data, ?User $user, ?User $employee, ?int $cashSessionId, ?string $idempotencyKey): Order
    {
        return DB::transaction(function () use ($data, $user, $employee, $cashSessionId, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    if ($user && $existing->user_id && (int) $existing->user_id !== (int) $user->id) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Esta compra ya fue registrada con otra cuenta.'],
                        ]);
                    }

                    return $existing->load($this->relations());
                }
            }

            $channel = $employee ? 'pos' : 'online';
            $data['channel'] = $channel;
            $data = $this->hydrateAddress($data, $user);
            $ids = collect($data['items'] ?? [])->pluck('product_id')->filter()->unique()->sort()->values();
            if ($ids->isNotEmpty()) {
                Product::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            }
            $quote = $this->pricing->quote($data, true);
            $this->pricing->throwIfNotReady($quote);

            $address = $this->addressSnapshot($data, $user);
            $payment = $quote['payment_method'];
            $paid = (bool) $payment->records_as_paid;
            $status = $channel === 'pos' && $paid ? 'completed' : 'pending';

            $order = new Order([
                'number' => $this->number(),
                'user_id' => $user?->id,
                'employee_id' => $employee?->id,
                'cash_session_id' => $cashSessionId,
                'channel' => $channel,
                'fulfillment' => $data['fulfillment'],
                'status' => $status,
                'payment_status' => $paid ? 'paid' : 'unpaid',
                'payment_method_id' => $payment->id,
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'],
                'address' => $address,
                'subtotal' => $quote['subtotal'],
                'discount_total' => $quote['discount_total'],
                'shipping_total' => $quote['shipping_total'],
                'total' => $quote['total'],
                'coupon_id' => $quote['coupon_id'],
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);
            $order->save();

            foreach ($quote['items'] as $line) {
                $caseId = $channel === 'pos' ? ($line['display_case_id'] ? (int) $line['display_case_id'] : null) : null;
                $allocations = $this->inventory->consumeFinished(
                    $line['product'],
                    $line['portions'],
                    $caseId,
                    [
                        'user_id' => $employee?->id ?? $user?->id,
                        'reason' => $channel === 'pos' ? 'Venta en punto de venta' : 'Venta en línea',
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                    ]
                );

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'product_variant_id' => $line['variant_id'],
                    'name' => $line['name'],
                    'variant_name' => $line['variant_name'],
                    'quantity' => $line['quantity'],
                    'portions' => $line['portions'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'toppings' => $line['toppings'],
                    'allocations' => $allocations,
                    'special_instructions' => $line['special_instructions'],
                ]);
            }

            if ($quote['coupon_id']) {
                Coupon::query()->whereKey($quote['coupon_id'])->increment('used_count');
            }

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => $status,
                'user_id' => $employee?->id ?? $user?->id,
                'note' => $channel === 'pos' ? 'Venta registrada en caja.' : 'Pedido creado en la tienda.',
                'created_at' => now(),
            ]);

            return $order->load($this->relations());
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function hydrateAddress(array $data, ?User $user): array
    {
        if ($user && ! empty($data['address_id'])) {
            $address = Address::query()->where('user_id', $user->id)->find($data['address_id']);
            if (! $address) {
                throw ValidationException::withMessages([
                    'address_id' => ['La dirección no pertenece a tu cuenta.'],
                ]);
            }

            $data['address'] = [
                'line1' => $address->line1,
                'line2' => $address->line2,
                'city' => $address->city,
                'notes' => $address->notes,
                'recipient' => $address->recipient,
                'phone' => $address->phone,
            ];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function addressSnapshot(array $data, ?User $user): ?array
    {
        if (($data['fulfillment'] ?? null) !== 'delivery') {
            return null;
        }

        if (! empty($data['address']['recipient'])) {
            return [
                'recipient' => $data['address']['recipient'],
                'line1' => $data['address']['line1'] ?? null,
                'line2' => $data['address']['line2'] ?? null,
                'city' => $data['address']['city'] ?? null,
                'phone' => $data['address']['phone'] ?? $data['customer_phone'],
                'notes' => $data['address']['notes'] ?? null,
            ];
        }

        return [
            'recipient' => $data['customer_name'],
            'line1' => $data['address']['line1'] ?? null,
            'line2' => $data['address']['line2'] ?? null,
            'city' => $data['address']['city'] ?? null,
            'phone' => $data['customer_phone'],
            'notes' => $data['address']['notes'] ?? null,
        ];
    }

    private function number(): string
    {
        do {
            $number = 'SC-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return [
            'items',
            'histories.user',
            'paymentMethod',
            'coupon',
            'employee',
        ];
    }
}
