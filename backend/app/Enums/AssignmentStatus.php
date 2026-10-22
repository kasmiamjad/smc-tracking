<?php

namespace App\Enums;

enum AssignmentStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';       // checked in, telemetry flowing
    case Completed = 'COMPLETED'; // checked out (manually or automatically)
    case Absent = 'ABSENT';       // shift ended without a check-in
}
