<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    use CartRules;

    public function rules(): array
    {
        return array_merge($this->cartRules(), [
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            // Ignored for signed-in customers: their account email is used.
            'customer_email' => [$this->user('sanctum') ? 'nullable' : 'required', 'email:rfc', 'max:255'],
            'customer_phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'shipping_city' => ['required', 'string', 'max:100'],
            'shipping_address' => ['required', 'string', 'max:255'],
            'referral_code' => ['nullable', 'string', 'max:60'],
        ]);
    }

    public function messages(): array
    {
        return ['customer_phone.regex' => 'Enter the phone number with country code, for example +9779812345678.'];
    }
}
