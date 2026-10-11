<?php

namespace App\Http\Controllers\Payments;

use App\Domain\Payments\ProviderPaymentService;
use App\Enums\PaymentAttemptStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where eSewa and Khalti send the customer's browser back. The callback is
 * recorded and triggers a server-side status check; the browser is then sent
 * to the order page, which shows what the check found.
 */
class PaymentReturnController extends Controller
{
    public function __construct(private readonly ProviderPaymentService $payments) {}

    public function esewaReturn(Request $request): RedirectResponse
    {
        return $this->finish($this->payments->handleCallback('esewa', 'return', $request->query()));
    }

    public function esewaFailure(string $attempt): RedirectResponse
    {
        return $this->finish($this->payments->handleCallback('esewa', 'failure', [], $attempt), true);
    }

    public function khaltiReturn(Request $request): RedirectResponse
    {
        return $this->finish($this->payments->handleCallback('khalti', 'return', $request->query()));
    }

    private function finish(?PaymentAttempt $attempt, bool $failed = false): RedirectResponse
    {
        if (! $attempt) {
            return redirect('/order-confirmed?payment=unknown');
        }

        $outcome = match (true) {
            $attempt->status === PaymentAttemptStatus::Completed => 'paid',
            $failed || $attempt->status === PaymentAttemptStatus::Failed => 'failed',
            default => 'pending',
        };

        return redirect('/order-confirmed?'.http_build_query(['order' => $attempt->order()->value('public_id'), 'payment' => $outcome]));
    }
}
