<?php

namespace App\Http\Requests\Admin;

use App\Enums\CommissionRuleKind;
use App\Enums\CommissionRuleScope;
use App\Models\CommissionRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape validation only; CommissionRuleService enforces the business rules
 * (kinds per scope, rate limits, no overlapping rules).
 */
class CommissionRuleRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var CommissionRule|null $rule */
        $rule = $this->route('rule');
        $required = $rule ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:3', 'max:120'],
            // The scope and target of an existing rule never change; end it and create a new one.
            'scope' => [$rule ? 'prohibited' : 'required', Rule::enum(CommissionRuleScope::class)],
            'target_id' => [$rule ? 'prohibited' : 'nullable', 'string', 'max:140'],
            'kind' => [$required, Rule::enum(CommissionRuleKind::class)],
            'rate_bp' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'fixed_minor' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'starts_at' => [$required, 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
