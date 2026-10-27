<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUSES = [
        'pending',
        'confirmed',
        'preparing',
        'ready',
        'dispatched',
        'completed',
        'cancelled',
    ];

    public const TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['ready', 'cancelled'],
        'ready' => ['dispatched', 'completed', 'cancelled'],
        'dispatched' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'number',
        'user_id',
        'employee_id',
        'cash_session_id',
        'channel',
        'fulfillment',
        'status',
        'payment_status',
        'payment_method_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'address',
        'subtotal',
        'discount_total',
        'shipping_total',
        'total',
        'coupon_id',
        'notes',
        'idempotency_key',
        'inventory_reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'total' => 'decimal:2',
            'inventory_reversed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }
}
