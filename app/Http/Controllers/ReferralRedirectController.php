<?php

namespace App\Http\Controllers;

use App\Domain\Referrals\ReferralTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /ref/{code}/{link?}: the public referral link. Always redirects to an
 * internal store path, whatever the outcome of tracking.
 */
class ReferralRedirectController extends Controller
{
    public function __invoke(Request $request, ReferralTracker $tracker, string $code, ?string $link = null): RedirectResponse
    {
        $result = $tracker->handle($request, $code, $link);

        $response = redirect()->to($result['path'], 302)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);

        foreach ($result['cookies'] as $cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }
}
