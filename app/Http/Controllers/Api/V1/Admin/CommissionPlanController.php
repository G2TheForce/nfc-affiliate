<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\AffiliateProfile;
use App\Models\CommissionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plans group affiliates for a shared default rate (a plan-scope rule).
 */
class CommissionPlanController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): array
    {
        return ['data' => CommissionPlan::query()->withCount('affiliates')->orderBy('name')->get()
            ->map(fn (CommissionPlan $plan) => $this->present($plan))];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120', 'unique:commission_plans,name'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $plan = CommissionPlan::create($data);
        $this->audit->log('commission_plan.created', $plan, $data);

        return response()->json(['data' => $this->present($plan->loadCount('affiliates'))], 201);
    }

    public function update(Request $request, CommissionPlan $plan): array
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120', Rule::unique('commission_plans', 'name')->ignore($plan->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $plan->fill($data)->save();
        $this->audit->log('commission_plan.updated', $plan, $data);

        return ['data' => $this->present($plan->loadCount('affiliates'))];
    }

    /** Puts an affiliate on a plan, or back on the global default with null. */
    public function assign(Request $request, AffiliateProfile $affiliate): array
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'string', 'ulid', Rule::exists('commission_plans', 'public_id')],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $plan = $data['plan_id'] ? CommissionPlan::where('public_id', $data['plan_id'])->firstOrFail() : null;
        $previous = $affiliate->plan?->public_id;

        $affiliate->forceFill(['commission_plan_id' => $plan?->id])->save();
        $this->audit->log('affiliate.plan_changed', $affiliate, ['plan' => $plan?->public_id, 'previous_plan' => $previous, 'reason' => $data['reason']]);

        return ['data' => ['affiliate_id' => $affiliate->public_id, 'plan' => $plan ? ['id' => $plan->public_id, 'name' => $plan->name] : null]];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CommissionPlan $plan): array
    {
        return [
            'id' => $plan->public_id,
            'name' => $plan->name,
            'description' => $plan->description,
            'affiliates_count' => $plan->affiliates_count ?? 0,
        ];
    }
}
