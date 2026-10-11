<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payouts\PayoutMethodService;
use App\Domain\Payouts\PayoutService;
use App\Enums\PayoutStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commissions\LedgerEntryResource;
use App\Http\Resources\Payouts\StaffPayoutResource;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PayoutController extends Controller
{
    private const RELATIONS = ['affiliate', 'reviewer', 'payer'];

    public function __construct(private readonly PayoutService $payouts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PayoutStatus::class)],
            'affiliate_id' => ['nullable', 'string', 'ulid'],
        ]);

        return StaffPayoutResource::collection(
            Payout::query()
                ->with(self::RELATIONS)
                ->withCount('items')
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['affiliate_id'] ?? null, fn ($q, $id) => $q->whereHas('affiliate', fn ($a) => $a->where('public_id', $id)))
                // Oldest open requests first: the queue finance works through.
                ->orderByRaw('CASE WHEN status IN (?, ?) THEN 0 ELSE 1 END', PayoutService::openStatuses())
                ->orderByRaw('CASE WHEN status IN (?, ?) THEN requested_at END ASC', PayoutService::openStatuses())
                ->latest('id')
                ->paginate(50)
                ->withQueryString()
        );
    }

    public function summary(): array
    {
        $rows = Payout::query()->selectRaw('status, COUNT(*) as n, COALESCE(SUM(amount_minor), 0) as total')->groupBy('status')->get()->keyBy('status');

        return ['data' => [
            'currency' => config('affiliate.currency.default'),
            'by_status' => collect(PayoutStatus::cases())->mapWithKeys(fn (PayoutStatus $s) => [$s->value => [
                'count' => (int) ($rows[$s->value]->n ?? 0),
                'amount_minor' => (int) ($rows[$s->value]->total ?? 0),
            ]]),
            'minimum_minor' => (int) config('affiliate.payouts.minimum_minor'),
            'require_separate_payer' => (bool) config('affiliate.payouts.require_separate_payer'),
        ]];
    }

    /**
     * Full account details are shown only to staff who can confirm a
     * transfer, and only while it can still be made; each view is audited.
     */
    public function show(Request $request, Payout $payout, AuditLogger $audit): array
    {
        $payout->load([...self::RELATIONS, 'items.commission', 'ledgerEntries.commission', 'ledgerEntries.adjustment', 'ledgerEntries.payout'])->loadCount('items');

        $destination = null;
        if ($request->user()->can(Permission::PayoutsMarkPaid->value) && $payout->status === PayoutStatus::Approved) {
            $destination = PayoutMethodService::describe($payout->method_type, $payout->account_name, $payout->destination);
            $audit->log('payout.destination_viewed', $payout, [], $request->user());
        }

        return ['data' => array_merge((new StaffPayoutResource($payout))->resolve($request), [
            'destination_details' => $destination,
            'commissions' => $payout->items->map(fn ($item) => [
                'id' => $item->commission->public_id,
                'order_number' => $item->commission->snapshot['order']['number'] ?? null,
                'product' => $item->commission->snapshot['line']['name'] ?? null,
                'amount_minor' => $item->amount_minor,
                'status' => $item->commission->status->value,
            ])->values(),
            'ledger' => LedgerEntryResource::collection($payout->ledgerEntries->sortBy('id')->values())->resolve($request),
        ])];
    }

    public function approve(Request $request, Payout $payout): StaffPayoutResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->resource($this->payouts->approve($payout, $request->user(), $data['note'] ?? null));
    }

    public function reject(Request $request, Payout $payout): StaffPayoutResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->resource($this->payouts->reject($payout, $request->user(), $data['note']));
    }

    public function markPaid(Request $request, Payout $payout): StaffPayoutResource
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'min:4', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['reference.regex' => 'Use the transfer reference exactly as the bank or wallet shows it (letters, digits, . _ / -).']);

        return $this->resource($this->payouts->markPaid($payout, $request->user(), $data['reference'], $data['note'] ?? null));
    }

    public function markFailed(Request $request, Payout $payout): StaffPayoutResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->resource($this->payouts->markFailed($payout, $request->user(), $data['reason']));
    }

    private function resource(Payout $payout): StaffPayoutResource
    {
        return new StaffPayoutResource($payout->load(self::RELATIONS)->loadCount('items'));
    }
}
