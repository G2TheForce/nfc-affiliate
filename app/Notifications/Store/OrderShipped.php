<?php

namespace App\Notifications\Store;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the customer their order has left, with the courier details staff entered. */
class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public readonly string $orderNumber;

    public readonly string $customerName;

    public readonly ?string $carrier;

    public readonly ?string $tracking;

    public function __construct(Order $order)
    {
        $this->orderNumber = $order->order_number;
        $this->customerName = $order->customer_name;
        $this->carrier = $order->carrier;
        $this->tracking = $order->tracking_number;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Order {$this->orderNumber} is on its way")
            ->greeting("Hello {$this->customerName},")
            ->line("Your order {$this->orderNumber} has been handed to the courier.");
        if ($this->carrier) {
            $mail->line("Courier: {$this->carrier}".($this->tracking ? ", tracking number {$this->tracking}" : '').'.');
        }

        return $mail;
    }
}
