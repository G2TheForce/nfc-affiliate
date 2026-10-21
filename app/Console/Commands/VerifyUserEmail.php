<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Command;

/**
 * Marks an account's email address as verified, for when the verification
 * email cannot arrive: no mail server or queue worker on a local machine, or
 * support has confirmed the address another way.
 */
class VerifyUserEmail extends Command
{
    protected $signature = 'affiliate:verify-email {email : The account\'s email address}';

    protected $description = 'Mark an account\'s email address as verified (audited)';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();
        if ($user === null) {
            $this->error('No account has that email address.');

            return self::FAILURE;
        }
        if ($user->hasVerifiedEmail()) {
            $this->info("{$user->email} is already verified.");

            return self::SUCCESS;
        }

        $user->markEmailAsVerified();
        event(new Verified($user));
        $audit->log('auth.email_verified', $user, ['via' => 'console']);

        $this->info("{$user->email} is now verified. They can sign in and carry on.");

        return self::SUCCESS;
    }
}
