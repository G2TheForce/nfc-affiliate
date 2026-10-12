<?php

namespace App\Models;

use App\Enums\CommissionRuleKind;
use App\Enums\CommissionRuleScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Created and changed only through CommissionRuleService, which rejects
 * overlapping rules and bumps the version on every edit.
 */
class CommissionRule extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['id', 'commission_plan_id', 'product_category_id', 'product_id', 'affiliate_profile_id', 'campaign_id', 'created_by', 'updated_by'];

    protected static function booted(): void
    {
        static::creating(function (CommissionRule $rule) {
            $rule->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'scope' => CommissionRuleScope::class,
            'kind' => CommissionRuleKind::class,
            'commission_plan_id' => 'integer',
            'product_category_id' => 'integer',
            'product_id' => 'integer',
            'affiliate_profile_id' => 'integer',
            'campaign_id' => 'integer',
            'rate_bp' => 'integer',
            'fixed_minor' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @param  Builder<CommissionRule>  $query
     */
    public function scopeInForceAt(Builder $query, CarbonInterface $at): void
    {
        $query->where('is_active', true)
            ->where('starts_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'commission_plan_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(AffiliateProfile::class, 'affiliate_profile_id');
    }

    /**
     * Everything needed to reproduce a calculation, stored on each commission.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'rule_id' => $this->public_id,
            'name' => $this->name,
            'scope' => $this->scope->value,
            'kind' => $this->kind->value,
            'rate_bp' => $this->rate_bp,
            'fixed_minor' => $this->fixed_minor,
            'currency' => $this->currency,
            'version' => $this->version,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
        ];
    }
}
