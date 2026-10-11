<?php

namespace App\Notifications\Store;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Receipt to the customer once their payment is verified. */
class OrderPaymentReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public readonly string $orderNumber;

    public readonly string $customerName;

    public readonly string $amount;

    public readonly string $method;

    public function __construct(Order $order, OrderPayment $payment)
    {
        $this->orderNumber = $order->order_number;
        $this->customerName = $order->customer_name;
        $this->amount = Money::format($payment->amount_minor, $payment->currency);
        $this->method = $payment->method->label();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payment received for order {$this->orderNumber}")
            ->greeting("Hello {$this->customerName},")
            ->line("We have received {$this->amount} for order {$this->orderNumber} ({$this->method}).")
            ->line('We will let you know when your order is on its way.');
    }
}
