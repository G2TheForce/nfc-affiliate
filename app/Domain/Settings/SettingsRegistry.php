<?php

namespace App\Domain\Settings;

/**
 * The programme settings staff may change on screen. Each key is a path in
 * config/affiliate.php. Secrets, payment keys and technical settings stay
 * in .env on purpose.
 */
final class SettingsRegistry
{
    /**
     * type: int (whole number), money (paisa), bool, or choice.
     *
     * @return array<string, array{group: string, label: string, help: string, type: string, min?: int, max?: int, choices?: array<string, string>}>
     */
    public static function definitions(): array
    {
        return [
            'attribution.policy' => [
                'group' => 'Referrals', 'label' => 'Which click gets the order', 'type' => 'choice',
                'choices' => ['last_click' => 'The most recent referral click', 'first_click' => 'The first referral click in the window'],
                'help' => 'Applies to clicks from now on.',
            ],
            'attribution.window_days' => [
                'group' => 'Referrals', 'label' => 'Attribution window (days)', 'type' => 'int', 'min' => 1, 'max' => 365,
                'help' => 'How long after a click an order still counts for the affiliate.',
            ],
            'attribution.coupon_overrides_click' => [
                'group' => 'Referrals', 'label' => 'An affiliate coupon beats an earlier click', 'type' => 'bool',
                'help' => 'When off, the clicked link keeps the order even if the customer uses another affiliate\'s coupon.',
            ],
            'applications.reapply_cooldown_days' => [
                'group' => 'Referrals', 'label' => 'Days before a rejected applicant may apply again', 'type' => 'int', 'min' => 0, 'max' => 365,
                'help' => '',
            ],
            'commissions.holding_period_days' => [
                'group' => 'Commission', 'label' => 'Holding period (days)', 'type' => 'int', 'min' => 0, 'max' => 180,
                'help' => 'Days after payment before commission can be approved. Applies to orders paid from now on.',
            ],
            'commissions.auto_release' => [
                'group' => 'Commission', 'label' => 'Release commission automatically after the holding period', 'type' => 'bool',
                'help' => 'When off, finance releases approved commission by hand.',
            ],
            'payouts.minimum_minor' => [
                'group' => 'Payouts', 'label' => 'Minimum payout', 'type' => 'money', 'min' => 0, 'max' => 100000000,
                'help' => 'The smallest balance an affiliate can ask to be paid.',
            ],
            'payouts.require_separate_payer' => [
                'group' => 'Payouts', 'label' => 'A different person must mark a payout paid', 'type' => 'bool',
                'help' => 'When on, whoever approved a payout cannot also mark it paid.',
            ],
            'store.shipping_flat_minor' => [
                'group' => 'Store', 'label' => 'Delivery charge per order', 'type' => 'money', 'min' => 0, 'max' => 10000000,
                'help' => 'Never part of the commission base.',
            ],
            'fraud.conversion_max_percent' => [
                'group' => 'Fraud alerts', 'label' => 'Flag conversion above (%)', 'type' => 'int', 'min' => 1, 'max' => 100,
                'help' => 'Paid orders per 100 unique clicks over 30 days.',
            ],
            'fraud.conversion_min_orders' => [
                'group' => 'Fraud alerts', 'label' => '…only with at least this many orders', 'type' => 'int', 'min' => 1, 'max' => 10000, 'help' => '',
            ],
            'fraud.clicks_without_orders' => [
                'group' => 'Fraud alerts', 'label' => 'Flag unique clicks in 30 days with no paid order', 'type' => 'int', 'min' => 10, 'max' => 10000000, 'help' => '',
            ],
            'fraud.refund_rate_max_percent' => [
                'group' => 'Fraud alerts', 'label' => 'Flag refund rate above (%)', 'type' => 'int', 'min' => 1, 'max' => 100,
                'help' => 'Share of paid referred orders refunded over 90 days.',
            ],
            'fraud.refund_rate_min_orders' => [
                'group' => 'Fraud alerts', 'label' => '…only with at least this many orders', 'type' => 'int', 'min' => 1, 'max' => 10000, 'help' => '',
            ],
            'fraud.earnings_spike_multiple' => [
                'group' => 'Fraud alerts', 'label' => 'Flag a week\'s commission above this many times the usual', 'type' => 'int', 'min' => 2, 'max' => 100, 'help' => '',
            ],
            'fraud.earnings_spike_min_minor' => [
                'group' => 'Fraud alerts', 'label' => '…only when the week\'s commission is at least', 'type' => 'money', 'min' => 0, 'max' => 10000000000, 'help' => '',
            ],
            'fraud.large_payout_minor' => [
                'group' => 'Fraud alerts', 'label' => 'Flag payout requests of at least', 'type' => 'money', 'min' => 100, 'max' => 10000000000, 'help' => '',
            ],
            'fraud.detail_change_days' => [
                'group' => 'Fraud alerts', 'label' => 'Flag a payout within this many days of new payout details', 'type' => 'int', 'min' => 0, 'max' => 90, 'help' => '',
            ],
        ];
    }

    public static function configKey(string $key): string
    {
        return 'affiliate.'.$key;
    }

    /** @return list<mixed> validation rules for one setting's value */
    public static function rulesFor(string $key): array
    {
        $d = self::definitions()[$key];

        return match ($d['type']) {
            'bool' => ['required', 'boolean'],
            'choice' => ['required', 'string', 'in:'.implode(',', array_keys($d['choices']))],
            default => ['required', 'integer', 'min:'.$d['min'], 'max:'.$d['max']],
        };
    }

    public static function cast(string $key, mixed $value): mixed
    {
        return match (self::definitions()[$key]['type']) {
            'bool' => (bool) $value,
            'choice' => (string) $value,
            default => (int) $value,
        };
    }
}
