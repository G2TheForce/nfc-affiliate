<?php

namespace App\Http\Requests\Affiliate;

use App\Enums\PromotionChannel;
use Illuminate\Validation\Rule;

/**
 * Validation shared by the application form and profile updates.
 */
trait AffiliateDetailsRules
{
    /**
     * @return array<string, mixed>
     */
    protected function detailRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'display_name' => [$required, 'string', 'min:2', 'max:120'],
            'country_code' => [$required, 'string', 'size:2', 'alpha'],
            'city' => ['nullable', 'string', 'max:100'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'promotion_channels' => [$required, 'array', 'min:1', 'max:11'],
            'promotion_channels.*' => ['distinct', Rule::enum(PromotionChannel::class)],
            'website_urls' => ['nullable', 'array', 'max:5'],
            'website_urls.*' => ['url:http,https', 'max:255'],
            'audience_description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function normaliseDetails(): void
    {
        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => strtoupper($this->input('country_code'))]);
        }
    }
}
