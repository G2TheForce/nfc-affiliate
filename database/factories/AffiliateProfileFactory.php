<?php

namespace Database\Factories;

use App\Enums\AffiliateStatus;
use App\Models\AffiliateProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test and development data only. Use AffiliateMembershipService for real
 * status changes; these states skip history and notifications.
 *
 * @extends Factory<AffiliateProfile>
 */
class AffiliateProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'country_code' => 'NP',
            'city' => 'Kathmandu',
            'promotion_channels' => ['facebook', 'instagram'],
            'website_urls' => [],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (AffiliateProfile $profile) {
            $profile->user_id ??= User::factory()->create()->id;
            $profile->status ??= AffiliateStatus::Pending;
            $profile->applied_at ??= now();
            $profile->status_changed_at ??= now();
        });
    }

    public function status(AffiliateStatus $status): static
    {
        return $this->afterMaking(function (AffiliateProfile $profile) use ($status) {
            $profile->status = $status;
            if ($status !== AffiliateStatus::Pending && $status !== AffiliateStatus::Rejected) {
                $profile->referral_code ??= strtoupper(fake()->unique()->bothify('????####'));
                $profile->referral_slug ??= fake()->unique()->slug(2);
                $profile->approved_at ??= now();
            }
        });
    }

    public function forUser(User $user): static
    {
        return $this->afterMaking(fn (AffiliateProfile $profile) => $profile->user_id = $user->id);
    }
}
