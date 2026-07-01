<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CrmFolder;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CRM Files API feature tests.
 *
 * Covers:
 *  - System folders seeded lazily on first GET /folders:
 *    company = 3 folders, contact = 1 folder
 *  - "Сканы договоров" lists deal documents; rejects upload
 *  - Upload + download + delete on a normal folder
 *  - System folder rename/delete rejected (422)
 *  - Create user folder
 *  - IDOR: wrong entity returns 404
 */
class CrmFilesTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- helpers

    private function makeOwner(): User
    {
        return User::factory()->create(['role' => Role::Manager]);
    }

    private function makeCompany(User $owner): Company
    {
        return Company::factory()->create(['owner_user_id' => $owner->id]);
    }

    private function makeContact(User $owner): Contact
    {
        return Contact::factory()->create(['owner_id' => $owner->id]);
    }

    // ---------------------------------------------------------------- company: system folders seeding

    public function test_company_gets_three_system_folders_on_first_get(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->getJson("/api/companies/{$company->id}/folders");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data);

        $names = array_column($data, 'name');
        $this->assertContains('Папка менеджера сделки', $names);
        $this->assertContains('Сканы договоров', $names);
        $this->assertContains('Папка ОКС', $names);

        // All three are system folders
        foreach ($data as $folder) {
            $this->assertTrue($folder['is_system']);
        }

        // "Сканы договоров" must be read_only
        $scans = collect($data)->firstWhere('name', 'Сканы договоров');
        $this->assertNotNull($scans);
        $this->assertTrue($scans['read_only']);
    }

    public function test_company_system_folders_are_idempotent(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        // Call twice — should still return 3 folders.
        $this->getJson("/api/companies/{$company->id}/folders")->assertOk();
        $response = $this->getJson("/api/companies/{$company->id}/folders");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertDatabaseCount('crm_folders', 3);
    }

    // ---------------------------------------------------------------- contact: system folders seeding

    public function test_contact_gets_one_system_folder_on_first_get(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $contact = $this->makeContact($owner);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->getJson("/api/contacts/{$contact->id}/folders");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Папка менеджера сделки', $data[0]['name']);
        $this->assertTrue($data[0]['is_system']);
        $this->assertFalse($data[0]['read_only']); // manager folder is NOT read-only
    }

    // ---------------------------------------------------------------- create user folder

    public function test_create_user_folder_for_company(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->postJson("/api/companies/{$company->id}/folders", [
            'name' => 'Мои документы',
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['name' => 'Мои документы', 'is_system' => false]);

        $this->assertDatabaseHas('crm_folders', [
            'name' => 'Мои документы',
            'is_system' => false,
            'owner_entity_type' => 'company',
            'owner_entity_id' => $company->id,
        ]);
    }

    public function test_create_user_folder_for_contact(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $contact = $this->makeContact($owner);

        Sanctum::actingAs($owner, ['*']);

        $response = $this->postJson("/api/contacts/{$contact->id}/folders", [
            'name' => 'Прочее',
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['name' => 'Прочее', 'is_system' => false]);
    }

    // ---------------------------------------------------------------- system folder delete rejected

    public function test_cannot_delete_system_folder(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        // Seed folders first
        $this->getJson("/api/companies/{$company->id}/folders");

        $systemFolder = CrmFolder::where('owner_entity_type', 'company')
            ->where('owner_entity_id', $company->id)
            ->where('is_system', true)
            ->first();

        $response = $this->deleteJson("/api/companies/{$company->id}/folders/{$systemFolder->id}");
        $response->assertStatus(422);
    }

    // ---------------------------------------------------------------- user folder delete works

    public function test_can_delete_user_folder(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        $folder = CrmFolder::create([
            'owner_entity_type' => 'company',
            'owner_entity_id' => $company->id,
            'name' => 'Temp folder',
            'is_system' => false,
            'sort_order' => 99,
        ]);

        $response = $this->deleteJson("/api/companies/{$company->id}/folders/{$folder->id}");
        $response->assertOk();
        $this->assertDatabaseMissing('crm_folders', ['id' => $folder->id]);
    }

    // ---------------------------------------------------------------- upload + download + delete

    public function test_upload_then_download_then_delete_file(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        // Seed folders
        $this->getJson("/api/companies/{$company->id}/folders");

        $managerFolder = CrmFolder::where('owner_entity_type', 'company')
            ->where('owner_entity_id', $company->id)
            ->where('name', 'Папка менеджера сделки')
            ->first();

        // Upload
        $fakeFile = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');

        $uploadResponse = $this->postJson(
            "/api/companies/{$company->id}/folders/{$managerFolder->id}/files",
            ['file' => $fakeFile],
        );

        $uploadResponse->assertCreated();
        $fileId = $uploadResponse->json('data.id');
        $this->assertNotNull($fileId);

        $this->assertDatabaseHas('crm_files', [
            'id' => $fileId,
            'folder_id' => $managerFolder->id,
            'owner_entity_type' => 'company',
            'owner_entity_id' => $company->id,
            'original_name' => 'contract.pdf',
        ]);

        // List files in folder
        $listResponse = $this->getJson("/api/companies/{$company->id}/folders/{$managerFolder->id}/files");
        $listResponse->assertOk();
        $this->assertCount(1, $listResponse->json('data'));

        // Download
        $downloadResponse = $this->get("/api/companies/{$company->id}/files/{$fileId}/download");
        $downloadResponse->assertOk();

        // Delete
        $deleteResponse = $this->deleteJson("/api/companies/{$company->id}/files/{$fileId}");
        $deleteResponse->assertOk();
        $this->assertDatabaseMissing('crm_files', ['id' => $fileId]);
    }

    // ---------------------------------------------------------------- scans folder: lists documents

    public function test_scans_folder_lists_company_deal_documents(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        // Create a document linked to the company.
        $doc = Document::factory()->create([
            'source_company_id' => $company->id,
            'archived_at' => null,
            'title' => 'Test Contract',
            'author_user_id' => $owner->id,
        ]);

        // Seed folders
        $this->getJson("/api/companies/{$company->id}/folders");

        $scansFolder = CrmFolder::where('owner_entity_type', 'company')
            ->where('owner_entity_id', $company->id)
            ->where('name', 'Сканы договоров')
            ->first();

        $response = $this->getJson("/api/companies/{$company->id}/folders/{$scansFolder->id}/files");
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('document', $data[0]['source']);
        $this->assertSame($doc->id, $data[0]['document_id']);
    }

    // ---------------------------------------------------------------- scans folder: rejects upload

    public function test_scans_folder_rejects_upload(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        // Seed folders
        $this->getJson("/api/companies/{$company->id}/folders");

        $scansFolder = CrmFolder::where('owner_entity_type', 'company')
            ->where('owner_entity_id', $company->id)
            ->where('name', 'Сканы договоров')
            ->first();

        $fakeFile = UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf');

        $response = $this->postJson(
            "/api/companies/{$company->id}/folders/{$scansFolder->id}/files",
            ['file' => $fakeFile],
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('read-only', $response->json('message'));
    }

    // ---------------------------------------------------------------- IDOR guard

    public function test_folder_from_another_company_returns_404(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company1 = $this->makeCompany($owner);
        $company2 = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        // Seed folders for company2
        $this->getJson("/api/companies/{$company2->id}/folders");

        $folderOfCompany2 = CrmFolder::where('owner_entity_id', $company2->id)->first();

        // Try to access company1's endpoint with company2's folder
        $response = $this->getJson("/api/companies/{$company1->id}/folders/{$folderOfCompany2->id}/files");
        $response->assertNotFound();
    }

    // ---------------------------------------------------------------- upload validation

    public function test_upload_without_file_returns_422(): void
    {
        Storage::fake('crm_files');

        $owner = $this->makeOwner();
        $company = $this->makeCompany($owner);

        Sanctum::actingAs($owner, ['*']);

        $this->getJson("/api/companies/{$company->id}/folders");

        $folder = CrmFolder::where('owner_entity_type', 'company')
            ->where('owner_entity_id', $company->id)
            ->where('name', 'Папка менеджера сделки')
            ->first();

        $response = $this->postJson("/api/companies/{$company->id}/folders/{$folder->id}/files", []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }
}
