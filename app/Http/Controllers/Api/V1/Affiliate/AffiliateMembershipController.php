<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Domain\Affiliates\AffiliateMembershipService;
use App\Domain\Affiliates\MembershipException;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Affiliate\ApplyRequest;
use App\Http\Requests\Affiliate\UpdateProfileRequest;
use App\Http\Resources\AffiliateProfileResource;
use App\Models\AffiliateProfile;
use App\Models\AffiliateTermsVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Member-facing endpoints. Every action works on the signed-in user's own
 * profile; no profile id is ever taken from the request.
 */
class AffiliateMembershipController extends Controller
{
    public function __construct(private readonly AffiliateMembershipService $membership) {}

    public function currentTerms(): JsonResponse
    {
        $terms = AffiliateTermsVersion::current();

        if (! $terms) {
            return response()->json(['data' => null, 'message' => 'The affiliate terms have not been published yet.']);
        }

        return response()->json(['data' => [
            'version' => $terms->version,
            'title' => $terms->title,
            'body' => $terms->body,
            'published_at' => $terms->published_at->toIso8601String(),
        ]]);
    }

    public function apply(ApplyRequest $request): JsonResponse
    {
        $terms = AffiliateTermsVersion::current();

        if (! $terms) {
            throw MembershipException::because('Applications open once the affiliate terms are published.', 'terms_version');
        }
        if ($request->validated('terms_version') !== $terms->version) {
            throw MembershipException::because('The affiliate terms have changed. Please review the latest version and accept it.', 'terms_version');
        }

        $details = collect($request->validated())->except(['terms_version', 'accept_terms'])->all();
        $profile = $this->membership->apply($request->user(), $details, $terms);

        return (new AffiliateProfileResource($this->load($profile)))->response()->setStatusCode(201);
    }

    public function show(Request $request): AffiliateProfileResource|JsonResponse
    {
        $profile = AffiliateProfile::where('user_id', $request->user()->id)->first();

        if (! $profile) {
            return response()->json(['data' => null]);
        }

        return new AffiliateProfileResource($this->load($profile));
    }

    public function update(UpdateProfileRequest $request, AuditLogger $audit): AffiliateProfileResource
    {
        $profile = $this->ownProfile($request);

        if (! in_array($profile->status->value, ['pending', 'approved', 'suspended'], true)) {
            throw MembershipException::because('This profile can no longer be edited.');
        }

        $profile->fill($request->validated());
        $changed = array_keys($profile->getDirty());
        $profile->save();

        if ($changed !== []) {
            $audit->log('affiliate.profile_updated', $profile, ['fields' => $changed]);
        }

        return new AffiliateProfileResource($this->load($profile));
    }

    public function acceptTerms(Request $request): AffiliateProfileResource
    {
        $request->validate(['terms_version' => ['required', 'string', 'max:40'], 'accept_terms' => ['accepted']]);
        $profile = $this->ownProfile($request);
        $terms = AffiliateTermsVersion::current();

        if (! $terms || $terms->version !== $request->input('terms_version')) {
            throw MembershipException::because('Those are not the current affiliate terms.', 'terms_version');
        }

        $this->membership->acceptTerms($profile, $terms);

        return new AffiliateProfileResource($this->load($profile->fresh()));
    }

    public function close(Request $request): AffiliateProfileResource
    {
        $request->validate([
            'password' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        $profile = $this->membership->closeOwnMembership($this->ownProfile($request), $request->user(), $request->input('reason'));

        return new AffiliateProfileResource($this->load($profile));
    }

    private function ownProfile(Request $request): AffiliateProfile
    {
        return AffiliateProfile::where('user_id', $request->user()->id)->first()
            ?? abort(404, 'You have not applied to the affiliate programme.');
    }

    private function load(AffiliateProfile $profile): AffiliateProfile
    {
        return $profile->load(['statusHistory', 'termsAcceptances.termsVersion']);
    }
}
