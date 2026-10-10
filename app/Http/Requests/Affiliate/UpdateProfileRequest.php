<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    use AffiliateDetailsRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->detailRules(partial: true);
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseDetails();
    }
}
