<?php

namespace App\Notifications\Finance;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts finance when the ledger check finds a difference, so drift is seen
 * the morning after rather than at month end.
 */
class ReconciliationDriftDetected extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(public readonly string $runId, public readonly int $issues) {}

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
            ->subject('Commission ledger check found differences')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message())
            ->action('Open reconciliation', rtrim((string) config('affiliate.frontend_url'), '/').'/admin/reconciliation');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'finance.reconciliation_drift',
            'title' => 'Ledger check found differences',
            'body' => $this->message(),
            'run_id' => $this->runId,
            'link' => '/admin/reconciliation',
        ];
    }

    private function message(): string
    {
        return "The commission ledger check found {$this->issues} ".($this->issues === 1 ? 'difference' : 'differences').'. Nothing was changed automatically; please review them.';
    }
}
