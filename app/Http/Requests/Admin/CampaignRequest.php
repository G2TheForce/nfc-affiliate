<?php

namespace App\Http\Requests\Admin;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CampaignRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var Campaign|null $campaign */
        $campaign = $this->route('campaign');
        $required = $campaign ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => [$required, 'date'],
            'ends_at' => ['nullable', 'date'],
            'budget_minor' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'is_active' => ['sometimes', 'boolean'],
            'all_affiliates' => ['sometimes', 'boolean'],
            'affiliate_ids' => ['sometimes', 'array', 'max:500'],
            'affiliate_ids.*' => ['string', 'ulid', Rule::exists('affiliate_profiles', 'public_id')],
            'product_ids' => ['sometimes', 'array', 'max:200'],
            'product_ids.*' => ['string', 'ulid', Rule::exists('products', 'public_id')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var Campaign|null $campaign */
            $campaign = $this->route('campaign');
            $starts = $this->input('starts_at', $campaign?->starts_at?->toIso8601String());
            $ends = $this->exists('ends_at') ? $this->input('ends_at') : $campaign?->ends_at?->toIso8601String();
            if ($starts && $ends && strtotime((string) $ends) <= strtotime((string) $starts)) {
                $validator->errors()->add('ends_at', 'The end must be after the start.');
            }

            $all = $this->has('all_affiliates') ? $this->boolean('all_affiliates') : ($campaign->all_affiliates ?? true);
            $chosen = $this->has('affiliate_ids') ? count((array) $this->input('affiliate_ids')) : ($campaign ? $campaign->affiliates()->count() : 0);
            if (! $all && $chosen === 0) {
                $validator->errors()->add('affiliate_ids', 'Choose at least one affiliate, or open the campaign to all affiliates.');
            }
        }];
    }
}
