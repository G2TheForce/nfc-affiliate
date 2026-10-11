<?php

namespace App\Http\Requests\Store;

trait CartRules
{
    /**
     * @return array<string, mixed>
     */
    protected function cartRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'string', 'ulid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('affiliate.store.max_quantity_per_line')],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ];
    }
}
