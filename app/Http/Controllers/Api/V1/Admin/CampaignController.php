<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Enums\CommissionRuleScope;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CampaignRequest;
use App\Models\AffiliateProfile;
use App\Models\Campaign;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Campaigns (campaigns.manage). A campaign is never deleted, because orders
 * and rules point at it; switch it off or end it instead.
 */
class CampaignController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): array
    {
        $filters = $request->validate(['state' => ['nullable', Rule::in(['running', 'upcoming', 'ended', 'all'])]]);
        $state = $filters['state'] ?? 'all';
        $now = now();

        $campaigns = Campaign::query()
            ->with(['products', 'affiliates', 'rules' => fn ($q) => $q->inForceAt($now)])
            ->when($state === 'running', fn ($q) => $q->runningAt($now))
            ->when($state === 'upcoming', fn ($q) => $q->where('is_active', true)->where('starts_at', '>', $now))
            ->when($state === 'ended', fn ($q) => $q->where(fn ($q) => $q->where('is_active', false)->orWhere('ends_at', '<=', $now)))
            ->orderByDesc('starts_at')->orderByDesc('id')
            ->paginate(50);

        $stats = $this->stats($campaigns->getCollection()->pluck('id')->all());

        return [
            'data' => $campaigns->getCollection()->map(fn (Campaign $c) => $this->present($c, $stats[$c->id] ?? null))->all(),
            'meta' => ['current_page' => $campaigns->currentPage(), 'last_page' => $campaigns->lastPage(), 'total' => $campaigns->total()],
        ];
    }

    public function show(Campaign $campaign): array
    {
        $campaign->load(['products', 'affiliates', 'rules' => fn ($q) => $q->inForceAt(now())]);

        return ['data' => $this->present($campaign, $this->stats([$campaign->id])[$campaign->id] ?? null)];
    }

    public function store(CampaignRequest $request): JsonResponse
    {
        $data = $request->validated();
        $campaign = DB::transaction(function () use ($data, $request) {
            $campaign = new Campaign($this->attributes($data));
            $campaign->created_by = $request->user()->id;
            $campaign->save();
            $this->syncMembers($campaign, $data);
            $this->audit->log('campaign.created', $campaign, $this->auditable($data), $request->user());

            return $campaign;
        });

        return response()->json($this->show($campaign), 201);
    }

    public function update(CampaignRequest $request, Campaign $campaign): array
    {
        $data = $request->validated();
        DB::transaction(function () use ($campaign, $data, $request) {
            $campaign->fill($this->attributes($data))->save();
            $this->syncMembers($campaign, $data);
            $this->audit->log('campaign.updated', $campaign, $this->auditable($data), $request->user());
        });

        return $this->show($campaign);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        unset($data['affiliate_ids'], $data['product_ids']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function auditable(array $data): array
    {
        return array_diff_key($data, ['description' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMembers(Campaign $campaign, array $data): void
    {
        if (array_key_exists('product_ids', $data)) {
            $campaign->products()->sync(Product::whereIn('public_id', $data['product_ids'])->pluck('id'));
        }
        if (array_key_exists('affiliate_ids', $data)) {
            $campaign->affiliates()->sync(AffiliateProfile::whereIn('public_id', $data['affiliate_ids'])->pluck('id'));
        }
    }

    /**
     * Paid orders, sales and net commission per campaign.
     *
     * @param  list<int>  $ids
     * @return array<int, array{orders: int, sales_minor: int, commission_minor: int}>
     */
    private function stats(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $orders = Order::query()
            ->join('order_attributions', 'order_attributions.order_id', '=', 'orders.id')
            ->whereIn('order_attributions.campaign_id', $ids)
            ->whereIn('orders.status', [OrderStatus::Paid->value, OrderStatus::PartiallyRefunded->value])
            ->groupBy('order_attributions.campaign_id')
            ->selectRaw('order_attributions.campaign_id as campaign_id, COUNT(*) as orders, SUM(orders.subtotal_minor - orders.discount_minor) as sales')
            ->get()->keyBy('campaign_id');

        $commission = Commission::query()
            ->join('order_attributions', 'order_attributions.order_id', '=', 'commissions.order_id')
            ->whereIn('order_attributions.campaign_id', $ids)
            ->groupBy('order_attributions.campaign_id')
            ->selectRaw('order_attributions.campaign_id as campaign_id, SUM(commissions.amount_minor - commissions.reversed_minor) as net')
            ->pluck('net', 'campaign_id');

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'orders' => (int) ($orders[$id]->orders ?? 0),
                'sales_minor' => (int) ($orders[$id]->sales ?? 0),
                'commission_minor' => (int) ($commission[$id] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  array{orders: int, sales_minor: int, commission_minor: int}|null  $stats
     * @return array<string, mixed>
     */
    private function present(Campaign $campaign, ?array $stats): array
    {
        $now = now();
        /** @var CommissionRule|null $rule */
        $rule = $campaign->rules->first(fn (CommissionRule $r) => $r->scope === CommissionRuleScope::Campaign);

        return [
            'id' => $campaign->public_id,
            'name' => $campaign->name,
            'description' => $campaign->description,
            'starts_at' => $campaign->starts_at->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
            'state' => match (true) {
                ! $campaign->is_active || ($campaign->ends_at && $campaign->ends_at->lte($now)) => 'ended',
                $campaign->starts_at->gt($now) => 'upcoming',
                default => 'running',
            },
            'is_active' => $campaign->is_active,
            'budget_minor' => $campaign->budget_minor,
            'currency' => config('affiliate.currency.default'),
            'all_affiliates' => $campaign->all_affiliates,
            'affiliates' => $campaign->affiliates->map(fn (AffiliateProfile $a) => ['id' => $a->public_id, 'display_name' => $a->display_name, 'referral_code' => $a->referral_code])->values(),
            'products' => $campaign->products->map(fn (Product $p) => ['id' => $p->public_id, 'name' => $p->name])->values(),
            'rule' => $rule ? ['id' => $rule->public_id, 'name' => $rule->name, 'kind' => $rule->kind->value, 'rate_bp' => $rule->rate_bp, 'fixed_minor' => $rule->fixed_minor] : null,
            'stats' => $stats ?? ['orders' => 0, 'sales_minor' => 0, 'commission_minor' => 0],
            'created_at' => $campaign->created_at?->toIso8601String(),
        ];
    }
}
