<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ProductResource;
use App\Models\Address;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Topping;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\OrderWorkflowService;
use App\Services\Commerce\PricingService;
use App\Services\Commerce\ProductService;
use App\Services\Commerce\SettingService;
use App\Support\ApiResponse;
use App\Support\CheckoutRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StorefrontController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly PricingService $pricing,
        private readonly CheckoutService $checkout,
        private readonly SettingService $settings,
        private readonly OrderWorkflowService $orders,
    ) {}

    public function settings(): JsonResponse
    {
        return ApiResponse::success($this->settings->publicPayload(), 'Configuración pública');
    }

    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->where('is_demonstration', false)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return ApiResponse::success($categories, 'Categorías');
    }

    public function products(Request $request): JsonResponse
    {
        $page = $this->products->paginate($request->all(), true);

        return ApiResponse::paginated(ProductResource::collection($page), 'Catálogo');
    }

    public function product(string $slug): JsonResponse
    {
        return ApiResponse::success(
            new ProductResource($this->products->findPublic($slug)),
            'Sabor'
        );
    }

    public function toppings(): JsonResponse
    {
        return ApiResponse::success(
            Topping::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'Toppings'
        );
    }

    public function paymentMethods(): JsonResponse
    {
        return ApiResponse::success(
            PaymentMethod::query()->where('is_active', true)->get()->filter(
                fn (PaymentMethod $method) => in_array('online', $method->channels ?? [], true)
            )->values(),
            'Medios de pago'
        );
    }

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate(CheckoutRules::rules());
        $data['channel'] = 'online';
        $data['discount_authorized'] = false;
        $quote = $this->pricing->quote($data);
        unset($quote['payment_method']);

        return ApiResponse::success($quote, 'Cotización');
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate(CheckoutRules::rules());
        $data['discount_authorized'] = false;
        $order = $this->checkout->place(
            $data,
            $request->user(),
            null,
            null,
            $this->idempotency($request)
        );

        return ApiResponse::success(new OrderResource($order), 'Pedido creado', 201);
    }

    public function myOrders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->with(['items.product', 'paymentMethod', 'histories.user'])
            ->latest()
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 15))));

        return ApiResponse::paginated(OrderResource::collection($orders), 'Pedidos');
    }

    public function showOrder(Request $request, string $number): JsonResponse
    {
        $order = Order::query()
            ->with(['items.product', 'paymentMethod', 'histories.user', 'coupon'])
            ->where('number', $number)
            ->firstOrFail();

        $user = Auth::guard('api')->user();
        $email = mb_strtolower((string) $request->query('email', ''));
        $owns = $user && (int) $order->user_id === (int) $user->id;
        $staff = $user?->can('orders.view');
        $guest = $email !== '' && mb_strtolower((string) $order->customer_email) === $email;

        if (! $owns && ! $staff && ! $guest) {
            abort(404);
        }

        return ApiResponse::success(new OrderResource($order), 'Pedido');
    }

    public function cancelOrder(Request $request, string $number): JsonResponse
    {
        $order = Order::query()->where('number', $number)->where('user_id', $request->user()->id)->firstOrFail();

        return ApiResponse::success(
            new OrderResource($this->orders->cancelOwnPending($order, $request->user())),
            'Pedido cancelado'
        );
    }

    public function addresses(Request $request): JsonResponse
    {
        return ApiResponse::success(
            Address::query()->where('user_id', $request->user()->id)->latest()->get(),
            'Direcciones'
        );
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'recipient' => ['required', 'string', 'max:120'],
            'line1' => ['required', 'string', 'max:180'],
            'line2' => ['nullable', 'string', 'max:180'],
            'city' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:280'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $address = new Address($data);
        $address->user_id = $request->user()->id;
        if (! empty($data['is_default'])) {
            Address::query()->where('user_id', $request->user()->id)->update(['is_default' => false]);
        }
        $address->save();

        return ApiResponse::success($address, 'Dirección creada', 201);
    }

    public function updateAddress(Request $request, int $address): JsonResponse
    {
        $model = Address::query()->where('user_id', $request->user()->id)->findOrFail($address);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:40'],
            'recipient' => ['sometimes', 'string', 'max:120'],
            'line1' => ['sometimes', 'string', 'max:180'],
            'line2' => ['nullable', 'string', 'max:180'],
            'city' => ['sometimes', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:280'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            Address::query()->where('user_id', $request->user()->id)->update(['is_default' => false]);
        }
        $model->update($data);

        return ApiResponse::success($model->fresh(), 'Dirección actualizada');
    }

    public function destroyAddress(Request $request, int $address): JsonResponse
    {
        Address::query()->where('user_id', $request->user()->id)->findOrFail($address)->delete();

        return ApiResponse::success(null, 'Dirección eliminada');
    }

    private function idempotency(Request $request): ?string
    {
        $key = substr(trim((string) $request->header('Idempotency-Key')), 0, 80);

        return $key !== '' ? $key : null;
    }
}
