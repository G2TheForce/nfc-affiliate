<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Security notice after a password change, so an owner notices a takeover. */
class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password was changed')
            ->greeting("Hello {$notifiable->name},")
            ->line('The password for your account was just changed, and you were signed out of other devices.')
            ->line('If this was not you, reset your password now and contact us.')
            ->action('Reset password', rtrim((string) config('affiliate.frontend_url'), '/').'/forgot-password');
    }
}
