<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');
        $required = $product ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'sku' => [$required, 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products', 'sku')->ignore($product?->id)],
            'slug' => [$required, 'string', 'max:220', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'price_minor' => [$required, 'integer', 'min:1', 'max:100000000000'],
            'category_id' => ['nullable', 'integer', Rule::exists('product_categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['slug.regex' => 'Use lower-case letters, numbers and hyphens.'];
    }
}
