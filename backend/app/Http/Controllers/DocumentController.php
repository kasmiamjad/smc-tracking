<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Http\Resources\UserDocumentResource;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** MOD-04: optional documents. Never a gate for registration or approval. */
class DocumentController extends Controller
{
    public function index(User $user): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [UserDocument::class, $user]);

        return UserDocumentResource::collection($user->documents()->latest()->get());
    }

    public function store(Request $request, User $user, DocumentStorage $storage): JsonResponse
    {
        Gate::authorize('create', [UserDocument::class, $user]);

        $data = $request->validate([
            'file' => ['required', ...DocumentStorage::rules()],
            'docType' => ['required', Rule::enum(DocumentType::class)],
        ]);

        $document = $storage->store($user, $request->file('file'), DocumentType::from($data['docType']), $request->user());

        return (new UserDocumentResource($document))->response()->setStatusCode(201);
    }

    public function download(UserDocument $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        return Storage::disk($document->disk)->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
        ]);
    }

    public function destroy(UserDocument $document): Response
    {
        Gate::authorize('delete', $document);
        $document->delete();

        return response()->noContent();
    }

    public function verify(Request $request, UserDocument $document): UserDocumentResource
    {
        Gate::authorize('verify', $document);

        $data = $request->validate([
            'status' => ['required', Rule::in([VerificationStatus::Approved->value, VerificationStatus::Rejected->value])],
            'reason' => ['required_if:status,REJECTED', 'nullable', 'string', 'max:500'],
        ]);

        $document->forceFill([
            'verification_status' => $data['status'],
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'rejection_reason' => $data['status'] === VerificationStatus::Rejected->value ? $data['reason'] : null,
        ])->save();

        return new UserDocumentResource($document);
    }
}
