<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'ADMIN';
    case Dispatcher = 'DISPATCHER';
    case Technician = 'TECHNICIAN';

    /** Console users sign in with email + password; technicians use phone + PIN. */
    public function usesConsole(): bool
    {
        return $this !== self::Technician;
    }
}
