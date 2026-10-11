<?php

namespace App\Notifications\Affiliate;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once per maturation run to each affiliate whose commissions became
 * available, with the total.
 */
class CommissionsAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
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
            ->subject('Commission added to your available balance')
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
            'type' => 'affiliate.commissions_available',
            'title' => 'Commission available',
            'body' => $this->message(),
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'link' => '/account/earnings',
        ];
    }

    private function message(): string
    {
        $major = number_format(intdiv($this->amountMinor, 100)).'.'.str_pad((string) ($this->amountMinor % 100), 2, '0', STR_PAD_LEFT);

        return "{$this->currency} {$major} of commission passed its holding period and is now in your available balance.";
    }
}
