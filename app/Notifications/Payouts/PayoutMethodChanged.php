<?php

namespace App\Notifications\Payouts;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security notice: payout details changed. If the affiliate did not make
 * the change, this is how they find out before the next payout.
 */
class PayoutMethodChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(public readonly string $masked) {}

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
            ->subject('Your payout details were changed')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your payouts will now be sent to {$this->masked}.")
            ->line('If you did not make this change, reset your password and contact us straight away.')
            ->action('Review payout details', rtrim((string) config('affiliate.frontend_url'), '/').'/account/payouts');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'affiliate.payout_method_changed',
            'title' => 'Payout details changed',
            'body' => "Your payouts will now be sent to {$this->masked}. If you did not make this change, contact us straight away.",
            'link' => '/account/payouts',
        ];
    }
}
