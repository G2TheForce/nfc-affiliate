<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Domain\Commissions\CommissionLedger;
use App\Domain\Payouts\PayoutMethodService;
use App\Domain\Payouts\PayoutService;
use App\Enums\LedgerBucket;
use App\Enums\PayoutMethodType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Payouts\PayoutResource;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The member's own payouts and payout details. Always scoped to the
 * signed-in user's profile.
 */
class PayoutController extends Controller
{
    use ResolvesAffiliate;

    public function __construct(private readonly PayoutService $payouts) {}

    public function summary(Request $request, CommissionLedger $ledger): array
    {
        $profile = $this->trackedProfile($request);
        $balances = $ledger->balances($profile->id);
        $method = $profile->payoutMethod()->first();
        $open = $profile->payouts()->whereIn('status', PayoutService::openStatuses())->withCount('items')->first();
        $minimum = (int) config('affiliate.payouts.minimum_minor');

        $blocker = match (true) {
            ! $profile->status->isEligibleToEarn() => 'Payouts can be requested while your affiliate membership is active.',
            $open !== null => 'You already have a payout request in progress.',
            $method === null => 'Add your payout details before requesting a payout.',
            $balances[LedgerBucket::Available->value] < $minimum => 'Your available balance is below the minimum payout.',
            default => null,
        };

        return ['data' => [
            'currency' => config('affiliate.currency.default'),
            'available_minor' => $balances[LedgerBucket::Available->value],
            'reserved_minor' => $balances[LedgerBucket::Reserved->value],
            'paid_minor' => $balances[LedgerBucket::Paid->value],
            'minimum_minor' => $minimum,
            'method' => $method ? [
                'type' => $method->type->value,
                'type_label' => $method->type->label(),
                'account_name' => $method->account_name,
                'masked' => $method->masked,
                'updated_at' => $method->updated_at?->toIso8601String(),
            ] : null,
            'open_payout' => $open ? new PayoutResource($open) : null,
            'can_request' => $blocker === null,
            'blocker' => $blocker,
        ]];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $profile = $this->trackedProfile($request);

        return PayoutResource::collection($profile->payouts()->withCount('items')->latest('id')->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $profile = $this->earningProfile($request);
        $payout = $this->payouts->request($profile, $request->user());

        return (new PayoutResource($payout->loadCount('items')))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, Payout $payout): PayoutResource
    {
        $profile = $this->trackedProfile($request);
        abort_unless($payout->affiliate_profile_id === $profile->id, 404);

        return new PayoutResource($this->payouts->cancel($payout, $request->user())->loadCount('items'));
    }

    public function saveMethod(Request $request, PayoutMethodService $methods): array
    {
        $profile = $this->earningProfile($request);
        $data = $request->validate([
            'type' => ['required', Rule::enum(PayoutMethodType::class)],
            'account_name' => ['required', 'string', 'min:2', 'max:120'],
            'bank_name' => ['required_if:type,bank_transfer', 'nullable', 'string', 'min:2', 'max:80'],
            'branch' => ['nullable', 'string', 'max:80'],
            'account_number' => ['required_if:type,bank_transfer', 'nullable', 'string', 'regex:/^[A-Za-z0-9-]{6,30}$/'],
            // Nepali mobile numbers registered with eSewa or Khalti.
            'wallet_id' => ['required_unless:type,bank_transfer', 'nullable', 'string', 'regex:/^9[678]\d{8}$/'],
            'password' => ['required', 'string'],
        ], [
            'account_number.regex' => 'Enter the account number using letters, digits and dashes only (6 to 30 characters).',
            'wallet_id.regex' => 'Enter the 10-digit mobile number registered with the wallet.',
        ]);

        // Changing where money goes needs the password, not just a session.
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        $method = $methods->save($profile, $data, $request->user());

        return ['data' => [
            'type' => $method->type->value,
            'type_label' => $method->type->label(),
            'account_name' => $method->account_name,
            'masked' => $method->masked,
            'updated_at' => $method->updated_at?->toIso8601String(),
        ]];
    }
}
