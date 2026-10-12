<?php

namespace App\Notifications\Fraud;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** New fraud flags waiting in the review queue. */
class FraudFlagsRaised extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    /**
     * @param  list<string>  $lines  one "Signal: Affiliate" line per flag
     */
    public function __construct(public readonly array $lines) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line('These need a look before related commission is paid out:');
        foreach (array_slice($this->lines, 0, 20) as $line) {
            $mail->line('- '.$line);
        }

        return $mail->action('Open fraud review', rtrim((string) config('affiliate.frontend_url'), '/').'/admin/fraud');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'fraud.flags_raised',
            'title' => $this->title(),
            'body' => implode('; ', array_slice($this->lines, 0, 5)),
            'link' => '/admin/fraud',
        ];
    }

    private function title(): string
    {
        $n = count($this->lines);

        return $n === 1 ? 'New fraud flag to review' : "{$n} new fraud flags to review";
    }
}
