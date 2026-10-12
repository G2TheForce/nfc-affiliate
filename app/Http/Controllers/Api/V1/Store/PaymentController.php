<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Domain\Payments\Gateways\GatewayRegistry;
use App\Domain\Payments\Gateways\PaymentGateway;
use App\Domain\Payments\ProviderPaymentService;
use App\Enums\PaymentAttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online payment for the customer's own order. A guest proves the order is
 * theirs with the access token issued at checkout; a signed-in customer may
 * also use their session.
 */
class PaymentController extends Controller
{
    public function methods(GatewayRegistry $gateways): array
    {
        return ['data' => array_values(array_map(fn (PaymentGateway $g) => [
            'key' => $g->key(),
            'label' => $g->label(),
            'minimum_minor' => $g->minimumMinor(),
        ], $gateways->available()))];
    }

    public function start(Request $request, Order $order, ProviderPaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:esewa,khalti'],
            'access_token' => ['nullable', 'string', 'max:100'],
        ]);
        $this->authorizeOrder($request, $order, $data['access_token'] ?? null);

        $result = $payments->start($order, $data['provider']);

        return response()->json(['data' => [
            'attempt_id' => $result['attempt']->public_id,
            'redirect' => $result['redirect'],
        ]], 201);
    }

    /**
     * Where the payment stands. Asks the provider again when the latest
     * attempt is still open and was not checked in the last 20 seconds.
     */
    public function status(Request $request, Order $order, ProviderPaymentService $payments): array
    {
        $this->authorizeOrder($request, $order, is_string($request->query('access_token')) ? $request->query('access_token') : null);

        $attempt = $order->paymentAttempts()->latest('id')->first();
        if ($attempt && $attempt->status->isOpen() && (! $attempt->last_checked_at || $attempt->last_checked_at->lt(now()->subSeconds(20)))) {
            $attempt = $payments->refresh($attempt);
        }
        $order->refresh();

        return ['data' => [
            'order_status' => $order->status->value,
            'order_status_label' => $order->status->label(),
            'paid' => $order->status->isPaid(),
            'attempt' => $attempt ? [
                'provider' => $attempt->provider,
                'status' => $attempt->status->value,
                // Customers see a plain outcome, not internal review reasons.
                'outcome' => match (true) {
                    $attempt->status === PaymentAttemptStatus::Completed => 'paid',
                    $attempt->status === PaymentAttemptStatus::Failed => 'failed',
                    $attempt->status === PaymentAttemptStatus::NeedsReview => 'checking',
                    default => 'pending',
                },
            ] : null,
        ]];
    }

    private function authorizeOrder(Request $request, Order $order, ?string $token): void
    {
        $user = $request->user('sanctum');
        abort_if($user && ! $user->isActive(), 403, 'This account is disabled.');
        $owner = $user && $order->customer_user_id !== null && $order->customer_user_id === $user->id;

        abort_unless($owner || $order->accessTokenMatches($token), 404, 'Order not found.');
    }
}
