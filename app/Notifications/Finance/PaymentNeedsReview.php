<?php

namespace App\Notifications\Finance;

use App\Models\PaymentAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An online payment that could not simply be applied: paid twice, paid for
 * a cancelled order, a wrong amount, or a refund seen at the provider.
 */
class PaymentNeedsReview extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public readonly string $orderId;

    public readonly string $orderNumber;

    public readonly string $provider;

    public readonly string $reason;

    public function __construct(PaymentAttempt $attempt)
    {
        $this->orderId = $attempt->order->public_id;
        $this->orderNumber = $attempt->order->order_number;
        $this->provider = $attempt->provider;
        $this->reason = (string) $attempt->review_reason;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Online payment needs review: order {$this->orderNumber}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->reason)
            ->action('Open the order', rtrim((string) config('affiliate.frontend_url'), '/')."/admin/orders/{$this->orderId}");
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'finance.payment_needs_review',
            'title' => "Payment needs review: {$this->orderNumber}",
            'body' => $this->reason,
            'link' => "/admin/orders/{$this->orderId}",
        ];
    }
}
