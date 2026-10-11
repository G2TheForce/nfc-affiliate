<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commissions\CommissionMath;
use App\Domain\Commissions\CommissionRuleService;
use App\Domain\Commissions\RuleResolver;
use App\Enums\CommissionRuleKind;
use App\Enums\CommissionRuleScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CommissionRuleRequest;
use App\Http\Resources\Commissions\CommissionRuleResource;
use App\Models\AffiliateProfile;
use App\Models\CommissionPlan;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\ProductCategory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionRuleController extends Controller
{
    private const RELATIONS = ['plan', 'category', 'product', 'affiliate'];

    public function __construct(private readonly CommissionRuleService $rules) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::enum(CommissionRuleScope::class)],
            'active' => ['nullable', 'boolean'],
        ]);

        return CommissionRuleResource::collection(
            CommissionRule::query()
                ->with(self::RELATIONS)
                ->when($filters['scope'] ?? null, fn ($q, $s) => $q->where('scope', $s))
                ->when(array_key_exists('active', $filters) && $filters['active'] !== null, fn ($q) => $q->where('is_active', (bool) $filters['active']))
                ->orderBy('scope')
                ->orderByDesc('starts_at')
                ->paginate(100)
        );
    }

    public function store(CommissionRuleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $scope = CommissionRuleScope::from($data['scope']);
        $attributes = $this->attributes($data);
        if ($column = $scope->targetColumn()) {
            $attributes[$column] = $this->resolveTarget($scope, $data['target_id'] ?? null);
        }
        unset($attributes['target_id']);

        $rule = $this->rules->create($attributes, $request->user());

        return (new CommissionRuleResource($rule->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(CommissionRuleRequest $request, CommissionRule $rule): CommissionRuleResource
    {
        $updated = $this->rules->update($rule, $this->attributes($request->validated()), $request->user());

        return new CommissionRuleResource($updated->load(self::RELATIONS));
    }

    /**
     * Shows which rule would apply to a product sold by an affiliate today,
     * and the commission on one unit at the current price.
     */
    public function preview(Request $request, RuleResolver $resolver): array
    {
        $data = $request->validate([
            'affiliate_id' => ['required', 'string', 'ulid'],
            'product_id' => ['required', 'string', 'ulid'],
        ]);
        $affiliate = AffiliateProfile::where('public_id', $data['affiliate_id'])->first();
        $product = Product::where('public_id', $data['product_id'])->first();
        if (! $affiliate || ! $product) {
            throw ValidationException::withMessages(['affiliate_id' => 'Affiliate or product not found.']);
        }

        $rule = $resolver->pick($resolver->candidates($affiliate, now()), $product->id, $product->category_id);
        $rule?->load(self::RELATIONS);

        $amount = $rule && $rule->kind !== CommissionRuleKind::Exclude && ($rule->kind !== CommissionRuleKind::Fixed || $rule->currency === $product->currency)
            ? CommissionMath::amount($rule->kind, $product->price_minor, 1, $rule->rate_bp, $rule->fixed_minor)
            : 0;

        return ['data' => [
            'rule' => $rule ? (new CommissionRuleResource($rule))->resolve($request) : null,
            'unit_price_minor' => $product->price_minor,
            'commission_per_unit_minor' => $amount,
            'currency' => $product->currency,
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        foreach (['starts_at', 'ends_at'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $data[$key] ? CarbonImmutable::parse($data[$key]) : null;
            }
        }

        return $data;
    }

    private function resolveTarget(CommissionRuleScope $scope, ?string $id): int
    {
        $found = $id === null ? null : match ($scope) {
            CommissionRuleScope::Plan => CommissionPlan::where('public_id', $id)->value('id'),
            CommissionRuleScope::Category => ProductCategory::where('slug', $id)->value('id'),
            CommissionRuleScope::Product => Product::where('public_id', $id)->value('id'),
            CommissionRuleScope::Affiliate => AffiliateProfile::where('public_id', $id)->value('id'),
            CommissionRuleScope::Global => null,
        };

        if (! $found) {
            throw ValidationException::withMessages(['target_id' => 'Choose what this rule applies to.']);
        }

        return $found;
    }
}
