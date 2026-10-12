<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Staff\StaffAccounts;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Staff accounts (admins.manage). */
class StaffController extends Controller
{
    public function __construct(private readonly StaffAccounts $accounts) {}

    public function index(Request $request): array
    {
        $staffRoles = array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => $r->isStaff()));

        $staff = User::query()
            ->role($staffRoles)
            ->with('roles')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return [
            'data' => $staff->map(fn (User $u) => $this->present($u, $request->user()))->all(),
            'roles' => array_values(array_map(fn (string $r) => ['value' => $r, 'label' => Role::from($r)->label()], $staffRoles)),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        $user = $this->accounts->invite($data['name'], $data['email'], Role::from($data['role']), $request->user());

        return response()->json(['data' => $this->present($user->load('roles'), $request->user())], 201);
    }

    public function resendInvitation(Request $request, User $user): array
    {
        $this->assertStaff($user);
        if ($user->last_login_at !== null) {
            throw ValidationException::withMessages(['user' => 'This person has already signed in. They can use "Forgot password" if they need a new one.']);
        }
        $this->accounts->sendInvitation($user, $request->user());

        return ['data' => $this->present($user->load('roles'), $request->user())];
    }

    public function role(Request $request, User $user): array
    {
        $this->assertStaff($user);
        $staffRoles = array_values(array_filter(Role::cases(), fn (Role $r) => $r->isStaff()));
        $data = $request->validate([
            'role' => ['required', Rule::enum(Role::class)->only($staffRoles)],
        ]);

        $this->accounts->changeRole($user, Role::from($data['role']), $request->user());

        return ['data' => $this->present($user->refresh()->load('roles'), $request->user())];
    }

    public function status(Request $request, User $user): array
    {
        $this->assertStaff($user);
        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->accounts->setStatus($user, UserStatus::from($data['status']), $data['reason'], $request->user());

        return ['data' => $this->present($user->refresh()->load('roles'), $request->user())];
    }

    private function assertStaff(User $user): void
    {
        abort_unless($user->roles->contains(fn ($r) => Role::tryFrom($r->name)?->isStaff()), 404);
    }

    /** @return array<string, mixed> */
    private function present(User $user, User $viewer): array
    {
        $role = Role::tryFrom((string) $user->roles->first(fn ($r) => Role::tryFrom($r->name)?->isStaff())?->name);

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role?->value,
            'role_label' => $role?->label(),
            'status' => $user->status->value,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'is_you' => $user->is($viewer),
        ];
    }
}
