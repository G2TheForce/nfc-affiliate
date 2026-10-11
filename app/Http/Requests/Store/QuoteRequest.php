<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

class QuoteRequest extends FormRequest
{
    use CartRules;

    public function rules(): array
    {
        return array_merge($this->cartRules(), [
            'customer_email' => ['nullable', 'email:rfc', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
        ]);
    }
}
