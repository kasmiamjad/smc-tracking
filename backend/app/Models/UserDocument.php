<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['user_id', 'uploaded_by', 'doc_type', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes'])]
class UserDocument extends Model
{
    use HasUuids;

    protected $attributes = ['verification_status' => 'PENDING'];

    protected function casts(): array
    {
        return [
            'doc_type' => DocumentType::class,
            'verification_status' => VerificationStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (UserDocument $doc) => Storage::disk($doc->disk)->delete($doc->path));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
