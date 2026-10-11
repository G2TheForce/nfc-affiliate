<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Referrals\ManualAttributionService;
use App\Domain\Store\OrderService;
use App\Enums\AttributionStatus;
use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderResource;
use App\Models\AffiliateProfile;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'attribution' => ['nullable', Rule::enum(AttributionStatus::class)],
            'affiliate_id' => ['nullable', 'string', 'ulid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $canSeeAttribution = $request->user()->can(Permission::AttributionView->value);

        $orders = Order::query()
            ->with(['coupon', 'attribution.affiliate', 'attribution.decider'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($canSeeAttribution ? ($filters['attribution'] ?? null) : null, fn ($q, $s) => $q->where('attribution_status', $s))
            ->when($canSeeAttribution ? ($filters['affiliate_id'] ?? null) : null, fn ($q, $id) => $q->whereHas('attribution.affiliate', fn ($a) => $a->where('public_id', $id)))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('order_number', $s)
                ->orWhere('customer_email', mb_strtolower($s))))
            ->latest('placed_at')
            ->paginate(25)
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        return $this->detail($order);
    }

    public function cancel(Request $request, Order $order, OrderService $orders): OrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $orders->cancel($order, $request->user(), $data['reason']);

        return $this->detail($order);
    }

    public function attribute(Request $request, Order $order, ManualAttributionService $service): OrderResource
    {
        $data = $request->validate([
            'affiliate_id' => ['required', 'string', 'ulid'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $affiliate = AffiliateProfile::where('public_id', $data['affiliate_id'])->first();
        if (! $affiliate) {
            throw ValidationException::withMessages(['affiliate_id' => 'Affiliate not found.']);
        }

        $service->assign($order, $affiliate, $request->user(), $data['reason']);

        return $this->detail($order);
    }

    private function detail(Order $order): OrderResource
    {
        $order->refresh()->load(['items', 'coupon', 'attribution.affiliate', 'attribution.click.link', 'attribution.decider']);

        return OrderResource::detail($order);
    }
}
