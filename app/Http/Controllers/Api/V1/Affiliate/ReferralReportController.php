<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Domain\Referrals\ReferralReport;
use App\Http\Controllers\Controller;
use App\Models\OrderAttribution;
use App\Support\Privacy\Mask;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReferralReportController extends Controller
{
    use ResolvesAffiliate;

    public function summary(Request $request, ReferralReport $report): array
    {
        $profile = $this->trackedProfile($request);
        $range = $this->range($request);

        return ['data' => [
            'from' => $range['from']->toDateString(),
            'to' => $range['to']->toDateString(),
            'currency' => config('affiliate.currency.default'),
            ...$report->forAffiliate($profile, $range['from'], $range['to']),
        ]];
    }

    /**
     * Orders attributed to the member. No customer details: only what the
     * affiliate needs to recognise their referrals.
     */
    public function orders(Request $request): array
    {
        $profile = $this->trackedProfile($request);

        $page = OrderAttribution::query()
            ->with(['order.items'])
            ->where('affiliate_profile_id', $profile->id)
            ->latest('id')
            ->paginate(20);

        return [
            'data' => $page->getCollection()->map(fn (OrderAttribution $a) => [
                'reference' => Mask::orderNumber($a->order->order_number),
                'placed_at' => $a->order->placed_at?->toIso8601String(),
                'status' => $a->order->status->value,
                'status_label' => $a->order->status->label(),
                'currency' => $a->order->currency,
                'order_value_minor' => $a->order->subtotal_minor - $a->order->discount_minor,
                'source' => $a->source->value,
                'source_label' => $a->source->label(),
                'items' => $a->order->items->map(fn ($i) => ['name' => $i->name, 'quantity' => $i->quantity])->values(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $range = ReferralReport::range($data['from'] ?? null, $data['to'] ?? null);
        if ($range['from']->diffInDays($range['to']) > 366) {
            throw ValidationException::withMessages(['from' => 'Choose a range of at most one year.']);
        }

        return $range;
    }
}
