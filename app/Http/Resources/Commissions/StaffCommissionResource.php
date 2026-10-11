<?php

namespace App\Http\Resources\Commissions;

/**
 * Staff view of a commission: full order number, affiliate, rule and hold reason.
 */
class StaffCommissionResource extends CommissionResource
{
    protected bool $staff = true;
}
