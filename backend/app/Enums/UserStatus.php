<?php

namespace App\Enums;

/**
 * Approval lifecycle only. Blocking is tracked separately (users.blocked_at)
 * so that unblocking restores whatever approval state the user had before.
 */
enum UserStatus: string
{
    case PendingApproval = 'PENDING_APPROVAL';
    case Active = 'ACTIVE';
    case Rejected = 'REJECTED';
}
