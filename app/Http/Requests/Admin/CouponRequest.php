<?php

namespace App\Http\Requests\Admin;

use App\Enums\DiscountType;
use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CouponRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        /** @var Coupon|null $coupon */
        $coupon = $this->route('coupon');
        $required = $coupon ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'affiliate_id' => ['nullable', 'string', 'ulid', Rule::exists('affiliate_profiles', 'public_id')],
            'discount_type' => [$required, Rule::enum(DiscountType::class)],
            'discount_value' => [$required, 'integer', 'min:1', 'max:100000000000'],
            'min_order_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'max_discount_minor' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'earns_commission' => ['sometimes', 'boolean'],
            'campaign_id' => ['nullable', 'string', 'ulid', Rule::exists('campaigns', 'public_id')],
            'product_ids' => ['sometimes', 'array', 'max:200'],
            'product_ids.*' => ['string', 'ulid', Rule::exists('products', 'public_id')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $type = $this->input('discount_type', $this->route('coupon')?->discount_type?->value);
            if ($type === DiscountType::Percent->value && (int) $this->input('discount_value', $this->route('coupon')?->discount_value ?? 0) > 10000) {
                $validator->errors()->add('discount_value', 'A percentage discount cannot exceed 100% (10000 basis points).');
            }

            /** @var Coupon|null $coupon */
            $coupon = $this->route('coupon');
            if ($coupon && $this->has('code') && $this->input('code') !== $coupon->code && $coupon->redemptions()->exists()) {
                $validator->errors()->add('code', 'The code cannot change after it has been used.');
            }
        }];
    }
}
