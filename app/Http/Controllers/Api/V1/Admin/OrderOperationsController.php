<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\DisputeService;
use App\Domain\Store\FulfilmentService;
use App\Enums\FulfilmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderResource;
use App\Models\Order;
use App\Models\OrderDispute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shipping steps and payment disputes on an order. */
class OrderOperationsController extends Controller
{
    public function __construct(private readonly OrderController $orders) {}

    public function fulfil(Request $request, Order $order, FulfilmentService $fulfilment): OrderResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([FulfilmentStatus::Processing->value, FulfilmentStatus::Shipped->value, FulfilmentStatus::Delivered->value])],
            'carrier' => ['nullable', 'string', 'max:60'],
            'tracking_number' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9 ._\/-]+$/'],
        ]);
        $fulfilment->advance($order, $request->user(), FulfilmentStatus::from($data['status']), $data);

        return $this->orders->detail($order);
    }

    public function openDispute(Request $request, Order $order, DisputeService $disputes): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $disputes->open($order, $request->user(), $data);

        return $this->orders->detail($order)->response()->setStatusCode(201);
    }

    public function resolveDispute(Request $request, OrderDispute $dispute, DisputeService $disputes): OrderResource
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:won,lost'],
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $disputes->resolve($dispute, $request->user(), $data['outcome'], $data['note']);

        return $this->orders->detail($dispute->order);
    }
}
