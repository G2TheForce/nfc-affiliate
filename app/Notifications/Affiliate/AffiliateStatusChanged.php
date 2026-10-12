<?php

namespace App\Notifications\Affiliate;

use App\Enums\AffiliateStatus;
use App\Models\AffiliateProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the member about a decision. Carries only the public reason; staff
 * internal notes are never passed in.
 */
class AffiliateStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(
        public readonly AffiliateProfile $profile,
        public readonly AffiliateStatus $from,
        public readonly AffiliateStatus $to,
        public readonly ?string $publicReason,
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
        [$subject, $line] = $this->wording();

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($line);

        if ($this->publicReason) {
            $mail->line('Reason: '.$this->publicReason);
        }

        return $mail->action('Open your account', rtrim((string) config('affiliate.frontend_url'), '/').'/account/affiliate');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        [$subject, $line] = $this->wording();

        return [
            'type' => 'affiliate.status_changed',
            'title' => $subject,
            'body' => $line.($this->publicReason ? ' Reason: '.$this->publicReason : ''),
            'status' => $this->to->value,
            'link' => '/account/affiliate',
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function wording(): array
    {
        return match (true) {
            $this->to === AffiliateStatus::Approved && $this->from === AffiliateStatus::Pending => [
                'Your affiliate application is approved',
                "Welcome to the affiliate programme. Your referral code is {$this->profile->referral_code}.",
            ],
            $this->to === AffiliateStatus::Rejected => [
                'Update on your affiliate application',
                'We are unable to approve your affiliate application at this time.',
            ],
            $this->to === AffiliateStatus::Suspended => [
                'Your affiliate membership is suspended',
                'Your affiliate membership has been suspended. Referrals will not earn commissions while it is suspended.',
            ],
            $this->to === AffiliateStatus::Approved && $this->from === AffiliateStatus::Suspended => [
                'Your affiliate membership is reinstated',
                'Your affiliate membership is active again.',
            ],
            $this->to === AffiliateStatus::Approved => [
                'Your affiliate membership is reactivated',
                'Your affiliate membership is active again.',
            ],
            $this->to === AffiliateStatus::Deactivated => [
                'Your affiliate membership is closed',
                'Your affiliate membership has been deactivated.',
            ],
            default => ['Affiliate membership update', 'Your affiliate membership status is now '.$this->to->label().'.'],
        };
    }
}
