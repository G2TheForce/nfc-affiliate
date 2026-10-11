<?php

namespace App\Domain\Commissions;

use App\Enums\CommissionRuleKind;
use InvalidArgumentException;

/**
 * Integer-only commission arithmetic (ADR 0001). Rounding is half-up to the
 * nearest minor unit, applied once per commission line.
 */
final class CommissionMath
{
    /** rate_bp of base, half-up. 1000 bp = 10%. */
    public static function percent(int $baseMinor, int $rateBp): int
    {
        self::assertNonNegative($baseMinor, $rateBp);

        return intdiv($baseMinor * $rateBp + 5000, 10000);
    }

    /** amount × part / whole, half-up. */
    public static function prorate(int $amountMinor, int $part, int $whole): int
    {
        self::assertNonNegative($amountMinor, $part);
        if ($whole <= 0) {
            throw new InvalidArgumentException('Whole must be positive.');
        }

        return intdiv(2 * $amountMinor * $part + $whole, 2 * $whole);
    }

    /**
     * The commission for a line. A fixed amount never exceeds the line's
     * revenue, so a fixed rule cannot pay more than the customer paid.
     */
    public static function amount(CommissionRuleKind $kind, int $baseMinor, int $quantity, ?int $rateBp, ?int $fixedMinor): int
    {
        return match ($kind) {
            CommissionRuleKind::Percent => self::percent($baseMinor, (int) $rateBp),
            CommissionRuleKind::Fixed => min((int) $fixedMinor * $quantity, $baseMinor),
            CommissionRuleKind::Exclude => 0,
        };
    }

    /**
     * What a commission is worth once part of its line was refunded:
     * recomputed on the remaining base with the snapshotted rule, so repeated
     * partial refunds never drift through rounding.
     */
    public static function remainingAfterRefund(CommissionRuleKind $kind, int $originalAmount, int $baseMinor, int $refundedMinor, ?int $rateBp): int
    {
        $remainingBase = max(0, $baseMinor - $refundedMinor);
        if ($remainingBase === 0 || $baseMinor === 0) {
            return 0;
        }

        return match ($kind) {
            CommissionRuleKind::Percent => self::percent($remainingBase, (int) $rateBp),
            CommissionRuleKind::Fixed => self::prorate($originalAmount, $remainingBase, $baseMinor),
            CommissionRuleKind::Exclude => 0,
        };
    }

    private static function assertNonNegative(int ...$values): void
    {
        foreach ($values as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Amounts and rates must not be negative.');
            }
        }
    }
}
