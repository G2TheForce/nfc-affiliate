<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commissions\AdjustmentService;
use App\Enums\AdjustmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Commissions\AdjustmentResource;
use App\Models\AffiliateProfile;
use App\Models\Commission;
use App\Models\CommissionAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdjustmentController extends Controller
{
    private const RELATIONS = ['affiliate', 'commission', 'creator', 'decider'];

    public function __construct(private readonly AdjustmentService $adjustments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['nullable', Rule::enum(AdjustmentStatus::class)]]);

        return AdjustmentResource::collection(
            CommissionAdjustment::query()
                ->with(self::RELATIONS)
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->latest('id')
                ->paginate(50)
                ->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'affiliate_id' => ['required', 'string', 'ulid'],
            'commission_id' => ['nullable', 'string', 'ulid'],
            'amount_minor' => ['required', 'integer', 'not_in:0', 'min:-100000000000', 'max:100000000000'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $affiliate = AffiliateProfile::where('public_id', $data['affiliate_id'])->first();
        if (! $affiliate) {
            throw ValidationException::withMessages(['affiliate_id' => 'Affiliate not found.']);
        }
        $commission = null;
        if (! empty($data['commission_id']) && ! ($commission = Commission::where('public_id', $data['commission_id'])->first())) {
            throw ValidationException::withMessages(['commission_id' => 'Commission not found.']);
        }

        $adjustment = $this->adjustments->propose($affiliate, (int) $data['amount_minor'], $data['reason'], $commission, $request->user());

        return (new AdjustmentResource($adjustment->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function approve(Request $request, CommissionAdjustment $adjustment): AdjustmentResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return new AdjustmentResource($this->adjustments->approve($adjustment, $request->user(), $data['note'] ?? null)->load(self::RELATIONS));
    }

    public function reject(Request $request, CommissionAdjustment $adjustment): AdjustmentResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);

        return new AdjustmentResource($this->adjustments->reject($adjustment, $request->user(), $data['note'])->load(self::RELATIONS));
    }
}
