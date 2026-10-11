<?php

namespace App\Enums;

enum CommissionRuleKind: string
{
    // rate_bp of the line's net revenue (1000 = 10%).
    case Percent = 'percent';
    // fixed_minor per unit sold.
    case Fixed = 'fixed';
    // No commission on the matched products. Only for product and category scopes.
    case Exclude = 'exclude';
}
