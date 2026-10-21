<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Files live on a private disk (local in dev, S3/R2/MinIO in production via
 * DOCUMENTS_DISK) and are only ever served through the authorised download route.
 */
class DocumentStorage
{
    public function store(User $owner, UploadedFile $file, DocumentType $type, ?User $uploader): UserDocument
    {
        $disk = config('smc.documents.disk');
        $name = Str::uuid().'.'.strtolower($file->extension());
        $path = $file->storeAs('documents/'.$owner->id, $name, $disk);

        return UserDocument::create([
            'user_id' => $owner->id,
            'uploaded_by' => $uploader?->id,
            'doc_type' => $type,
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
    }

    public static function rules(): array
    {
        return ['file', 'mimes:'.implode(',', config('smc.documents.mimes')), 'max:'.config('smc.documents.max_size_kb')];
    }
}
