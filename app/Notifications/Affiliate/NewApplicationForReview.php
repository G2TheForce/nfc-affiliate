<?php

namespace App\Notifications\Affiliate;

use App\Models\AffiliateProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notice for staff who can review applications.
 */
class NewApplicationForReview extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 30;

    public function __construct(public readonly AffiliateProfile $profile) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'affiliate.application_submitted',
            'title' => 'New affiliate application',
            'body' => "{$this->profile->display_name} applied to the affiliate programme.",
            'link' => "/admin/affiliates/{$this->profile->public_id}",
        ];
    }
}
