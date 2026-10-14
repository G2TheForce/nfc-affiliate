<?php

namespace App\Support;

/**
 * Phone numbers are stored in E.164 (+9779812345678). People in Nepal type
 * their mobile number the local way (981-234 5678), so before validation
 * spaces, dashes, dots and brackets are dropped, a leading 00 becomes +, and
 * a ten-digit Nepali mobile number (98..., 97..., 96...) gets +977.
 * Anything else is left for the validator to reject.
 */
final class PhoneNumber
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $compact = preg_replace('/[\s\-.()]/', '', trim($value));
        if ($compact === '') {
            return $compact;
        }
        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }
        if (preg_match('/^(?:977)?(9[678]\d{8})$/', $compact, $m)) {
            return '+977'.$m[1];
        }

        return $compact;
    }
}
