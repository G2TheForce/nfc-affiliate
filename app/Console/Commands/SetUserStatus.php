<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Disables or re-enables any account, for example when a staff member leaves.
 * Disabling signs the account out everywhere: its API tokens and sessions are deleted.
 */
class SetUserStatus extends Command
{
    protected $signature = 'affiliate:set-user-status
        {email : The account\'s email address}
        {status : active or disabled}
        {--reason= : Why (stored in the audit log, at least 5 characters)}';

    protected $description = 'Disable or re-enable an account (signs a disabled account out everywhere)';

    public function handle(AuditLogger $audit): int
    {
        $status = UserStatus::tryFrom((string) $this->argument('status'));
        $reason = trim((string) $this->option('reason'));
        if ($status === null) {
            $this->error('Status must be active or disabled.');

            return self::FAILURE;
        }
        if (mb_strlen($reason) < 5) {
            $this->error('Give a --reason of at least 5 characters.');

            return self::FAILURE;
        }

        $user = User::where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('No account has that email address.');

            return self::FAILURE;
        }

        if ($status === UserStatus::Disabled && $user->hasRole(Role::SuperAdmin->value)
            && User::role(Role::SuperAdmin->value)->where('status', UserStatus::Active->value)->whereKeyNot($user->getKey())->doesntExist()) {
            $this->error('This is the only active super administrator. Create another one first.');

            return self::FAILURE;
        }

        if ($user->status === $status) {
            $this->info("{$user->email} is already {$status->value}.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($user, $status, $reason, $audit) {
            $previous = $user->status->value;
            $user->forceFill(['status' => $status])->save();
            if ($status === UserStatus::Disabled) {
                $user->tokens()->delete();
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }
            $audit->log('user.status_changed', $user, ['via' => 'console', 'from' => $previous, 'to' => $status->value, 'reason' => $reason], null);
        });

        $this->info("{$user->email} is now {$status->value}.");

        return self::SUCCESS;
    }
}
