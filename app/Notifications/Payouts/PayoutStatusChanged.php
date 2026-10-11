<?php

namespace App\Notifications\Payouts;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the affiliate each time their payout moves. The status is captured
 * when the notification is created, so a queued message describes the step
 * that triggered it even if the payout has moved on since.
 */
class PayoutStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public readonly string $status;

    public readonly int $amountMinor;

    public readonly string $currency;

    public readonly string $payoutId;

    public readonly ?string $reason;

    public readonly ?string $reference;

    public readonly string $destination;

    public function __construct(Payout $payout)
    {
        $this->status = $payout->status->value;
        $this->amountMinor = $payout->amount_minor;
        $this->currency = $payout->currency;
        $this->payoutId = $payout->public_id;
        $this->reason = $payout->status === PayoutStatus::Failed ? $payout->failure_reason : ($payout->status === PayoutStatus::Rejected ? $payout->review_note : null);
        $this->reference = $payout->payment_reference;
        $this->destination = $payout->destination_masked;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message());
        if ($this->reason) {
            $mail->line('Reason: '.$this->reason);
        }

        return $mail->action('View your payouts', rtrim((string) config('affiliate.frontend_url'), '/').'/account/payouts');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'affiliate.payout_'.$this->status,
            'title' => $this->title(),
            'body' => $this->message().($this->reason ? ' Reason: '.$this->reason : ''),
            'payout_id' => $this->payoutId,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'link' => '/account/payouts',
        ];
    }

    private function title(): string
    {
        return match (PayoutStatus::from($this->status)) {
            PayoutStatus::Requested => 'Payout request received',
            PayoutStatus::Approved => 'Payout approved',
            PayoutStatus::Paid => 'Payout sent',
            PayoutStatus::Rejected => 'Payout request declined',
            PayoutStatus::Failed => 'Payout could not be completed',
            PayoutStatus::Cancelled => 'Payout request cancelled',
        };
    }

    private function message(): string
    {
        $amount = Money::format($this->amountMinor, $this->currency);

        return match (PayoutStatus::from($this->status)) {
            PayoutStatus::Requested => "We received your request for {$amount} to {$this->destination}. Our finance team will review it.",
            PayoutStatus::Approved => "Your payout of {$amount} was approved and will be sent to {$this->destination}.",
            PayoutStatus::Paid => "{$amount} was sent to {$this->destination}. Transfer reference: {$this->reference}.",
            PayoutStatus::Rejected => "Your payout request for {$amount} was declined. The amount is back in your available balance.",
            PayoutStatus::Failed => "The transfer of {$amount} to {$this->destination} did not go through. The amount is back in your available balance.",
            PayoutStatus::Cancelled => "Your payout request for {$amount} was cancelled. The amount is back in your available balance.",
        };
    }
}
