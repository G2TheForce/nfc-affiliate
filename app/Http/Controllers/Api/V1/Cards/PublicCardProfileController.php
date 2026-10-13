<?php

namespace App\Http\Controllers\Api\V1\Cards;

use App\Domain\Cards\CardProfilePresenter;
use App\Domain\Cards\CardProfileService;
use App\Domain\Cards\VCard;
use App\Http\Controllers\Controller;
use App\Models\CardProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Public tap profile pages: what an NFC card opens. */
class PublicCardProfileController extends Controller
{
    public function __construct(private readonly CardProfileService $service, private readonly CardProfilePresenter $presenter) {}

    public function show(Request $request, string $handle): array
    {
        $profile = self::findVisible($handle);
        $this->service->recordTap($profile, $request);

        return ['data' => $this->presenter->forPublic($profile)];
    }

    /** The SPA shell, with the person's name in the title and share preview. Unknown handles still get the shell, which shows "not found". */
    public function page(string $handle): View
    {
        $profile = CardProfile::query()->with('user')->where('handle', strtolower($handle))->where('is_published', true)->first();
        $meta = $profile && $profile->user?->isActive()
            ? ['title' => $profile->display_name, 'description' => implode(' · ', array_filter([$profile->headline, $profile->company])) ?: 'Tap profile']
            : null;

        return view('app', ['meta' => $meta]);
    }

    /** "Save contact": a vCard the phone's contacts app opens. */
    public function vcard(Request $request, string $handle, VCard $vcard): Response
    {
        $profile = self::findVisible($handle);
        $this->service->recordSave($profile, $request);

        return response($vcard->build($profile, $this->presenter), 200, [
            'Content-Type' => 'text/vcard; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$profile->handle.'.vcf"',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** Published, and the owner's account is active. Anything else is a plain 404. */
    public static function findVisible(string $handle): CardProfile
    {
        $profile = CardProfile::query()->with('user.affiliateProfile')
            ->where('handle', strtolower($handle))
            ->where('is_published', true)
            ->first();

        abort_unless($profile && $profile->user?->isActive(), 404);

        return $profile;
    }
}
