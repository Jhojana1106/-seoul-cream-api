<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\CashSession;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\OrderWorkflowService;
use App\Services\Commerce\ReportService;
use App\Services\Commerce\SettingService;
use App\Support\ApiResponse;
use App\Support\CheckoutRules;
use App\Support\Quantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommerceAdminController extends Controller
{
    public function __construct(
        private readonly OrderWorkflowService $workflow,
        private readonly CheckoutService $checkout,
        private readonly ReportService $reports,
        private readonly SettingService $settings,
    ) {}

    public function orders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with(['items', 'paymentMethod', 'employee'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($inner) use ($search) {
                    $inner->where('number', 'like', "%{$search}%")
                        ->orWhere('customer_name', 'like', "%{$search}%")
                        ->orWhere('customer_email', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 20))));

        return ApiResponse::paginated(OrderResource::collection($orders), 'Pedidos');
    }

    public function showOrder(string $number): JsonResponse
    {
        $order = Order::query()
            ->with(['items', 'histories.user', 'paymentMethod', 'employee', 'coupon'])
            ->where('number', $number)
            ->firstOrFail();

        return ApiResponse::success(new OrderResource($order), 'Pedido');
    }

    public function transition(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:280'],
        ]);

        return ApiResponse::success(
            new OrderResource($this->workflow->transition($order, $data['status'], $request->user(), $data['note'] ?? null)),
            'Estado actualizado'
        );
    }

    public function payment(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'payment_status' => ['required', Rule::in(['paid', 'unpaid'])],
        ]);

        return ApiResponse::success(
            new OrderResource($this->workflow->markPayment($order, $data['payment_status'], $request->user())),
            'Pago actualizado'
        );
    }

    public function refund(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:280']]);

        return ApiResponse::success(
            new OrderResource($this->workflow->refund($order, $request->user(), $data['note'] ?? null)),
            'Devolución registrada'
        );
    }

    public function posPaymentMethods(): JsonResponse
    {
        return ApiResponse::success(
            PaymentMethod::query()->where('is_active', true)->get()->filter(
                fn (PaymentMethod $method) => in_array('pos', $method->channels ?? [], true)
            )->values(),
            'Medios de pago de caja'
        );
    }

    public function currentCash(Request $request): JsonResponse
    {
        $session = CashSession::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        return ApiResponse::success($session ? $this->cashPayload($session) : null, 'Caja');
    }

    public function openCash(Request $request): JsonResponse
    {
        $data = $request->validate(['opening_amount' => ['required', 'numeric', 'min:0']]);
        $open = CashSession::query()->where('user_id', $request->user()->id)->where('status', 'open')->exists();
        if ($open) {
            throw ValidationException::withMessages([
                'cash' => ['Ya tienes una caja abierta.'],
            ]);
        }

        $session = CashSession::query()->create([
            'user_id' => $request->user()->id,
            'status' => 'open',
            'opening_amount' => $data['opening_amount'],
            'opened_at' => now(),
        ]);

        return ApiResponse::success($this->cashPayload($session), 'Caja abierta', 201);
    }

    public function closeCash(Request $request, CashSession $cashSession): JsonResponse
    {
        if ((int) $cashSession->user_id !== (int) $request->user()->id && ! $request->user()->can('reports.view')) {
            abort(403);
        }
        if ($cashSession->status !== 'open') {
            throw ValidationException::withMessages(['cash' => ['La caja ya está cerrada.']]);
        }

        $data = $request->validate([
            'closing_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $summary = $this->cashPayload($cashSession);
        $cashSession->update([
            'status' => 'closed',
            'closing_amount' => $data['closing_amount'],
            'expected_amount' => $summary['expected_cash'],
            'closed_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return ApiResponse::success($this->cashPayload($cashSession->fresh()), 'Caja cerrada');
    }

    public function sell(Request $request): JsonResponse
    {
        $session = CashSession::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();

        if (! $session) {
            throw ValidationException::withMessages([
                'cash' => ['Abre la caja antes de registrar una venta.'],
            ]);
        }

        $data = $request->validate(CheckoutRules::rules());
        $data['discount_authorized'] = $request->user()->can('pos.discount');
        if (! $data['discount_authorized'] && Quantity::gt($data['discount_amount'] ?? 0, 0, 2)) {
            throw ValidationException::withMessages([
                'discount_amount' => ['No tienes permiso para aplicar descuentos.'],
            ]);
        }

        $order = $this->checkout->place(
            $data,
            null,
            $request->user(),
            $session->id,
            $this->idempotency($request)
        );

        return ApiResponse::success(new OrderResource($order), 'Venta registrada', 201);
    }

    public function customers(Request $request): JsonResponse
    {
        $customers = User::query()
            ->role('customer')
            ->withSum(['orders as spent' => fn ($q) => $q->where('payment_status', 'paid')->where('status', '!=', 'cancelled')], 'total')
            ->withCount(['orders as orders_count' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->withMax(['orders as last_order_at' => fn ($q) => $q->where('status', '!=', 'cancelled')], 'created_at')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(min(100, max(1, (int) $request->integer('per_page', 20))));

        $customers->getCollection()->transform(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'orders_count' => (int) $user->orders_count,
            'spent' => Quantity::money($user->spent ?? 0),
            'last_order_at' => $user->last_order_at,
        ]);

        return ApiResponse::success($customers->items(), 'Clientes', 200, [
            'current_page' => $customers->currentPage(),
            'last_page' => $customers->lastPage(),
            'per_page' => $customers->perPage(),
            'total' => $customers->total(),
        ]);
    }

    public function customerOrders(User $customer): JsonResponse
    {
        if (! $customer->hasRole('customer')) {
            abort(404);
        }

        $orders = Order::query()->where('user_id', $customer->id)->with(['items', 'paymentMethod'])->latest()->limit(50)->get();

        return ApiResponse::success(OrderResource::collection($orders), 'Pedidos del cliente');
    }

    public function dashboard(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->reports->dashboard(
                $request->string('from')->toString() ?: null,
                $request->string('to')->toString() ?: null,
                $request->user()->can('reports.employees')
            ),
            'Tablero'
        );
    }

    public function salesReport(Request $request): JsonResponse
    {
        [$start, $end] = $this->range($request);

        return ApiResponse::success($this->reports->salesReport($start, $end), 'Reporte de ventas');
    }

    public function inventoryReport(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->integer('days', 7)));

        return ApiResponse::success($this->reports->inventoryReport($days), 'Reporte de inventario');
    }

    public function exportSales(Request $request): StreamedResponse
    {
        [$start, $end] = $this->range($request);
        $report = $this->reports->salesReport($start, $end);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['fecha', 'ventas', 'pedidos']);
            foreach ($report['series'] as $point) {
                fputcsv($out, [$point['date'], $point['total'], $point['orders']]);
            }
            fclose($out);
        }, 'ventas.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function showSettings(): JsonResponse
    {
        return ApiResponse::success($this->settings->all(), 'Configuración');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_name' => ['sometimes', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:80'],
            'hours' => ['nullable', 'array'],
            'hours.*.day' => ['required_with:hours', 'string', 'max:20'],
            'hours.*.opens' => ['nullable', 'date_format:H:i'],
            'hours.*.closes' => ['nullable', 'date_format:H:i'],
            'hours.*.closed' => ['sometimes', 'boolean'],
            'instagram' => ['nullable', 'string', 'max:80'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'pickup_enabled' => ['sometimes', 'boolean'],
            'delivery_enabled' => ['sometimes', 'boolean'],
            'delivery_fee' => ['nullable', 'numeric', 'min:0'],
            'faq' => ['nullable', 'array'],
            'faq.*.question' => ['required_with:faq', 'string', 'max:180'],
            'faq.*.answer' => ['required_with:faq', 'string', 'max:800'],
            'policies' => ['nullable', 'string'],
            'about' => ['nullable', 'string'],
            'mission' => ['nullable', 'string', 'max:2000'],
            'vision' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success($this->settings->update($data), 'Configuración actualizada');
    }

    public function coupons(): JsonResponse
    {
        return ApiResponse::success(Coupon::query()->latest()->get(), 'Cupones');
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('coupons', 'code')],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'numeric', 'gt:0'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::success(Coupon::query()->create($data), 'Cupón creado', 201);
    }

    public function updateCoupon(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'ends_at' => ['nullable', 'date'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
        ]);
        $coupon->update($data);

        return ApiResponse::success($coupon->fresh(), 'Cupón actualizado');
    }

    public function paymentMethods(): JsonResponse
    {
        return ApiResponse::success(PaymentMethod::query()->orderBy('name')->get(), 'Medios de pago');
    }

    public function updatePaymentMethod(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'channels' => ['sometimes', 'array', 'min:1'],
            'channels.*' => ['in:online,pos'],
        ]);
        $paymentMethod->update($data);

        return ApiResponse::success($paymentMethod->fresh(), 'Medio de pago actualizado');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from'))->startOfDay() : now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to'))->endOfDay() : now()->endOfDay();

        return [$start, $end];
    }

    /**
     * @return array<string, mixed>
     */
    private function cashPayload(CashSession $session): array
    {
        $orders = Order::query()
            ->with('paymentMethod')
            ->where('cash_session_id', $session->id)
            ->where('status', '!=', 'cancelled')
            ->get();
        $paidCash = $orders
            ->filter(fn (Order $order) => $order->payment_status === 'paid' && $order->paymentMethod?->code === 'cash')
            ->sum('total');

        return [
            'id' => $session->id,
            'status' => $session->status,
            'opening_amount' => $session->opening_amount,
            'closing_amount' => $session->closing_amount,
            'opened_at' => $session->opened_at,
            'closed_at' => $session->closed_at,
            'notes' => $session->notes,
            'orders' => $orders->count(),
            'sales_total' => Quantity::money($orders->where('payment_status', 'paid')->sum('total')),
            'expected_cash' => Quantity::add($session->opening_amount, $paidCash, 2),
        ];
    }

    private function idempotency(Request $request): ?string
    {
        $key = substr(trim((string) $request->header('Idempotency-Key')), 0, 80);

        return $key !== '' ? $key : null;
    }
}
