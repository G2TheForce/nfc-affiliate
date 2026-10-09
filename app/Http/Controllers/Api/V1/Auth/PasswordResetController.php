<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Always answers the same way so the endpoint cannot be used to find out
     * which email addresses have accounts.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => mb_strtolower($request->validated('email'))]);

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            [
                'email' => mb_strtolower($request->validated('email')),
                'password' => $request->validated('password'),
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $request->validated('token'),
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Sign out every API client after a password change.
                $user->tokens()->delete();

                event(new PasswordReset($user));
                $this->audit->log('auth.password_reset', $user, [], $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }
}
