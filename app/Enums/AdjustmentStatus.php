<?php

namespace App\Enums;

enum AdjustmentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
