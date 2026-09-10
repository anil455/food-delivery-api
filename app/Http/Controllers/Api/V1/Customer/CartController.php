<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Customer\AddCartItemRequest;
use App\Http\Resources\V1\CartPresenter;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartPricingService;
use App\Services\Cart\CartService;
use App\Services\Tenancy\RestaurantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CartPricingService $pricing,
        private readonly RestaurantContext $context,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->carts->existing($request->user());

        if ($cart === null || $cart->items->isEmpty()) {
            return ApiResponse::success(CartPresenter::empty(), 'Cart fetched successfully');
        }

        return ApiResponse::success(
            data: CartPresenter::make($cart, $this->pricing->price(
                $cart,
                $cart->restaurant,
                $this->addressFor($request),
            )),
            message: 'Cart fetched successfully',
        );
    }

    public function store(AddCartItemRequest $request): JsonResponse
    {
        $restaurant = $this->context->restaurant();
        $validated = $request->validated();

        // Both lookups are tenant-scoped, so an id from another restaurant is a
        // 404 before the cart is ever touched.
        $product = Product::query()->findOrFail($validated['product_id']);

        $variant = isset($validated['product_variant_id'])
            ? ProductVariant::query()
                ->where('product_id', $product->getKey())
                ->findOrFail($validated['product_variant_id'])
            : null;

        $this->carts->addItem(
            user: $request->user(),
            restaurant: $restaurant,
            product: $product,
            variant: $variant,
            quantity: (int) $validated['quantity'],
            addons: $validated['addons'] ?? [],
            instructions: $validated['special_instructions'] ?? null,
            replaceCart: (bool) ($validated['replace_cart'] ?? false),
        );

        return $this->respondWithCart($request, 'Item added to cart successfully', 201);
    }

    public function update(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $this->carts->updateQuantity($this->cartOrFail($request), $item, (int) $validated['quantity']);

        return $this->respondWithCart($request, 'Cart updated successfully');
    }

    public function destroyItem(Request $request, int $item): JsonResponse
    {
        $this->carts->removeItem($this->cartOrFail($request), $item);

        return $this->respondWithCart($request, 'Item removed from cart successfully');
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->carts->existing($request->user());

        if ($cart !== null) {
            $this->carts->clear($cart);
        }

        return ApiResponse::success(CartPresenter::empty(), 'Cart cleared successfully');
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
        ]);

        $this->carts->applyCoupon($this->cartOrFail($request), $request->user(), $validated['code']);

        return $this->respondWithCart($request, 'Coupon applied successfully');
    }

    public function removeCoupon(Request $request): JsonResponse
    {
        $this->carts->removeCoupon($this->cartOrFail($request));

        return $this->respondWithCart($request, 'Coupon removed successfully');
    }

    /**
     * Totals for a specific delivery address, so the customer sees the real
     * delivery fee and ETA before committing to the order.
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->respondWithCart($request, 'Cart summary fetched successfully');
    }

    private function respondWithCart(Request $request, string $message, int $status = 200): JsonResponse
    {
        $cart = $this->carts->existing($request->user());

        if ($cart === null || $cart->items->isEmpty()) {
            return ApiResponse::success(CartPresenter::empty(), $message, $status);
        }

        return ApiResponse::success(
            data: CartPresenter::make($cart, $this->pricing->price(
                $cart,
                $cart->restaurant,
                $this->addressFor($request),
            )),
            message: $message,
            status: $status,
        );
    }

    private function cartOrFail(Request $request): Cart
    {
        $cart = $this->carts->existing($request->user());

        abort_if($cart === null, 404, 'Cart not found.');

        return $cart;
    }

    /**
     * The address used for delivery pricing: an explicitly requested one, else
     * the default. Always looked up through the user relation, so another
     * customer address id resolves to nothing.
     */
    private function addressFor(Request $request): ?Address
    {
        $addressId = $request->query('address_id');

        if ($addressId !== null) {
            return $request->user()->addresses()->find((int) $addressId);
        }

        return $request->user()->addresses()
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
