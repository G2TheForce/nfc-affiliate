<?php

namespace App\Notifications\Payouts;

use App\Models\Payout;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notice for staff who can review payouts.
 */
class NewPayoutRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public readonly string $body;

    public readonly string $payoutId;

    public function __construct(Payout $payout, string $affiliateName)
    {
        $this->payoutId = $payout->public_id;
        $this->body = "{$affiliateName} requested a payout of ".Money::format($payout->amount_minor, $payout->currency).'.';
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payout.requested',
            'title' => 'New payout request',
            'body' => $this->body,
            'link' => '/admin/payouts?payout='.$this->payoutId,
        ];
    }
}
