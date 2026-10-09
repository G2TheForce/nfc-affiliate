<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Target of the signed link in the verification email. The `signed`
     * middleware has already rejected tampered or expired links.
     */
    public function verify(Request $request, User $user, string $hash): JsonResponse|RedirectResponse
    {
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $this->audit->log('auth.email_verified', $user, [], $user);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Email verified.']);
        }

        return redirect()->away(rtrim((string) config('affiliate.frontend_url'), '/').'/email-verified');
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification link sent.'], 202);
    }
}
