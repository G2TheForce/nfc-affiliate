<?php

namespace App\Domain\Commissions;

use App\Domain\Audit\AuditLogger;
use App\Enums\CommissionRuleKind;
use App\Enums\CommissionRuleScope;
use App\Models\CommissionRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves commission rules. Two active rules for the same target with
 * overlapping dates are refused, so the resolver never has to choose
 * between them. Edits bump the version; commissions already created keep
 * the snapshot of the version they used.
 */
class CommissionRuleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated attributes with internal ids
     */
    public function create(array $data, User $actor): CommissionRule
    {
        return DB::transaction(function () use ($data, $actor) {
            $rule = new CommissionRule;
            $rule->forceFill($this->normalise($data + ['is_active' => true]) + ['version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            $this->assertValid($rule);
            $rule->save();

            $this->audit->log('commission_rule.created', $rule, $rule->snapshot(), $actor);

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommissionRule $rule, array $data, User $actor): CommissionRule
    {
        return DB::transaction(function () use ($rule, $data, $actor) {
            $locked = CommissionRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $before = $locked->snapshot();

            $locked->forceFill($this->normalise($data + [
                'kind' => $locked->kind->value,
                'rate_bp' => $locked->rate_bp,
                'fixed_minor' => $locked->fixed_minor,
                'currency' => $locked->currency,
            ]));
            if (! $locked->isDirty()) {
                return $locked;
            }
            $this->assertValid($locked);
            $locked->version++;
            $locked->updated_by = $actor->id;
            $locked->save();

            $this->audit->log('commission_rule.updated', $locked, ['before' => $before, 'after' => $locked->snapshot()], $actor);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $kind = CommissionRuleKind::from($data['kind']);
        $data['rate_bp'] = $kind === CommissionRuleKind::Percent ? ($data['rate_bp'] ?? null) : null;
        $data['fixed_minor'] = $kind === CommissionRuleKind::Fixed ? ($data['fixed_minor'] ?? null) : null;
        $data['currency'] = $kind === CommissionRuleKind::Fixed ? ($data['currency'] ?? config('affiliate.currency.default')) : null;

        return $data;
    }

    private function assertValid(CommissionRule $rule): void
    {
        $scope = $rule->scope;
        $kind = $rule->kind;

        if ($kind === CommissionRuleKind::Exclude && ! in_array($scope, [CommissionRuleScope::Product, CommissionRuleScope::Category], true)) {
            throw ValidationException::withMessages(['kind' => 'Only product and category rules can exclude.']);
        }
        if ($kind === CommissionRuleKind::Percent && ($rule->rate_bp === null || $rule->rate_bp < 1 || $rule->rate_bp > (int) config('affiliate.commissions.max_rate_bp'))) {
            throw ValidationException::withMessages(['rate_bp' => 'Enter a rate between 0.01% and '.(config('affiliate.commissions.max_rate_bp') / 100).'%.']);
        }
        if ($kind === CommissionRuleKind::Fixed && ($rule->fixed_minor === null || $rule->fixed_minor < 1)) {
            throw ValidationException::withMessages(['fixed_minor' => 'Enter a fixed amount per unit.']);
        }

        foreach (CommissionRuleScope::cases() as $other) {
            $column = $other->targetColumn();
            if ($column && $other !== $scope && $rule->getAttribute($column) !== null) {
                throw ValidationException::withMessages(['scope' => 'A '.$scope->value.' rule cannot also target a '.$other->value.'.']);
            }
        }
        $column = $scope->targetColumn();
        if ($column && $rule->getAttribute($column) === null) {
            throw ValidationException::withMessages(['target_id' => 'Choose what this rule applies to.']);
        }

        if ($rule->ends_at && $rule->ends_at->lte($rule->starts_at)) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }

        if ($rule->is_active) {
            $this->assertNoOverlap($rule);
        }
    }

    private function assertNoOverlap(CommissionRule $rule): void
    {
        $column = $rule->scope->targetColumn();
        $farFuture = CarbonImmutable::create(9999, 12, 31);

        $clash = CommissionRule::query()
            ->where('scope', $rule->scope->value)
            ->where('is_active', true)
            ->when($rule->exists, fn ($q) => $q->whereKeyNot($rule->id))
            ->when($column, fn ($q) => $q->where($column, $rule->getAttribute($column)))
            // Ranges [start, end) overlap when each starts before the other ends.
            ->where('starts_at', '<', $rule->ends_at ?? $farFuture)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $rule->starts_at))
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'starts_at' => "These dates overlap the active rule \"{$clash->name}\" for the same target. End that rule first or change the dates.",
            ]);
        }
    }
}
