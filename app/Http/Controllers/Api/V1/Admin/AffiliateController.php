<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Affiliates\AffiliateMembershipService;
use App\Domain\Affiliates\DuplicateSignals;
use App\Enums\AffiliateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MembershipActionRequest;
use App\Http\Resources\Admin\AffiliateDetailResource;
use App\Http\Resources\Admin\AffiliateListItemResource;
use App\Models\AffiliateProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AffiliateController extends Controller
{
    public function __construct(
        private readonly AffiliateMembershipService $membership,
        private readonly DuplicateSignals $duplicates,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(AffiliateStatus::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $profiles = AffiliateProfile::query()
            ->with('user')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($q) => $q
                    ->where('display_name', 'like', $like)
                    ->orWhere('referral_code', $search)
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $like)->orWhere('name', 'like', $like)));
            })
            // Oldest pending applications first so nobody waits forever.
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")
            ->orderBy('applied_at')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return AffiliateListItemResource::collection($profiles);
    }

    public function counts(): array
    {
        $counts = AffiliateProfile::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return ['data' => collect(AffiliateStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)])];
    }

    public function show(AffiliateProfile $affiliate): AffiliateDetailResource
    {
        return $this->detail($affiliate);
    }

    public function approve(MembershipActionRequest $request, AffiliateProfile $affiliate): AffiliateDetailResource
    {
        $this->membership->approve($affiliate, $request->user(), $request->validated('internal_note'));

        return $this->detail($affiliate);
    }

    public function reject(MembershipActionRequest $request, AffiliateProfile $affiliate): AffiliateDetailResource
    {
        $this->membership->reject($affiliate, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->detail($affiliate);
    }

    public function suspend(MembershipActionRequest $request, AffiliateProfile $affiliate): AffiliateDetailResource
    {
        $this->membership->suspend($affiliate, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->detail($affiliate);
    }

    public function reinstate(MembershipActionRequest $request, AffiliateProfile $affiliate): AffiliateDetailResource
    {
        $this->membership->reinstate($affiliate, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->detail($affiliate);
    }

    public function deactivate(MembershipActionRequest $request, AffiliateProfile $affiliate): AffiliateDetailResource
    {
        $this->membership->deactivate($affiliate, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->detail($affiliate);
    }

    public function reactivate(MembershipActionRequest $request, AffiliateProfile $affiliate): AffiliateDetailResource
    {
        $this->membership->reactivate($affiliate, $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->detail($affiliate);
    }

    public function addNote(Request $request, AffiliateProfile $affiliate): AffiliateDetailResource
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000']]);
        $this->membership->addNote($affiliate, $request->user(), $data['body']);

        return $this->detail($affiliate);
    }

    private function detail(AffiliateProfile $affiliate): AffiliateDetailResource
    {
        $affiliate->refresh()->load(['user', 'approver', 'statusHistory.actor', 'notes.author', 'termsAcceptances.termsVersion']);

        return new AffiliateDetailResource($affiliate, $this->duplicates->for($affiliate));
    }
}
