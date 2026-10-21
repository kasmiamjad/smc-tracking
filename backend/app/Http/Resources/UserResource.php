<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fullName' => $this->full_name,
            'role' => $this->role,
            'status' => $this->status,
            'phone' => $this->phone_number,
            'email' => $this->email,
            'departmentId' => $this->department_id,
            'department' => new DepartmentResource($this->whenLoaded('department')),
            'mustChangePin' => $this->must_change_pin,
            'isBlocked' => $this->isBlocked(),
            'blockedAt' => $this->blocked_at?->toIso8601String(),
            'blockReason' => $this->block_reason,
            'isLocked' => $this->isLocked(),
            'approvedAt' => $this->approved_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'lastLoginAt' => $this->last_login_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'documents' => UserDocumentResource::collection($this->whenLoaded('documents')),
        ];
    }
}
