<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Resources\Store\OrderResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The signed-in customer's own orders. Queries are always scoped to the
 * user, so another customer's order id returns 404.
 */
class CustomerOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->with(['items', 'coupon'])
            ->where('customer_user_id', $request->user()->id)
            ->latest('placed_at')
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $order): OrderResource
    {
        $model = Order::query()
            ->with(['items', 'coupon'])
            ->where('customer_user_id', $request->user()->id)
            ->where('public_id', $order)
            ->firstOrFail();

        return new OrderResource($model);
    }
}
