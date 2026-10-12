<?php

namespace App\Notifications\Auth;

use App\Enums\Role;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Invites a new staff member to set their password. */
class StaffInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(public readonly string $token, public readonly string $role) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Same link as a password reset, so it expires with the reset broker's setting.
        $url = ResetPassword::$createUrlCallback
            ? call_user_func(ResetPassword::$createUrlCallback, $notifiable, $this->token)
            : url('/reset-password?'.http_build_query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]));
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');
        $role = Role::tryFrom($this->role)?->label() ?? 'staff member';

        return (new MailMessage)
            ->subject('Your staff account for '.config('app.name'))
            ->greeting("Hello {$notifiable->name},")
            ->line("An account has been created for you as {$role}.")
            ->line('Choose your password to sign in.')
            ->action('Set your password', $url)
            ->line("This link works for {$minutes} minutes. If it has expired, use \"Forgot password\" on the sign-in page, or ask an administrator to send a new invitation.");
    }
}
