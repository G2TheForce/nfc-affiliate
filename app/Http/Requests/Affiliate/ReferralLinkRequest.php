<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReferralLinkRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => strtolower(trim($this->input('slug')))]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $tag = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'];

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            // Slugs are fixed once created because the link may already be printed.
            'slug' => $creating ? ['required', 'string', 'min:2', 'max:60', 'regex:/^[a-z0-9][a-z0-9-]*$/'] : ['prohibited'],
            'product_id' => ['nullable', 'string', 'ulid', Rule::exists('products', 'public_id')->where('is_active', true)],
            'utm_source' => $tag,
            'utm_medium' => $tag,
            'utm_campaign' => $tag,
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lower-case letters, numbers and hyphens.',
            'slug.prohibited' => 'A link address cannot be changed after it is created.',
        ];
    }
}
