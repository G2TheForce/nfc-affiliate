<?php

namespace App\Http\Requests\Cards;

use App\Domain\Cards\CardProfileService;
use App\Models\CardProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCardProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('handle'))) {
            $this->merge(['handle' => strtolower(trim($this->input('handle')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $own = $this->user()->cardProfile?->id;
        $phone = ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'];

        return [
            'handle' => [
                'required', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'not_regex:/--/',
                Rule::notIn(CardProfileService::RESERVED),
                Rule::unique('card_profiles', 'handle')->ignore($own),
            ],
            'display_name' => ['required', 'string', 'max:80'],
            'headline' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:500'],
            'phone' => $phone,
            'whatsapp' => $phone,
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'location' => ['nullable', 'string', 'max:100'],
            'socials' => ['nullable', 'array:'.implode(',', CardProfile::SOCIALS)],
            // A full https link, or just the username.
            'socials.*' => ['nullable', 'string', 'max:255', 'regex:/^(https:\/\/[^\s<>"]+|@?[A-Za-z0-9._-]{1,60})$/'],
            'theme' => ['required', Rule::in(CardProfile::THEMES)],
            'is_published' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'handle.regex' => 'Use lowercase letters, numbers and single hyphens, starting and ending with a letter or number.',
            'handle.not_regex' => 'Use single hyphens only.',
            'handle.not_in' => 'That address is reserved. Please choose another.',
            'handle.unique' => 'That address is taken. Please choose another.',
            'socials.*.regex' => 'Enter a full https:// link or just the username.',
        ];
    }
}
