<?php

namespace App\Services\Commerce;

use App\Models\DisplayCase;
use App\Models\Ingredient;
use App\Models\IngredientLot;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Support\Carbon;

class ReportService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(?string $from, ?string $to, bool $includeEmployees): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->startOfMonth();
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        return [
            'currency' => $this->settings->all()['currency'] ?? 'COP',
            'period' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
            ],
            'sales_today' => $this->salesBetween(now()->startOfDay(), now()->endOfDay()),
            'sales_month' => $this->salesBetween(now()->startOfMonth(), now()->endOfMonth()),
            'period_sales' => $this->salesBetween($start, $end),
            'pending_orders' => Order::query()->whereIn('status', ['pending', 'confirmed', 'preparing', 'ready'])->count(),
            'top_products' => $this->topProducts($start, $end),
            'sales_by_channel' => $this->salesByChannel($start, $end),
            'sales_series' => $this->series($start, $end),
            'inventory_alerts' => $this->inventoryAlerts(),
            'out_of_stock' => $this->outOfStock(),
            'display_cases' => $this->caseSummary(),
            'recent_activity' => $this->activity(),
            'employees' => $includeEmployees ? $this->employees($start, $end) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salesReport(Carbon $start, Carbon $end): array
    {
        return [
            'summary' => $this->salesBetween($start, $end),
            'by_product' => $this->topProducts($start, $end, 100),
            'by_channel' => $this->salesByChannel($start, $end),
            'series' => $this->series($start, $end),
            'margins' => $this->margins($start, $end),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryReport(int $expiringDays): array
    {
        return [
            'ingredients' => Ingredient::query()->with('unit')->orderBy('name')->get()->map(fn (Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'unit' => $ingredient->unit?->code,
                'stock_quantity' => (string) $ingredient->stock_quantity,
                'min_stock' => (string) $ingredient->min_stock,
                'below_minimum' => Quantity::gt($ingredient->min_stock, 0) && Quantity::lte($ingredient->stock_quantity, $ingredient->min_stock),
            ]),
            'finished' => Product::query()
                ->where('is_demonstration', false)
                ->withSum('displayCaseItems as case_portions', 'quantity')
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'warehouse_portions' => (string) $product->warehouse_portions,
                    'case_portions' => Quantity::of($product->case_portions ?? 0),
                    'available_portions' => $product->availablePortions(),
                    'availability' => $product->availability(),
                ]),
            'expiring_lots' => IngredientLot::query()
                ->with('ingredient')
                ->where('quantity', '>', 0)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', now()->addDays($expiringDays)->toDateString())
                ->orderBy('expires_on')
                ->get()
                ->map(fn (IngredientLot $lot) => [
                    'id' => $lot->id,
                    'ingredient' => $lot->ingredient?->name,
                    'lot_code' => $lot->lot_code,
                    'quantity' => (string) $lot->quantity,
                    'expires_on' => $lot->expires_on?->toDateString(),
                ]),
            'waste' => StockMovement::query()
                ->where('movement_type', 'waste')
                ->latest()
                ->limit(100)
                ->get(),
            'display_cases' => $this->caseSummary(),
        ];
    }

    /**
     * @return array{total: string, orders: int, average_ticket: string|null}
     */
    private function salesBetween(Carbon $start, Carbon $end): array
    {
        $query = $this->recognizedSales()->whereBetween('created_at', [$start, $end]);
        $orders = (clone $query)->count();
        $total = Quantity::money((clone $query)->sum('total'));

        return [
            'total' => $total,
            'orders' => $orders,
            'average_ticket' => $orders > 0 ? Quantity::div($total, $orders, 2) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topProducts(Carbon $start, Carbon $end, int $limit = 5): array
    {
        return OrderItem::query()
            ->selectRaw('product_id, name, SUM(quantity) as quantity, SUM(portions) as portions, SUM(line_total) as revenue')
            ->whereHas('order', fn ($order) => $this->recognizedSales($order)->whereBetween('created_at', [$start, $end]))
            ->groupBy('product_id', 'name')
            ->orderByDesc('quantity')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id,
                'name' => $row->name,
                'quantity' => (int) $row->quantity,
                'portions' => Quantity::of($row->portions),
                'revenue' => Quantity::money($row->revenue),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function salesByChannel(Carbon $start, Carbon $end): array
    {
        return $this->recognizedSales()
            ->selectRaw('channel, COUNT(*) as orders, SUM(total) as total')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('channel')
            ->get()
            ->map(fn ($row) => [
                'channel' => $row->channel,
                'orders' => (int) $row->orders,
                'total' => Quantity::money($row->total),
            ])
            ->all();
    }

    /**
     * @return list<array{date: string, total: string, orders: int}>
     */
    private function series(Carbon $start, Carbon $end): array
    {
        $rows = $this->recognizedSales()
            ->selectRaw('DATE(created_at) as day, SUM(total) as total, COUNT(*) as orders')
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $points = [];
        $cursor = $start->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $points[] = [
                'date' => $key,
                'total' => Quantity::money($row->total ?? 0),
                'orders' => (int) ($row->orders ?? 0),
            ];
            $cursor->addDay();
        }

        return $points;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function margins(Carbon $start, Carbon $end): array
    {
        $products = Product::query()->with(['recipes.items.ingredient'])->where('is_demonstration', false)->get();
        $sales = collect($this->topProducts($start, $end, 100))->keyBy('product_id');
        $rows = [];

        foreach ($products as $product) {
            $sale = $sales->get($product->id);
            if (! $sale) {
                continue;
            }

            $recipe = $product->recipes->firstWhere('is_active', true);
            $complete = $recipe && $recipe->items->isNotEmpty() && $recipe->items->every(
                fn ($item) => Quantity::gt($item->ingredient->cost_per_unit, 0, 4)
            );

            if (! $complete || Quantity::lte($recipe->yield_quantity, 0)) {
                $rows[] = [
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'revenue' => $sale['revenue'],
                    'estimated_cost' => null,
                    'margin' => null,
                    'margin_available' => false,
                    'reason' => 'Faltan costos de ingredientes o una receta activa.',
                ];

                continue;
            }

            $batchCost = '0.0000';
            foreach ($recipe->items as $item) {
                $batchCost = Quantity::add($batchCost, Quantity::mul($item->quantity, $item->ingredient->cost_per_unit, 4), 4);
            }
            $costPerPortion = Quantity::div($batchCost, $recipe->yield_quantity, 4);
            $estimated = Quantity::money(Quantity::mul($costPerPortion, $sale['portions'], 4));
            $rows[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'revenue' => $sale['revenue'],
                'estimated_cost' => $estimated,
                'margin' => Quantity::sub($sale['revenue'], $estimated, 2),
                'margin_available' => true,
                'reason' => null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventoryAlerts(): array
    {
        return Ingredient::query()
            ->with('unit')
            ->where('is_active', true)
            ->where('min_stock', '>', 0)
            ->get()
            ->filter(fn (Ingredient $ingredient) => Quantity::lte($ingredient->stock_quantity, $ingredient->min_stock))
            ->map(fn (Ingredient $ingredient) => [
                'id' => $ingredient->id,
                'name' => $ingredient->name,
                'stock_quantity' => (string) $ingredient->stock_quantity,
                'min_stock' => (string) $ingredient->min_stock,
                'unit' => $ingredient->unit?->code,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outOfStock(): array
    {
        return Product::query()
            ->where('is_active', true)
            ->where('is_demonstration', false)
            ->withSum('displayCaseItems as case_portions', 'quantity')
            ->get()
            ->filter(fn (Product $product) => $product->availability() === 'out')
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'available_portions' => $product->availablePortions(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function caseSummary(): array
    {
        return DisplayCase::query()
            ->with(['items', 'openAlerts', 'latestReading'])
            ->withSum('items as used_portions', 'quantity')
            ->get()
            ->map(function (DisplayCase $case) {
                $used = Quantity::of($case->used_portions ?? 0);

                return [
                    'id' => $case->id,
                    'name' => $case->name,
                    'code' => $case->code,
                    'status' => $this->caseStatus($case, $used),
                    'used_portions' => $used,
                    'capacity_portions' => $case->capacity_portions ? (string) $case->capacity_portions : null,
                    'needs_cleaning' => $case->needs_cleaning,
                    'open_alerts' => $case->openAlerts->count(),
                    'latest_temperature' => $case->latestReading ? [
                        'celsius' => (string) $case->latestReading->celsius,
                        'recorded_at' => $case->latestReading->recorded_at,
                        'source' => $case->latestReading->source,
                    ] : null,
                    'range_configured' => $case->temp_min !== null && $case->temp_max !== null,
                ];
            })
            ->all();
    }

    private function caseStatus(DisplayCase $case, string $used): string
    {
        if ($case->openAlerts->isNotEmpty()) {
            return 'temperature';
        }
        if ($case->needs_cleaning) {
            return 'cleaning';
        }
        if ($case->capacity_portions !== null && Quantity::gt($used, $case->capacity_portions)) {
            return 'over_capacity';
        }
        if (Quantity::lte($used, 0)) {
            return 'empty';
        }
        if (Quantity::gt($case->min_threshold_portions, 0) && $case->items->contains(
            fn ($item) => Quantity::gt($item->quantity, 0) && Quantity::lte($item->quantity, $case->min_threshold_portions)
        )) {
            return 'low';
        }

        return 'available';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activity(): array
    {
        $orderStatus = [
            'pending' => 'pendiente',
            'confirmed' => 'confirmado',
            'preparing' => 'en preparación',
            'ready' => 'listo para entregar',
            'dispatched' => 'despachado',
            'completed' => 'completado',
            'cancelled' => 'cancelado',
        ];

        $orders = Order::query()->latest()->limit(8)->get()->map(fn (Order $order) => [
            'type' => 'order',
            'at' => $order->created_at,
            'description' => 'Pedido '.$order->number.' '.($orderStatus[$order->status] ?? $order->status),
        ]);

        $movements = StockMovement::query()->latest()->limit(8)->get();
        $products = Product::withTrashed()
            ->whereIn('id', $movements->where('item_type', 'finished')->pluck('item_id'))
            ->pluck('name', 'id');
        $ingredients = Ingredient::query()
            ->whereIn('id', $movements->where('item_type', 'ingredient')->pluck('item_id'))
            ->pluck('name', 'id');

        $movements = $movements->map(function (StockMovement $movement) use ($products, $ingredients) {
            $name = $movement->item_type === 'finished'
                ? ($products[$movement->item_id] ?? 'helado')
                : ($ingredients[$movement->item_id] ?? 'ingrediente');
            $amount = $this->plainQuantity($movement->quantity);
            $portion = $movement->item_type === 'finished'
                ? ($amount === '1' ? 'porción' : 'porciones')
                : ($amount === '1' ? 'unidad' : 'unidades');

            $sentence = match ($movement->movement_type) {
                'sale' => "Se vendió {$amount} {$portion} de {$name}",
                'sale_reversal' => "Devolución de {$amount} {$portion} de {$name}",
                'waste' => "Merma de {$amount} {$portion} de {$name}",
                'receipt' => "Entraron {$amount} {$portion} de {$name} al almacén",
                'transfer_out' => "Salieron {$amount} {$portion} de {$name} hacia la vitrina",
                'transfer_in' => "Llegaron {$amount} {$portion} de {$name} a la vitrina",
                'production_output' => "Se produjeron {$amount} {$portion} de {$name}",
                'production_consume' => "Se usaron {$amount} {$portion} de {$name} en producción",
                'adjustment' => "Ajuste de {$amount} {$portion} de {$name}",
                default => "Movimiento de {$amount} {$portion} de {$name}",
            };

            return [
                'type' => 'inventory',
                'at' => $movement->created_at,
                'description' => $sentence,
            ];
        });

        return $orders->concat($movements)->sortByDesc('at')->take(10)->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employees(Carbon $start, Carbon $end): array
    {
        $rows = $this->recognizedSales()
            ->where('channel', 'pos')
            ->whereNotNull('employee_id')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('employee_id, COUNT(*) as orders, SUM(total) as total')
            ->groupBy('employee_id')
            ->get();

        $users = User::query()->whereIn('id', $rows->pluck('employee_id'))->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'employee_id' => $row->employee_id,
            'name' => $users[$row->employee_id]->name ?? 'Empleado',
            'orders' => (int) $row->orders,
            'total' => Quantity::money($row->total),
        ])->all();
    }

    private function recognizedSales(?\Illuminate\Database\Eloquent\Builder $query = null): \Illuminate\Database\Eloquent\Builder
    {
        $query ??= Order::query();

        return $query->where('payment_status', 'paid')->where('status', '!=', 'cancelled');
    }

    private function plainQuantity(mixed $value): string
    {
        $number = (float) $value;
        if (abs($number - round($number)) < 0.0001) {
            return (string) (int) round($number);
        }

        return rtrim(rtrim(number_format($number, 3, ',', ''), '0'), ',');
    }
}
