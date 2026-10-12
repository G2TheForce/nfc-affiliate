<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Shared name, email and password input for the account bootstrap commands.
 * There is no default password: the operator types one, and it must meet the policy.
 */
trait CreatesAccounts
{
    /**
     * @return array{name: string, email: string, password: string}|null null when validation failed (errors printed)
     */
    protected function askForAccount(): ?array
    {
        $name = $this->option('name') ?: text('Full name', required: true);
        $email = mb_strtolower(trim($this->option('email') ?: text('Email address', required: true)));
        if ($this->option('password-stdin')) {
            // Avoids putting the password in shell history or process lists.
            $password = $confirmation = rtrim((string) fgets($this->stdin()), "\r\n");
        } else {
            $password = password('Password (min 12 characters, mixed case and a number)', required: true);
            $confirmation = password('Confirm password', required: true);
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return null;
        }

        return ['name' => $name, 'email' => $email, 'password' => $password];
    }

    /**
     * @return resource
     */
    protected function stdin()
    {
        return app()->bound('admin.stdin') ? app('admin.stdin') : STDIN;
    }
}
