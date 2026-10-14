<?php

namespace App\Domain\Cards;

use App\Models\CardProfile;

/** Shapes a tap profile for its owner and for the public page. */
class CardProfilePresenter
{
    private const SOCIAL_BASE = [
        'facebook' => 'https://facebook.com/',
        'instagram' => 'https://instagram.com/',
        'linkedin' => 'https://linkedin.com/in/',
        'tiktok' => 'https://tiktok.com/@',
        'youtube' => 'https://youtube.com/@',
        'x' => 'https://x.com/',
    ];

    /** @return array<string, mixed> */
    public function forOwner(CardProfile $profile): array
    {
        return $this->fields($profile) + [
            'socials' => (object) ($profile->socials ?? []),
            'is_published' => $profile->is_published,
            'taps_total' => $profile->taps_total,
            'saves_total' => $profile->saves_total,
            'public_url' => $this->publicUrl($profile),
            'updated_at' => $profile->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function forPublic(CardProfile $profile): array
    {
        $affiliate = $profile->user->affiliateProfile;
        $code = $affiliate?->status->isEligibleToEarn() ? $affiliate->referral_code : null;

        return $this->fields($profile) + [
            'socials' => $this->orderedSocials($profile),
            'public_url' => $this->publicUrl($profile),
            'vcard_url' => url('/p/'.$profile->handle.'/contact.vcf'),
            // An affiliate's card doubles as their referral link (ADR 0012).
            'get_a_card_url' => $code ? '/ref/'.$code : '/shop',
        ];
    }

    /**
     * Social links in a fixed order. MySQL's JSON type does not keep key
     * order, so the order comes from CardProfile::SOCIALS, not storage.
     *
     * @return list<array{network: string, url: string}>
     */
    public function orderedSocials(CardProfile $profile): array
    {
        $socials = $profile->socials ?? [];
        $out = [];
        foreach (CardProfile::SOCIALS as $network) {
            if (isset($socials[$network])) {
                $out[] = ['network' => $network, 'url' => $this->socialUrl($network, $socials[$network])];
            }
        }

        return $out;
    }

    public function publicUrl(CardProfile $profile): string
    {
        return url('/p/'.$profile->handle);
    }

    public function socialUrl(string $network, string $value): string
    {
        return str_starts_with($value, 'https://') ? $value : self::SOCIAL_BASE[$network].ltrim($value, '@');
    }

    /** @return array<string, mixed> */
    private function fields(CardProfile $profile): array
    {
        return $profile->only(['handle', 'display_name', 'headline', 'company', 'bio', 'phone', 'whatsapp', 'email', 'website', 'location', 'theme']);
    }
}
