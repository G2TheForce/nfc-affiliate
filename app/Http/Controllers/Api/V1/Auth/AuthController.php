<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $this->ensureClientCanAuthenticate($request);

        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'phone', 'password']));
            $user->assignRole(Role::Customer->value);
            $this->audit->log('auth.registered', $user, [], $user);

            return $user;
        });

        event(new Registered($user));

        return $this->authenticated($request, $user, 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $this->ensureClientCanAuthenticate($request);

        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            $this->audit->log('auth.login_failed', $user, ['reason' => 'invalid_credentials']);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->isActive()) {
            $this->audit->log('auth.login_failed', $user, ['reason' => 'account_disabled']);

            throw ValidationException::withMessages([
                'email' => 'This account is disabled. Please contact support.',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('auth.login', $user, [
            'mode' => $request->filled('device_name') ? 'token' : 'session',
        ], $user);

        return $this->authenticated($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        $this->audit->log('auth.logout', $user, [], $user);

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Fails before anything is written when the caller can neither receive a
     * token (no device_name) nor hold a session (not the first-party SPA).
     */
    private function ensureClientCanAuthenticate(Request $request): void
    {
        if (! $request->filled('device_name') && ! $request->hasSession()) {
            throw ValidationException::withMessages([
                'device_name' => 'device_name is required when not signing in from the web app.',
            ]);
        }
    }

    /**
     * Mobile/API clients send device_name and receive a bearer token.
     * The first-party SPA uses the Sanctum session cookie instead.
     */
    private function authenticated(Request $request, User $user, int $status = 200): JsonResponse
    {
        if ($request->filled('device_name')) {
            $token = $user->createToken((string) $request->input('device_name'))->plainTextToken;

            return response()->json([
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
            ], $status);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => new UserResource($user)], $status);
    }
}
