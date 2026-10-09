<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            // E.164: + followed by country code and number, e.g. +9779812345678
            'phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_privacy_notice' => ['accepted'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter the phone number with country code, for example +9779812345678.',
            'accept_privacy_notice.accepted' => 'You must accept the privacy notice to create an account.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
