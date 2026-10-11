<?php

namespace App\Http\Resources\Commissions;

use App\Enums\CommissionRuleScope;
use App\Models\CommissionRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionRule
 */
class CommissionRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'scope' => $this->scope->value,
            'scope_label' => $this->scope->label(),
            'target' => self::target($this->resource),
            'kind' => $this->kind->value,
            'rate_bp' => $this->rate_bp,
            'fixed_minor' => $this->fixed_minor,
            'currency' => $this->currency,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'version' => $this->version,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public static function target(CommissionRule $rule): ?array
    {
        return match ($rule->scope) {
            CommissionRuleScope::Global => null,
            CommissionRuleScope::Plan => $rule->plan ? ['id' => $rule->plan->public_id, 'name' => $rule->plan->name] : null,
            CommissionRuleScope::Category => $rule->category ? ['id' => $rule->category->slug, 'name' => $rule->category->name] : null,
            CommissionRuleScope::Product => $rule->product ? ['id' => $rule->product->public_id, 'name' => $rule->product->name] : null,
            CommissionRuleScope::Affiliate => $rule->affiliate ? ['id' => $rule->affiliate->public_id, 'name' => $rule->affiliate->display_name] : null,
        };
    }
}
