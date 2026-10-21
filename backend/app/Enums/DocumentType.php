<?php

namespace App\Enums;

enum DocumentType: string
{
    case NationalId = 'NATIONAL_ID';
    case DrivingLicense = 'DRIVING_LICENSE';
    case Certificate = 'CERTIFICATE';
    case Other = 'OTHER';
}
