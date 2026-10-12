<?php

namespace App\Console\Commands;

use App\Domain\Staff\StaffAccounts;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

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

    public function handle(StaffAccounts $accounts): int
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

        try {
            $changed = $accounts->setStatus($user, $status, $reason, null, via: 'console');
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }
        if (! $changed) {
            $this->info("{$user->email} is already {$status->value}.");

            return self::SUCCESS;
        }

        $this->info("{$user->email} is now {$status->value}.");

        return self::SUCCESS;
    }
}
