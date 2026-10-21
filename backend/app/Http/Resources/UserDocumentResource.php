<?php

namespace App\Http\Resources;

use App\Models\UserDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserDocument */
class UserDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'docType' => $this->doc_type,
            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'sizeBytes' => $this->size_bytes,
            'verificationStatus' => $this->verification_status,
            'rejectionReason' => $this->rejection_reason,
            'verifiedAt' => $this->verified_at?->toIso8601String(),
            'uploadedBy' => $this->uploaded_by,
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'downloadUrl' => route('documents.download', $this->id),
        ];
    }
}
