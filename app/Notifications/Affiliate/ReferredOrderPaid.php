<?php

namespace App\Notifications\Affiliate;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an affiliate that an order credited to them was paid, with the
 * commission it earned (zero when no rule applies). Carries only the masked
 * order reference, never the customer.
 */
class ReferredOrderPaid extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(
        public readonly string $orderReference,
        public readonly int $commissionMinor,
        public readonly string $currency,
        public readonly CarbonInterface $eligibleAt,
        public readonly bool $onHold,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message())
            ->action('View your earnings', rtrim((string) config('affiliate.frontend_url'), '/').'/account/earnings');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'affiliate.referred_order_paid',
            'title' => $this->title(),
            'body' => $this->message(),
            'amount_minor' => $this->commissionMinor,
            'currency' => $this->currency,
            'link' => '/account/earnings',
        ];
    }

    private function title(): string
    {
        return $this->commissionMinor > 0 ? 'You earned commission on a new order' : 'A customer you referred placed an order';
    }

    private function message(): string
    {
        $order = "Order {$this->orderReference}, credited to you, has been paid.";
        if ($this->commissionMinor <= 0) {
            return $order.' No commission applies to the items in it.';
        }

        $amount = Money::format($this->commissionMinor, $this->currency);
        if ($this->onHold) {
            return "{$order} It earned {$amount} of commission, which is on hold while your membership is not active.";
        }
        $date = $this->eligibleAt->copy()->timezone((string) config('affiliate.business_timezone'))->format('j M Y');

        return "{$order} It earned {$amount} of commission, pending until {$date} if the order is not refunded.";
    }
}
