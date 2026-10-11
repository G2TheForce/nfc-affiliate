<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\ProviderPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PaymentAttemptResource;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Online payment attempts: the review queue, a manual re-check, and closing a review. */
class PaymentAttemptController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['filter' => ['nullable', 'in:review,open,all']]);
        $filter = $data['filter'] ?? 'review';

        return PaymentAttemptResource::collection(
            PaymentAttempt::query()->with(['order:id,public_id,order_number', 'reviewer:id,name'])
                ->when($filter === 'review', fn ($q) => $q->whereNotNull('review_reason')->whereNull('reviewed_at'))
                ->when($filter === 'open', fn ($q) => $q->whereIn('status', ['initiated', 'pending']))
                ->latest('id')
                ->paginate(30),
        );
    }

    public function recheck(Request $request, PaymentAttempt $attempt, ProviderPaymentService $payments): PaymentAttemptResource
    {
        return new PaymentAttemptResource($payments->refresh($attempt, $request->user())->load(['order', 'reviewer']));
    }

    public function resolve(Request $request, PaymentAttempt $attempt, ProviderPaymentService $payments): PaymentAttemptResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return new PaymentAttemptResource($payments->resolveReview($attempt, $request->user(), $data['note'])->load(['order', 'reviewer']));
    }
}
