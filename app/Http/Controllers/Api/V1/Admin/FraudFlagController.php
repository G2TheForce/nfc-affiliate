<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fraud\FraudDetector;
use App\Enums\FraudFlagStatus;
use App\Http\Controllers\Controller;
use App\Models\FraudFlag;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The fraud review queue (fraud.review). */
class FraudFlagController extends Controller
{
    public function __construct(private readonly FraudDetector $detector) {}

    public function index(Request $request): array
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'dismissed', 'confirmed', 'all'])],
            'affiliate_id' => ['nullable', 'string', 'max:26'],
        ]);
        $status = $filters['status'] ?? 'open';

        $flags = FraudFlag::query()
            ->with(['affiliate', 'reviewer'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($filters['affiliate_id'] ?? null, fn ($q, $id) => $q->whereHas('affiliate', fn ($a) => $a->where('public_id', $id)))
            ->orderByDesc('detected_at')->orderByDesc('id')
            ->paginate(25);

        return [
            'data' => $flags->getCollection()->map(fn (FraudFlag $f) => self::present($f))->all(),
            'meta' => [
                'current_page' => $flags->currentPage(),
                'last_page' => $flags->lastPage(),
                'total' => $flags->total(),
                'open' => FraudFlag::query()->where('status', FraudFlagStatus::Open->value)->count(),
            ],
        ];
    }

    public function review(Request $request, FraudFlag $flag): array
    {
        $data = $request->validate([
            'outcome' => ['required', Rule::in([FraudFlagStatus::Dismissed->value, FraudFlagStatus::Confirmed->value])],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $flag = $this->detector->review($flag, $request->user(), FraudFlagStatus::from($data['outcome']), $data['note']);

        return ['data' => self::present($flag->load(['affiliate', 'reviewer']))];
    }

    /** @return array<string, mixed> */
    public static function present(FraudFlag $flag): array
    {
        return [
            'id' => $flag->public_id,
            'signal' => $flag->signal->value,
            'signal_label' => $flag->signal->label(),
            'guidance' => $flag->signal->guidance(),
            'severity' => $flag->severity,
            'details' => $flag->details,
            'subject' => $flag->subject_type ? ['type' => $flag->subject_type, 'id' => $flag->subject_id] : null,
            'status' => $flag->status->value,
            'status_label' => $flag->status->label(),
            'affiliate' => $flag->affiliate ? [
                'id' => $flag->affiliate->public_id,
                'display_name' => $flag->affiliate->display_name,
                'referral_code' => $flag->affiliate->referral_code,
            ] : null,
            'detected_at' => $flag->detected_at?->toIso8601String(),
            'reviewed_by' => $flag->reviewer?->name,
            'reviewed_at' => $flag->reviewed_at?->toIso8601String(),
            'review_note' => $flag->review_note,
        ];
    }
}
