<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Foundation\Http\FormRequest;

class ApplyRequest extends FormRequest
{
    use AffiliateDetailsRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->detailRules() + [
            // The version the applicant actually saw, so a terms change
            // between page load and submit cannot be accepted silently.
            'terms_version' => ['required', 'string', 'max:40'],
            'accept_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'You must accept the affiliate terms to apply.',
            'promotion_channels.required' => 'Choose at least one way you will promote our products.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseDetails();
    }
}
