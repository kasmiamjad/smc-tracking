<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\Department;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\DocumentStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DepartmentAndDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_department_crud_for_admin(): void
    {
        $this->actingAsUser(User::factory()->admin()->create());

        $id = $this->postJson('/api/v1/departments', ['name' => 'Plumbing', 'colorHex' => '#3b82f6', 'iconCode' => 'droplet'])
            ->assertCreated()->assertJsonPath('data.colorHex', '#3B82F6')->json('data.id');

        $this->postJson('/api/v1/departments', ['name' => 'Plumbing', 'colorHex' => 'blue'])
            ->assertJsonValidationErrors(['name', 'colorHex']);

        $this->putJson("/api/v1/departments/{$id}", ['colorHex' => '#EF4444'])->assertOk()->assertJsonPath('data.name', 'Plumbing');

        $member = User::factory()->create(['department_id' => $id]);
        $this->deleteJson("/api/v1/departments/{$id}")->assertNoContent();
        $this->assertNull($member->fresh()->department_id);
    }

    public function test_department_list_is_public_but_writes_need_admin(): void
    {
        Department::factory()->count(2)->create();

        $this->getJson('/api/v1/departments')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.usersCount');
        $this->postJson('/api/v1/departments', ['name' => 'X', 'colorHex' => '#000000'])->assertUnauthorized();
        $this->actingAsUser(User::factory()->dispatcher()->create())
            ->postJson('/api/v1/departments', ['name' => 'X', 'colorHex' => '#000000'])->assertForbidden();
    }

    public function test_technician_uploads_lists_and_downloads_own_document(): void
    {
        Storage::fake('local');
        $technician = User::factory()->create();
        $this->actingAsUser($technician);

        $doc = $this->post("/api/v1/users/{$technician->id}/documents", [
            'file' => UploadedFile::fake()->image('licence.jpg'),
            'docType' => 'DRIVING_LICENSE',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.verificationStatus', 'PENDING')->json('data');

        $this->getJson("/api/v1/users/{$technician->id}/documents")->assertJsonCount(1, 'data');
        $this->get($doc['downloadUrl'])->assertOk()->assertDownload('licence.jpg');
    }

    public function test_documents_are_private_to_owner_and_console(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $document = $this->makeDocument($owner);

        $this->actingAsUser(User::factory()->create());
        $this->getJson("/api/v1/users/{$owner->id}/documents")->assertForbidden();
        $this->getJson("/api/v1/documents/{$document->id}/download")->assertForbidden();
        $this->post("/api/v1/users/{$owner->id}/documents", ['file' => UploadedFile::fake()->image('x.jpg'), 'docType' => 'OTHER'], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAsUser(User::factory()->dispatcher()->create());
        $this->getJson("/api/v1/documents/{$document->id}/download")->assertOk();
    }

    public function test_upload_rejects_disallowed_file_types(): void
    {
        Storage::fake('local');
        $technician = User::factory()->create();

        $this->actingAsUser($technician)->post("/api/v1/users/{$technician->id}/documents", [
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream'),
            'docType' => 'OTHER',
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('file');
    }

    public function test_admin_verifies_and_owner_cannot_delete_verified_document(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $document = $this->makeDocument($owner);

        $this->actingAsUser(User::factory()->admin()->create())
            ->patchJson("/api/v1/admin/documents/{$document->id}/verification", ['status' => 'APPROVED'])
            ->assertOk()->assertJsonPath('data.verificationStatus', 'APPROVED');

        $this->actingAsUser($owner)->deleteJson("/api/v1/documents/{$document->id}")->assertForbidden();
    }

    public function test_owner_deletes_pending_document_and_file_is_removed(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $document = $this->makeDocument($owner);

        $this->actingAsUser($owner)->deleteJson("/api/v1/documents/{$document->id}")->assertNoContent();

        Storage::disk('local')->assertMissing($document->path);
    }

    private function makeDocument(User $owner): UserDocument
    {
        return app(DocumentStorage::class)->store(
            $owner, UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'), DocumentType::NationalId, $owner,
        );
    }
}
