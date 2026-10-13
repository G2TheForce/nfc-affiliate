<?php

namespace App\Http\Controllers\Api\V1\Cards;

use App\Domain\Cards\CardProfilePresenter;
use App\Domain\Cards\CardProfileService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\SaveCardProfileRequest;
use Illuminate\Http\Request;

/** The signed-in user's own tap profile. */
class MyCardProfileController extends Controller
{
    public function __construct(private readonly CardProfileService $service, private readonly CardProfilePresenter $presenter) {}

    public function show(Request $request): array
    {
        $profile = $request->user()->cardProfile;

        return ['data' => $profile ? $this->presenter->forOwner($profile) : null];
    }

    public function update(SaveCardProfileRequest $request): array
    {
        $profile = $this->service->save($request->user(), $request->validated());

        return ['data' => $this->presenter->forOwner($profile->refresh())];
    }

    public function stats(Request $request): array
    {
        $profile = $request->user()->cardProfile;
        abort_unless($profile, 404, 'Create your tap profile first.');

        return ['data' => [
            'taps_total' => $profile->taps_total,
            'saves_total' => $profile->saves_total,
            'daily' => $this->service->daily($profile, 30),
        ]];
    }
}
