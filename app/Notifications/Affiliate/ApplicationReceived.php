<?php

namespace App\Notifications\Affiliate;

use App\Models\AffiliateProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly AffiliateProfile $profile) {}

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
            ->subject('We received your affiliate application')
            ->greeting("Hello {$notifiable->name},")
            ->line('Thanks for applying to our affiliate programme. Our team will review your application and email you when there is a decision.')
            ->action('View your application', rtrim((string) config('affiliate.frontend_url'), '/').'/account/affiliate');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'affiliate.application_received',
            'title' => 'Application received',
            'body' => 'Your affiliate application is waiting for review.',
            'link' => '/account/affiliate',
        ];
    }
}
