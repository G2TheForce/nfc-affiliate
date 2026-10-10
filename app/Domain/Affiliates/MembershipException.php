<?php

namespace App\Domain\Affiliates;

use Illuminate\Validation\ValidationException;

/**
 * A membership action that is not allowed in the current state. Rendered as
 * a 422 with a readable message.
 */
class MembershipException extends ValidationException
{
    public static function because(string $message, string $field = 'status'): self
    {
        return static::withMessages([$field => $message]);
    }
}
