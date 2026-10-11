<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commissions\CommissionLedger;
use App\Domain\Commissions\CommissionLifecycle;
use App\Enums\CommissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commissions\LedgerEntryResource;
use App\Http\Resources\Commissions\StaffCommissionResource;
use App\Models\AffiliateProfile;
use App\Models\Commission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionController extends Controller
{
    public function __construct(private readonly CommissionLifecycle $lifecycle) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(CommissionStatus::class)],
            'affiliate_id' => ['nullable', 'string', 'ulid'],
            'on_hold' => ['nullable', 'boolean'],
            'order' => ['nullable', 'string', 'max:32'],
        ]);

        $commissions = Commission::query()
            ->with(['order', 'affiliate'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['affiliate_id'] ?? null, fn ($q, $id) => $q->whereHas('affiliate', fn ($a) => $a->where('public_id', $id)))
            ->when(isset($filters['on_hold']), fn ($q) => $filters['on_hold'] ? $q->whereNotNull('held_at') : $q->whereNull('held_at'))
            ->when($filters['order'] ?? null, fn ($q, $n) => $q->whereHas('order', fn ($o) => $o->where('order_number', $n)))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return StaffCommissionResource::collection($commissions);
    }

    /** Programme-wide liability by bucket. */
    public function summary(CommissionLedger $ledger): array
    {
        return ['data' => [
            'currency' => config('affiliate.currency.default'),
            'balances' => $ledger->balances(),
            'on_hold_minor' => (int) Commission::query()->whereNotNull('held_at')->whereIn('status', [CommissionStatus::Pending->value, CommissionStatus::Approved->value])
                ->selectRaw('COALESCE(SUM(amount_minor - reversed_minor), 0) as total')->value('total'),
            'auto_release' => (bool) config('affiliate.commissions.auto_release'),
            'holding_period_days' => (int) config('affiliate.commissions.holding_period_days'),
        ]];
    }

    public function show(Commission $commission): array
    {
        $commission->load(['order', 'affiliate', 'ledgerEntries.commission', 'ledgerEntries.adjustment']);

        return ['data' => [
            ...(new StaffCommissionResource($commission))->resolve(request()),
            'snapshot' => $commission->snapshot,
            'ledger' => LedgerEntryResource::collection($commission->ledgerEntries->sortBy('id')->values())->resolve(request()),
        ]];
    }

    public function hold(Request $request, Commission $commission): StaffCommissionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->present($this->lifecycle->hold($commission, $request->user(), $data['reason']));
    }

    public function releaseHold(Request $request, Commission $commission): StaffCommissionResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->present($this->lifecycle->releaseHold($commission, $request->user(), $data['note']));
    }

    public function void(Request $request, Commission $commission): StaffCommissionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->present($this->lifecycle->void($commission, $request->user(), $data['reason']));
    }

    /** Finance releases an approved commission to the available balance (when auto-release is off). */
    public function release(Request $request, Commission $commission): StaffCommissionResource
    {
        if (! $this->lifecycle->release($commission, $request->user())) {
            throw ValidationException::withMessages(['status' => 'Only approved commissions that are not on hold can be released.']);
        }

        return $this->present($commission);
    }

    /** One affiliate's balances and ledger, for finance. */
    public function affiliateLedger(AffiliateProfile $affiliate, CommissionLedger $ledger): array
    {
        $entries = $affiliate->ledgerEntries()->with(['commission', 'adjustment'])->latest('id')->paginate(50);

        return [
            'data' => LedgerEntryResource::collection($entries->getCollection())->resolve(request()),
            'balances' => $ledger->balances($affiliate->id),
            'currency' => config('affiliate.currency.default'),
            'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'total' => $entries->total()],
        ];
    }

    private function present(Commission $commission): StaffCommissionResource
    {
        return new StaffCommissionResource($commission->fresh(['order', 'affiliate']));
    }
}
