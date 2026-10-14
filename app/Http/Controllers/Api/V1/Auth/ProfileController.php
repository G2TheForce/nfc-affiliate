<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Notifications\Auth\PasswordChanged;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in person's own name, phone and password. Email changes go
 * through support, because the address is how an account is recovered.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(Request $request): UserResource
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => PhoneNumber::normalize($request->input('phone'))]);
        }
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
        ], ['phone.regex' => 'Enter a mobile number such as 9812345678, or include the country code, for example +9779812345678.']);

        $user = $request->user();
        $user->fill($data);
        $changed = array_keys($user->getDirty());

        if ($changed !== []) {
            DB::transaction(function () use ($user, $changed) {
                $user->save();
                // Field names only: the audit log keeps no personal details.
                $this->audit->log('user.profile_updated', $user, ['fields' => $changed], $user);
            });
        }

        return new UserResource($user);
    }

    /**
     * Changing the password keeps this session and signs out every other
     * session and API token.
     */
    public function password(Request $request): UserResource
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'current_password.current_password' => 'Your current password is not right.',
            'password.different' => 'Choose a password you have not been using.',
        ]);

        $user = $request->user();
        DB::transaction(function () use ($user, $request) {
            $user->forceFill(['password' => Hash::make($request->input('password'))])->save();
            $current = $user->currentAccessToken();
            $user->tokens()->when($current && method_exists($current, 'getKey'), fn ($q) => $q->whereKeyNot($current->getKey()))->delete();
            if ($request->hasSession()) {
                DB::table('sessions')->where('user_id', $user->getKey())->where('id', '!=', $request->session()->getId())->delete();
                $request->session()->regenerate();
            } else {
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }
            $this->audit->log('auth.password_changed', $user, [], $user);
        });
        $user->notify(new PasswordChanged);

        return new UserResource($user);
    }
}
