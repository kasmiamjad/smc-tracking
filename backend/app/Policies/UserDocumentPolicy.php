<?php

namespace App\Policies;

use App\Enums\VerificationStatus;
use App\Models\User;
use App\Models\UserDocument;

/**
 * Technicians manage their own documents; console users can view anyone's;
 * only admins upload on someone else's behalf, verify, or delete verified files.
 */
class UserDocumentPolicy
{
    public function viewAny(User $actor, User $owner): bool
    {
        return $actor->is($owner) || $actor->role->usesConsole();
    }

    public function create(User $actor, User $owner): bool
    {
        return $actor->is($owner) || $actor->isAdmin();
    }

    public function view(User $actor, UserDocument $document): bool
    {
        return $actor->id === $document->user_id || $actor->role->usesConsole();
    }

    public function delete(User $actor, UserDocument $document): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        return $actor->id === $document->user_id && $document->verification_status === VerificationStatus::Pending;
    }

    public function verify(User $actor, UserDocument $document): bool
    {
        return $actor->isAdmin();
    }
}
