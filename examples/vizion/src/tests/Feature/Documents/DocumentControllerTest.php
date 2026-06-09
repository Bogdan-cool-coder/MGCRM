<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Contracts\DocumentObjectDataResolver;
use App\Jobs\GenerateDocumentJob;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\Promotion;
use App\Models\User;
use App\Services\Documents\DocxTemplateService;
use App\Services\Documents\GotenbergClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Documents\Concerns\MakesDocxFixture;
use Tests\TestCase;

/**
 * Feature tests for DocumentController:
 *   - index visibility matrix (system / published / personal × roles)
 *   - show read-ACL
 *   - create / update / delete (owner, admin, viewer-403, system reject)
 *   - publish / unpublish (admin ok, analyst 403, system reject)
 *   - cross-company isolation
 *   - generate endpoint queues a job + creates GeneratedDocument(pending)
 *   - generated status + download ACL
 */
class DocumentControllerTest extends TestCase
{
    use MakesDocxFixture;
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'name' => $name,
            'macrodata_host' => '127.0.0.1',
            'macrodata_port' => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url' => 'https://crm.test',
        ]);
    }

    private function makeUser(Company $company, string $role): User
    {
        return User::factory()->create([
            'company_id' => $company->id,
            'active_company_id' => $company->id,
            'role' => $role,
            'company_accesses' => [['company_id' => $company->id, 'role' => $role]],
        ]);
    }

    // -------------------------------------------------------------------------
    // index — visibility matrix
    // -------------------------------------------------------------------------

    /** @test */
    public function test_index_admin_sees_system_and_company_templates(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $system = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);
        $personal = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);
        $published = DocumentTemplate::factory()->published()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson('/api/documents');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($system->id, $ids);
        $this->assertContains($personal->id, $ids);
        $this->assertContains($published->id, $ids);
    }

    /** @test */
    public function test_index_analyst_sees_system_published_and_own_but_not_others_private(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $other = $this->makeUser($company, 'analyst');

        $system = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);
        $own = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $othersPrivate = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $other->id]);
        $othersPub = DocumentTemplate::factory()->published()->create(['company_id' => $company->id, 'user_id' => $other->id]);

        $response = $this->actingAs($analyst)->getJson('/api/documents');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($system->id, $ids);
        $this->assertContains($own->id, $ids);
        $this->assertContains($othersPub->id, $ids);
        $this->assertNotContains($othersPrivate->id, $ids);
    }

    /** @test */
    public function test_index_viewer_sees_only_system_and_published(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');

        $system = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);
        $personal = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);
        $pub = DocumentTemplate::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $response = $this->actingAs($viewer)->getJson('/api/documents');
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($system->id, $ids);
        $this->assertContains($pub->id, $ids);
        $this->assertNotContains($personal->id, $ids);
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    /** @test */
    public function test_show_returns_template_with_config_and_author(): void
    {
        $company = $this->makeCompany('A');
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'active_company_id' => $company->id,
            'role' => 'admin',
            'name' => 'Doc Author',
            'email' => 'doc@example.com',
            'company_accesses' => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->getJson("/api/documents/{$template->id}");
        $response->assertOk();
        $response->assertJsonPath('id', $template->id);
        $response->assertJsonPath('author.id', $admin->id);
        $response->assertJsonPath('author.name', 'Doc Author');
        $response->assertJsonPath('type', 'html');
        $this->assertArrayHasKey('config', $response->json());
    }

    /** @test */
    public function test_viewer_cannot_show_unpublished_personal_template(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $this->actingAs($viewer)->getJson("/api/documents/{$template->id}")->assertStatus(403);
    }

    /** @test */
    public function test_show_cross_company_template_is_forbidden_for_admin(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $adminA = $this->makeUser($companyA, 'admin');
        $templateB = DocumentTemplate::factory()->create(['company_id' => $companyB->id, 'user_id' => null]);

        $this->actingAs($adminA)->getJson("/api/documents/{$templateB->id}")->assertStatus(403);
    }

    /** @test */
    public function test_superadmin_can_show_cross_company_template(): void
    {
        $home = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $superadmin = User::factory()->create([
            'company_id' => $home->id,
            'active_company_id' => $home->id,
            'role' => 'superadmin',
            'company_accesses' => [['company_id' => $home->id, 'role' => 'superadmin']],
        ]);
        $templateOther = DocumentTemplate::factory()->create(['company_id' => $other->id, 'user_id' => null]);

        $this->actingAs($superadmin)->getJson("/api/documents/{$templateOther->id}")->assertOk();
    }

    // -------------------------------------------------------------------------
    // create / update / delete
    // -------------------------------------------------------------------------

    /** @test */
    public function test_analyst_can_create_template(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $response = $this->actingAs($analyst)->postJson('/api/documents', [
            'name' => ['ru' => 'Новый', 'en' => 'New'],
            'type' => 'html',
            'config' => ['html' => '<p>{{title}}</p>'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('is_system', false);
        $response->assertJsonPath('user_id', $analyst->id);
        $response->assertJsonPath('type', 'html');
        $this->assertDatabaseHas('document_templates', [
            'id' => $response->json('id'),
            'company_id' => $company->id,
        ]);
    }

    /** @test */
    public function test_analyst_can_create_template_with_empty_config(): void
    {
        // A brand-new template is created with an empty config; `present|array`
        // must accept {} / [] where `required|array` would 422 on the empty array.
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $response = $this->actingAs($analyst)->postJson('/api/documents', [
            'name' => ['ru' => 'Пустой', 'en' => 'Empty'],
            'type' => 'html',
            'config' => [],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user_id', $analyst->id);
        $response->assertJsonPath('config', []);
        $this->assertDatabaseHas('document_templates', [
            'id' => $response->json('id'),
            'company_id' => $company->id,
        ]);
    }

    /** @test */
    public function test_create_template_without_config_key_is_rejected(): void
    {
        // `present` still requires the key to be in the payload — omitting it
        // entirely is a 422 (the frontend always sends at least {}).
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $this->actingAs($analyst)->postJson('/api/documents', [
            'name' => ['ru' => 'Без конфига', 'en' => 'No config'],
            'type' => 'html',
        ])->assertStatus(422)->assertJsonValidationErrors('config');
    }

    /** @test */
    public function test_viewer_cannot_create_template(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');

        $this->actingAs($viewer)->postJson('/api/documents', [
            'name' => ['en' => 'X'],
            'type' => 'html',
            'config' => ['html' => '<p>x</p>'],
        ])->assertStatus(403);
    }

    /** @test */
    public function test_owner_analyst_can_update_own_template(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $response = $this->actingAs($analyst)->putJson("/api/documents/{$template->id}", [
            'name' => ['ru' => 'Изм', 'en' => 'Changed'],
        ]);

        $response->assertOk();
        $this->assertSame('Changed', $template->fresh()->getTranslation('name', 'en'));
    }

    /** @test */
    public function test_update_preserves_config_keys_not_in_request(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'config' => ['html' => '<p>old</p>', 'css' => '.x{}', 'fields' => ['a', 'b']],
        ]);

        // Send a full config blob with nested keys — they must all survive.
        $response = $this->actingAs($analyst)->putJson("/api/documents/{$template->id}", [
            'config' => ['html' => '<p>new</p>', 'css' => '.y{}', 'fields' => ['a', 'b', 'c']],
        ]);

        $response->assertOk();
        $fresh = $template->fresh();
        $this->assertSame('<p>new</p>', $fresh->config['html']);
        $this->assertSame('.y{}', $fresh->config['css']);
        $this->assertSame(['a', 'b', 'c'], $fresh->config['fields']);
    }

    /** @test */
    public function test_analyst_cannot_update_other_users_template(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $other = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $other->id]);

        $this->actingAs($analyst)->putJson("/api/documents/{$template->id}", [
            'name' => ['en' => 'Hijack'],
        ])->assertStatus(403);
    }

    /** @test */
    public function test_update_rejects_system_template(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);

        $this->actingAs($superadmin)->putJson("/api/documents/{$template->id}", [
            'name' => ['en' => 'Nope'],
        ])->assertStatus(403);
    }

    /** @test */
    public function test_owner_can_delete_template(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $this->actingAs($analyst)->deleteJson("/api/documents/{$template->id}")->assertOk();
        $this->assertNull(DocumentTemplate::find($template->id));
    }

    /** @test */
    public function test_viewer_cannot_delete_template(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->published()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $this->actingAs($viewer)->deleteJson("/api/documents/{$template->id}")->assertStatus(403);
        $this->assertNotNull(DocumentTemplate::find($template->id));
    }

    /** @test */
    public function test_delete_rejects_system_template(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);

        $this->actingAs($superadmin)->deleteJson("/api/documents/{$template->id}")->assertStatus(403);
        $this->assertNotNull(DocumentTemplate::find($template->id));
    }

    // -------------------------------------------------------------------------
    // publish / unpublish
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_publish_template(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $admin->id]);

        $response = $this->actingAs($admin)->postJson("/api/documents/{$template->id}/publish");
        $response->assertOk();
        $response->assertJsonPath('is_published', true);
        $this->assertTrue($template->fresh()->is_published);
    }

    /** @test */
    public function test_analyst_cannot_publish_template(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);

        $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/publish")->assertStatus(403);
        $this->assertFalse($template->fresh()->is_published);
    }

    /** @test */
    public function test_publish_rejects_system_template(): void
    {
        $company = $this->makeCompany('A');
        $superadmin = $this->makeUser($company, 'superadmin');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);

        $this->actingAs($superadmin)->postJson("/api/documents/{$template->id}/publish")->assertStatus(403);
    }

    /** @test */
    public function test_admin_cannot_publish_template_from_another_company(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $adminA = $this->makeUser($companyA, 'admin');
        $templateB = DocumentTemplate::factory()->create(['company_id' => $companyB->id, 'user_id' => null]);

        $this->actingAs($adminA)->postJson("/api/documents/{$templateB->id}/publish")->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // generate + status + download
    // -------------------------------------------------------------------------

    /** @test */
    public function test_generate_creates_pending_document_and_dispatches_job(): void
    {
        Bus::fake();

        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        // Viewer may generate from a system (visible) template.
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);

        $response = $this->actingAs($viewer)->postJson("/api/documents/{$template->id}/generate", [
            'estate_sell_id' => 555,
            'discount' => 5,
        ]);

        $response->assertStatus(202);
        $generatedId = $response->json('generated_document_id');
        $this->assertNotNull($generatedId);

        $this->assertDatabaseHas('generated_documents', [
            'id' => $generatedId,
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'status' => GeneratedDocument::STATUS_PENDING,
        ]);

        $generated = GeneratedDocument::find($generatedId);
        $this->assertSame(555, $generated->params['estate_sell_id']);

        Bus::assertDispatched(GenerateDocumentJob::class, fn ($job) => $job->generatedDocumentId === $generatedId);
    }

    /** @test */
    public function test_generate_forbidden_when_template_not_readable(): void
    {
        Bus::fake();

        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');
        // Unpublished personal template — invisible to viewer.
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        $this->actingAs($viewer)->postJson("/api/documents/{$template->id}/generate")->assertStatus(403);
        Bus::assertNothingDispatched();
    }

    /** @test */
    public function test_generated_status_returns_state(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $generated = GeneratedDocument::factory()->done()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
        ]);

        $response = $this->actingAs($analyst)->getJson("/api/documents/generated/{$generated->id}");
        $response->assertOk();
        $response->assertJsonPath('status', GeneratedDocument::STATUS_DONE);
        $response->assertJsonPath('id', $generated->id);
    }

    /** @test */
    public function test_generated_status_enforces_template_read_acl(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');
        // Unpublished personal template — viewer cannot read it, so the
        // generation derived from it is also forbidden.
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);
        $generated = GeneratedDocument::factory()->done()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $author->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/documents/generated/{$generated->id}")->assertStatus(403);
    }

    /** @test */
    public function test_download_streams_pdf_when_done(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_DONE,
            'pdf_path' => 'documents/99/document.pdf',
        ]);
        Storage::disk('documents')->put('documents/99/document.pdf', '%PDF-1.4 fake');

        $response = $this->actingAs($analyst)->get("/api/documents/generated/{$generated->id}/download?format=pdf");
        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=document-'.$generated->id.'.pdf');
    }

    /** @test */
    public function test_download_409_when_path_set_but_file_missing(): void
    {
        // Status done + pdf_path set, but the file is absent on disk. download()
        // must still 409 (file_not_ready) rather than 500 — guards the
        // disk->exists() branch after the documents-disk migration.
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_DONE,
            'pdf_path' => "documents/{$company->id}/missing.pdf",
        ]);

        $this->actingAs($analyst)->getJson("/api/documents/generated/{$generated->id}/download?format=pdf")
            ->assertStatus(409);
    }

    /** @test */
    public function test_download_409_when_not_ready(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_PENDING,
        ]);

        $this->actingAs($analyst)->getJson("/api/documents/generated/{$generated->id}/download?format=pdf")
            ->assertStatus(409);
    }

    /** @test */
    public function test_download_enforces_read_acl(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);
        $generated = GeneratedDocument::factory()->done()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $author->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/documents/generated/{$generated->id}/download?format=pdf")
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // GenerateDocumentJob — happy path with mocked resolver + Gotenberg
    // -------------------------------------------------------------------------

    /** @test */
    public function test_generate_job_renders_pdf_and_marks_done(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'config' => ['html' => '<h1>{{title}}</h1>'],
        ]);
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_PENDING,
            'params' => ['estate_sell_id' => 0, 'title' => 'Hello'],
        ]);

        // Resolver is mocked (no MacroData); Gotenberg is mocked (no HTTP).
        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([]);
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $gotenberg = Mockery::mock(GotenbergClient::class);
        $gotenberg->shouldReceive('htmlToPdf')->once()->andReturn('%PDF-1.4 fake-bytes');
        $this->app->instance(GotenbergClient::class, $gotenberg);

        // ConnectionService::connect would hit a real MySQL; the company in the
        // test has bogus creds. Mock it so the job stays offline.
        $connection = Mockery::mock(\App\Services\MacroData\ConnectionService::class);
        $connection->shouldReceive('connect')->andReturnNull();
        $this->app->instance(\App\Services\MacroData\ConnectionService::class, $connection);

        (new GenerateDocumentJob($generated->id))->handle(
            $connection,
            $resolver,
            $this->app->make(\App\Services\Documents\HtmlDocumentService::class),
            $gotenberg,
            $this->app->make(DocxTemplateService::class),
            $this->app->make(\App\Services\Documents\DocumentDataAssembler::class),
        );

        $fresh = $generated->fresh();
        $this->assertSame(GeneratedDocument::STATUS_DONE, $fresh->status);
        $this->assertSame("documents/{$generated->id}/document.pdf", $fresh->pdf_path);
        Storage::disk('documents')->assertExists("documents/{$generated->id}/document.pdf");
    }

    /** @test */
    public function test_generated_pdf_is_downloadable_end_to_end(): void
    {
        // The regression that motivated the dedicated "documents" disk: a file
        // written by the job (queue-worker → root) must be readable by the
        // download endpoint (php-fpm → www-data). With a single shared disk in
        // tests this exercises the same write-then-read path the containers use.
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'config' => ['html' => '<h1>{{title}}</h1>'],
        ]);
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_PENDING,
            'params' => ['estate_sell_id' => 0, 'title' => 'Hello'],
        ]);

        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([]);
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $gotenberg = Mockery::mock(GotenbergClient::class);
        $gotenberg->shouldReceive('htmlToPdf')->once()->andReturn('%PDF-1.4 fake-bytes');
        $this->app->instance(GotenbergClient::class, $gotenberg);

        $connection = Mockery::mock(\App\Services\MacroData\ConnectionService::class);
        $connection->shouldReceive('connect')->andReturnNull();
        $this->app->instance(\App\Services\MacroData\ConnectionService::class, $connection);

        (new GenerateDocumentJob($generated->id))->handle(
            $connection,
            $resolver,
            $this->app->make(\App\Services\Documents\HtmlDocumentService::class),
            $gotenberg,
            $this->app->make(DocxTemplateService::class),
            $this->app->make(\App\Services\Documents\DocumentDataAssembler::class),
        );

        // Now hit the download endpoint — the file written by the job must be
        // served (200), not 409.
        $response = $this->actingAs($analyst)->get("/api/documents/generated/{$generated->id}/download?format=pdf");
        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=document-'.$generated->id.'.pdf');
    }

    /** @test */
    public function test_generate_job_marks_error_on_failure(): void
    {
        Storage::fake('local');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $analyst->id]);
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_PENDING,
            'params' => ['estate_sell_id' => 0],
        ]);

        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([]);

        $gotenberg = Mockery::mock(GotenbergClient::class);
        $gotenberg->shouldReceive('htmlToPdf')->andThrow(new \RuntimeException('Gotenberg down'));

        $connection = Mockery::mock(\App\Services\MacroData\ConnectionService::class);
        $connection->shouldReceive('connect')->andReturnNull();

        (new GenerateDocumentJob($generated->id))->handle(
            $connection,
            $resolver,
            $this->app->make(\App\Services\Documents\HtmlDocumentService::class),
            $gotenberg,
            $this->app->make(DocxTemplateService::class),
            $this->app->make(\App\Services\Documents\DocumentDataAssembler::class),
        );

        $fresh = $generated->fresh();
        $this->assertSame(GeneratedDocument::STATUS_ERROR, $fresh->status);
        $this->assertStringContainsString('Gotenberg down', (string) $fresh->error);
    }

    // -------------------------------------------------------------------------
    // generate — promotion / discount gate (M3)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_generate_with_promotion_discount_in_range_ok(): void
    {
        Bus::fake();

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);
        $promo = Promotion::factory()->create([
            'company_id' => $company->id,
            'discount_type' => 'percent',
            'discount_min' => 0,
            'discount_max' => 10,
        ]);

        $response = $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/generate", [
            'estate_sell_id' => 555,
            'promotion_id' => $promo->id,
            'discount' => 7,
        ]);

        $response->assertStatus(202);
        $generated = GeneratedDocument::find($response->json('generated_document_id'));
        $this->assertSame($promo->id, $generated->params['promotion_id']);
        $this->assertSame(7, $generated->params['discount']);
        Bus::assertDispatched(GenerateDocumentJob::class);
    }

    /** @test */
    public function test_generate_with_discount_out_of_range_422(): void
    {
        Bus::fake();

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);
        $promo = Promotion::factory()->create([
            'company_id' => $company->id,
            'discount_min' => 0,
            'discount_max' => 10,
        ]);

        $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/generate", [
            'promotion_id' => $promo->id,
            'discount' => 25,
        ])->assertStatus(422)->assertJsonValidationErrors(['discount']);

        Bus::assertNothingDispatched();
    }

    /** @test */
    public function test_generate_with_other_company_promotion_422(): void
    {
        Bus::fake();

        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $analyst = $this->makeUser($companyA, 'analyst');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $companyA->id]);
        $foreignPromo = Promotion::factory()->create(['company_id' => $companyB->id]);

        $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/generate", [
            'promotion_id' => $foreignPromo->id,
            'discount' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors(['promotion_id']);

        Bus::assertNothingDispatched();
    }

    /** @test */
    public function test_generate_with_inactive_promotion_422(): void
    {
        Bus::fake();

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);
        $promo = Promotion::factory()->inactive()->create([
            'company_id' => $company->id,
            'discount_min' => 0,
            'discount_max' => 10,
        ]);

        $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/generate", [
            'promotion_id' => $promo->id,
            'discount' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors(['promotion_id']);

        Bus::assertNothingDispatched();
    }

    /** @test */
    public function test_generate_without_promotion_ignores_discount_gate(): void
    {
        Bus::fake();

        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);

        // No promotion_id → discount is ungated (free generation).
        $this->actingAs($viewer)->postJson("/api/documents/{$template->id}/generate", [
            'discount' => 999,
        ])->assertStatus(202);

        Bus::assertDispatched(GenerateDocumentJob::class);
    }

    // -------------------------------------------------------------------------
    // preview-html — synchronous HTML preview (M4)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_preview_html_returns_html_with_substituted_placeholders(): void
    {
        Bus::fake();

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'config' => ['html' => '<h1>{{title}}</h1><p>ЖК: {{complex_name}}</p>'],
        ]);

        // Resolver mocked — returns the object fields that fill the placeholders.
        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldReceive('resolve')->once()->andReturn([
            'title' => 'КП №42',
            'complex_name' => 'ЖК Звезда',
        ]);
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $response = $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/preview-html", [
            'estate_sell_id' => 555,
        ]);

        $response->assertOk();
        $html = $response->json('html');
        $this->assertIsString($html);
        $this->assertStringContainsString('<h1>КП №42</h1>', $html);
        $this->assertStringContainsString('ЖК Звезда', $html);
        // Never leak raw {{...}} markup.
        $this->assertStringNotContainsString('{{', $html);

        // Sync path: no GeneratedDocument, nothing queued.
        $this->assertSame(0, GeneratedDocument::count());
        Bus::assertNothingDispatched();
    }

    /** @test */
    public function test_preview_html_renders_without_estate_sell_id(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'config' => ['html' => '<h1>{{title}}</h1>'],
        ]);

        // Resolver must NOT be called when no estate_sell_id is supplied.
        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $response = $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/preview-html", []);

        $response->assertOk();
        $html = $response->json('html');
        $this->assertIsString($html);
        // Empty object data → placeholder collapses to empty, no raw markup.
        $this->assertStringContainsString('<h1></h1>', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    /** @test */
    public function test_preview_html_forbidden_when_template_not_readable(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');
        // Unpublished personal template — invisible to viewer.
        $template = DocumentTemplate::factory()->create(['company_id' => $company->id, 'user_id' => $author->id]);

        // Resolver never reached — ACL fails first.
        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $this->actingAs($viewer)->postJson("/api/documents/{$template->id}/preview-html", [
            'estate_sell_id' => 1,
        ])->assertStatus(403);
    }

    /** @test */
    public function test_preview_html_viewer_can_preview_system_template(): void
    {
        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $template = DocumentTemplate::factory()->system()->create([
            'company_id' => $company->id,
            'config' => ['html' => '<h1>{{title}}</h1>'],
        ]);

        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['title' => 'Visible']);
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $response = $this->actingAs($viewer)->postJson("/api/documents/{$template->id}/preview-html", [
            'estate_sell_id' => 7,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('Visible', $response->json('html'));
    }

    /** @test */
    public function test_preview_html_cross_company_template_forbidden_for_admin(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $adminA = $this->makeUser($companyA, 'admin');
        $templateB = DocumentTemplate::factory()->create(['company_id' => $companyB->id, 'user_id' => null]);

        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $this->actingAs($adminA)->postJson("/api/documents/{$templateB->id}/preview-html", [
            'estate_sell_id' => 1,
        ])->assertStatus(403);
    }

    /** @test */
    public function test_preview_html_with_discount_out_of_range_422(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $company->id]);
        $promo = Promotion::factory()->create([
            'company_id' => $company->id,
            'discount_min' => 0,
            'discount_max' => 10,
        ]);

        // Resolver never reached — discount gate fails first.
        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/preview-html", [
            'promotion_id' => $promo->id,
            'discount' => 25,
        ])->assertStatus(422)->assertJsonValidationErrors(['discount']);
    }

    /** @test */
    public function test_preview_html_with_other_company_promotion_422(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $analyst = $this->makeUser($companyA, 'analyst');
        $template = DocumentTemplate::factory()->system()->create(['company_id' => $companyA->id]);
        $foreignPromo = Promotion::factory()->create(['company_id' => $companyB->id]);

        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        $this->actingAs($analyst)->postJson("/api/documents/{$template->id}/preview-html", [
            'promotion_id' => $foreignPromo->id,
            'discount' => 5,
        ])->assertStatus(422)->assertJsonValidationErrors(['promotion_id']);
    }

    // -------------------------------------------------------------------------
    // Word (docx) source upload + placeholders (M5)
    // -------------------------------------------------------------------------

    /**
     * Build an UploadedFile from real .docx bytes. We avoid UploadedFile::fake()
     * because ->image() needs GD (absent in the test container) and the mimes:docx
     * rule needs genuine OOXML content, not zeroed bytes
     * (cf. uploadedfile_fake_no_gd memory).
     *
     * @param  array<int, string>  $paragraphs
     */
    private function makeUploadedDocx(array $paragraphs, string $name = 'template.docx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->makeDocxFixtureBytes($paragraphs));
    }

    /** @test */
    public function test_upload_source_file_stores_docx_and_sets_source_path(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => null,
        ]);

        $response = $this->actingAs($analyst)->post(
            "/api/documents/{$template->id}/source-file",
            ['file' => $this->makeUploadedDocx(['Client: ${client_name}'])],
        );

        $response->assertOk();
        $expectedPath = "document-templates/{$template->id}/template.docx";
        $response->assertJsonPath('source_path', $expectedPath);

        Storage::disk('documents')->assertExists($expectedPath);
        $this->assertSame($expectedPath, $template->fresh()->source_path);
    }

    /** @test */
    public function test_upload_source_file_viewer_forbidden(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->docx()->published()->create([
            'company_id' => $company->id,
            'user_id' => $author->id,
        ]);

        $this->actingAs($viewer)->post(
            "/api/documents/{$template->id}/source-file",
            ['file' => $this->makeUploadedDocx(['x ${y}'])],
        )->assertStatus(403);
    }

    /** @test */
    public function test_upload_source_file_rejects_non_docx_mime(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
        ]);

        // A plain-text file with a .txt extension — fails the mimetypes
        // whitelist (text/plain is not docx/zip/octet-stream).
        $bad = UploadedFile::fake()->createWithContent('notes.txt', 'just text');

        $this->actingAs($analyst)->postJson(
            "/api/documents/{$template->id}/source-file",
            ['file' => $bad],
        )->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    /** @test */
    public function test_upload_source_file_rejects_non_docx_extension(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
        ]);

        // Real OOXML bytes named with a .zip extension. A .docx IS a zip, so the
        // mimetypes whitelist (which includes application/zip) lets it through —
        // exactly the case where mimes:docx used to (wrongly) backstop. The
        // explicit extension guard now rejects it with documents.must_be_docx,
        // proving we still gate on the .docx extension after dropping mimes:docx.
        $wrongExt = UploadedFile::fake()->createWithContent(
            'template.zip',
            $this->makeDocxFixtureBytes(['x ${y}']),
        );

        $this->actingAs($analyst)->postJson(
            "/api/documents/{$template->id}/source-file",
            ['file' => $wrongExt],
        )->assertStatus(422)->assertJsonPath('message', __('documents.must_be_docx'));
    }

    /** @test */
    public function test_upload_docx_to_html_template_rejected(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        // html-type template (factory default). In v2 html templates accept an
        // .html source, but a .docx (a zip container) fails the html mimetypes
        // whitelist → 422 (postJson so the validation failure is JSON, not a 302
        // redirect back).
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
        ]);

        $this->actingAs($analyst)->postJson(
            "/api/documents/{$template->id}/source-file",
            ['file' => $this->makeUploadedDocx(['x ${y}'])],
        )->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    /** @test */
    public function test_upload_source_file_rejected_for_system_template(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');
        $template = DocumentTemplate::factory()->docx()->system()->create([
            'company_id' => $company->id,
        ]);

        $this->actingAs($admin)->post(
            "/api/documents/{$template->id}/source-file",
            ['file' => $this->makeUploadedDocx(['x ${y}'])],
        )->assertStatus(403);
    }

    /** @test */
    public function test_placeholders_returns_tokens_from_uploaded_docx(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => null,
        ]);

        // Upload through the real endpoint so the stored bytes match the disk.
        $this->actingAs($analyst)->post(
            "/api/documents/{$template->id}/source-file",
            ['file' => $this->makeUploadedDocx(['Client: ${client_name}', 'Price: ${estate_price}'])],
        )->assertOk();

        $response = $this->actingAs($analyst)->getJson("/api/documents/{$template->id}/placeholders");
        $response->assertOk();

        $placeholders = $response->json('placeholders');
        sort($placeholders);
        $this->assertSame(['client_name', 'estate_price'], $placeholders);
    }

    /** @test */
    public function test_placeholders_422_when_no_source_uploaded(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => null,
        ]);

        $this->actingAs($analyst)->getJson("/api/documents/{$template->id}/placeholders")
            ->assertStatus(422);
    }

    /** @test */
    public function test_placeholders_enforces_read_acl(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $viewer = $this->makeUser($company, 'viewer');
        $author = $this->makeUser($company, 'analyst');
        // Unpublished personal docx template — invisible to viewer.
        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $author->id,
        ]);

        $this->actingAs($viewer)->getJson("/api/documents/{$template->id}/placeholders")
            ->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // HTML source upload (mirror of docx) + html placeholders (v2)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_upload_html_source_stores_file_and_sets_source_path(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        // html-type template (factory default).
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => null,
        ]);

        $html = '<p>ЖК ${estate.complex_name}, цена ${estate.price|format} ₽</p>';
        $file = UploadedFile::fake()->createWithContent('kp.html', $html);

        $response = $this->actingAs($analyst)->post(
            "/api/documents/{$template->id}/source-file",
            ['file' => $file],
        );

        $response->assertOk();
        $expectedPath = "document-templates/{$template->id}/template.html";
        $response->assertJsonPath('source_path', $expectedPath);

        Storage::disk('documents')->assertExists($expectedPath);
        $this->assertSame($expectedPath, $template->fresh()->source_path);
    }

    /** @test */
    public function test_upload_html_source_rejects_non_html_extension(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
        ]);

        // Plain text content with a .txt extension → fails the html mimetypes
        // whitelist (text/plain IS allowed) so it reaches the extension guard and
        // is rejected with documents.must_be_html.
        $file = UploadedFile::fake()->createWithContent('notes.txt', '<p>hi</p>');

        $this->actingAs($analyst)->postJson(
            "/api/documents/{$template->id}/source-file",
            ['file' => $file],
        )->assertStatus(422)->assertJsonPath('message', __('documents.must_be_html'));
    }

    /** @test */
    public function test_upload_html_source_accepts_htm_extension(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
        ]);

        $file = UploadedFile::fake()->createWithContent('kp.htm', '<p>${estate.number}</p>');

        $this->actingAs($analyst)->post(
            "/api/documents/{$template->id}/source-file",
            ['file' => $file],
        )->assertOk();
    }

    /** @test */
    public function test_html_placeholders_extracted_from_uploaded_source(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => null,
        ]);

        $html = '<p>${estate.complex_name}</p><p>${estate.price|words}</p><p>{{discount.percent|format}}</p>';
        $this->actingAs($analyst)->post(
            "/api/documents/{$template->id}/source-file",
            ['file' => UploadedFile::fake()->createWithContent('kp.html', $html)],
        )->assertOk();

        $response = $this->actingAs($analyst)->getJson("/api/documents/{$template->id}/placeholders");
        $response->assertOk();

        $placeholders = $response->json('placeholders');
        sort($placeholders);
        // Names only, filter chains stripped, both syntaxes scanned.
        $this->assertSame(['discount.percent', 'estate.complex_name', 'estate.price'], $placeholders);
    }

    /** @test */
    public function test_html_placeholders_fall_back_to_config_html(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        // html template with no uploaded source but a config.html body.
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => null,
            'config' => ['html' => '<p>${estate.number} / ${estate.floor}</p>'],
        ]);

        $response = $this->actingAs($analyst)->getJson("/api/documents/{$template->id}/placeholders");
        $response->assertOk();

        $placeholders = $response->json('placeholders');
        sort($placeholders);
        $this->assertSame(['estate.floor', 'estate.number'], $placeholders);
    }

    /** @test */
    public function test_html_placeholders_422_when_no_html_anywhere(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => null,
            'config' => [],
        ]);

        $this->actingAs($analyst)->getJson("/api/documents/{$template->id}/placeholders")
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // GenerateDocumentJob — docx branch (M5)
    // -------------------------------------------------------------------------

    /** @test */
    public function test_generate_job_fills_docx_and_renders_pdf(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        // Stage a real .docx source on the faked disk + a field_mapping.
        $sourcePath = 'document-templates/77/template.docx';
        Storage::disk('documents')->put(
            $sourcePath,
            $this->makeDocxFixtureBytes(['Client: ${client_name}', 'Object: ${object_label}']),
        );

        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => $sourcePath,
            'config' => ['field_mapping' => ['object_label' => 'complex_name']],
        ]);

        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_PENDING,
            'params' => ['estate_sell_id' => 555],
        ]);

        // Resolver supplies object fields used by the placeholders (a positive
        // estate_sell_id is required for the job to call it).
        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([
            'client_name' => 'Ivan Petrov',
            'complex_name' => 'ЖК Звезда',
        ]);
        $this->app->instance(DocumentObjectDataResolver::class, $resolver);

        // Gotenberg officeToPdf is the only HTTP touch — mock it.
        $gotenberg = Mockery::mock(GotenbergClient::class);
        $gotenberg->shouldReceive('officeToPdf')->once()->andReturn('%PDF-1.4 fake-bytes');
        $this->app->instance(GotenbergClient::class, $gotenberg);

        $connection = Mockery::mock(\App\Services\MacroData\ConnectionService::class);
        $connection->shouldReceive('connect')->andReturnNull();
        $this->app->instance(\App\Services\MacroData\ConnectionService::class, $connection);

        (new GenerateDocumentJob($generated->id))->handle(
            $connection,
            $resolver,
            $this->app->make(\App\Services\Documents\HtmlDocumentService::class),
            $gotenberg,
            $this->app->make(DocxTemplateService::class),
            $this->app->make(\App\Services\Documents\DocumentDataAssembler::class),
        );

        $fresh = $generated->fresh();
        $this->assertSame(GeneratedDocument::STATUS_DONE, $fresh->status);
        $this->assertSame("documents/{$generated->id}/document.docx", $fresh->docx_path);
        $this->assertSame("documents/{$generated->id}/document.pdf", $fresh->pdf_path);

        Storage::disk('documents')->assertExists("documents/{$generated->id}/document.docx");
        Storage::disk('documents')->assertExists("documents/{$generated->id}/document.pdf");

        // The filled .docx must carry the substituted values.
        $filled = Storage::disk('documents')->get("documents/{$generated->id}/document.docx");
        $tmp = tempnam(sys_get_temp_dir(), 'vizion_assert_').'.docx';
        file_put_contents($tmp, $filled);
        $text = $this->readDocxText($tmp);
        @unlink($tmp);

        $this->assertStringContainsString('Ivan Petrov', $text);
        $this->assertStringContainsString('ЖК Звезда', $text);
    }

    /** @test */
    public function test_generate_job_docx_errors_when_source_missing(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        // docx template with no uploaded source.
        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'source_path' => null,
        ]);
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_PENDING,
            'params' => ['estate_sell_id' => 0],
        ]);

        $resolver = Mockery::mock(DocumentObjectDataResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([]);
        $gotenberg = Mockery::mock(GotenbergClient::class);
        $gotenberg->shouldNotReceive('officeToPdf');
        $connection = Mockery::mock(\App\Services\MacroData\ConnectionService::class);
        $connection->shouldReceive('connect')->andReturnNull();

        (new GenerateDocumentJob($generated->id))->handle(
            $connection,
            $resolver,
            $this->app->make(\App\Services\Documents\HtmlDocumentService::class),
            $gotenberg,
            $this->app->make(DocxTemplateService::class),
            $this->app->make(\App\Services\Documents\DocumentDataAssembler::class),
        );

        $this->assertSame(GeneratedDocument::STATUS_ERROR, $generated->fresh()->status);
    }

    /** @test */
    public function test_download_serves_docx_when_present(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->docx()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
        ]);
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_DONE,
            'docx_path' => "documents/{$company->id}/document.docx",
            'pdf_path' => "documents/{$company->id}/document.pdf",
        ]);
        Storage::disk('documents')->put("documents/{$company->id}/document.docx", $this->makeDocxFixtureBytes(['hi']));

        $response = $this->actingAs($analyst)->get("/api/documents/generated/{$generated->id}/download?format=docx");
        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=document-'.$generated->id.'.docx');
    }

    /** @test */
    public function test_download_docx_409_when_docx_path_null(): void
    {
        Storage::fake('documents');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');
        $template = DocumentTemplate::factory()->create([
            'company_id' => $company->id,
            'user_id' => $analyst->id,
        ]);
        // html generation — pdf only, no docx_path. Requesting docx must 409.
        $generated = GeneratedDocument::factory()->create([
            'document_template_id' => $template->id,
            'company_id' => $company->id,
            'user_id' => $analyst->id,
            'status' => GeneratedDocument::STATUS_DONE,
            'pdf_path' => "documents/{$company->id}/document.pdf",
            'docx_path' => null,
        ]);

        $this->actingAs($analyst)->getJson("/api/documents/generated/{$generated->id}/download?format=docx")
            ->assertStatus(409);
    }

    // -------------------------------------------------------------------------
    // field-catalog — static reference of substitutable fields
    // -------------------------------------------------------------------------

    /** @test */
    public function test_field_catalog_returns_grouped_fields_with_keys_labels_and_filters(): void
    {
        $company = $this->makeCompany('A');
        // A viewer (lowest-privilege role) suffices — this is a reference, not
        // company data; the only gate is auth + company.access.
        $viewer = $this->makeUser($company, 'viewer');

        $response = $this->actingAs($viewer)->getJson('/api/documents/field-catalog');
        $response->assertOk();

        $groups = $response->json('groups');
        $this->assertIsArray($groups);
        // Canonical groups (macrodata-engineer rewrite): object / deal / buyer /
        // finances are resolver-backed; common / discount / branding are injected.
        foreach (['object', 'deal', 'buyer', 'finances', 'common', 'discount', 'branding'] as $g) {
            $this->assertArrayHasKey($g, $groups);
        }

        // Every field carries {key, label:{ru,en}, group, filters, example} and
        // the group matches the bucket it sits in.
        foreach ($groups as $group => $fields) {
            $this->assertNotEmpty($fields, "group {$group} should not be empty");
            foreach ($fields as $field) {
                $this->assertArrayHasKey('key', $field);
                $this->assertNotSame('', $field['key']);
                $this->assertArrayHasKey('label', $field);
                $this->assertArrayHasKey('ru', $field['label']);
                $this->assertArrayHasKey('en', $field['label']);
                $this->assertSame($group, $field['group']);
                $this->assertArrayHasKey('filters', $field);
                $this->assertIsArray($field['filters']);
                $this->assertArrayHasKey('example', $field);
            }
        }

        // The object group surfaces canonical estate.* keys.
        $objectKeys = collect($groups['object'])->pluck('key')->all();
        $this->assertContains('estate.price', $objectKeys);
        $this->assertContains('estate.complex_name', $objectKeys);

        // Money fields declare the words/rouble/format filter set.
        $price = collect($groups['object'])->firstWhere('key', 'estate.price');
        $this->assertContains('words', $price['filters']);
        $this->assertContains('rouble', $price['filters']);
        $this->assertContains('format', $price['filters']);

        // The deal group surfaces canonical deal.* keys with date filters.
        $dealKeys = collect($groups['deal'])->pluck('key')->all();
        $this->assertContains('deal.sum', $dealKeys);
        $dealDate = collect($groups['deal'])->firstWhere('key', 'deal.date');
        $this->assertContains('date', $dealDate['filters']);
        $this->assertContains('date_words', $dealDate['filters']);

        // The discount group surfaces the computed discount keys.
        $discountKeys = collect($groups['discount'])->pluck('key')->all();
        $this->assertContains('discount.percent', $discountKeys);
        $this->assertContains('discount.price_discounted', $discountKeys);

        // common.today is injected at render time.
        $commonKeys = collect($groups['common'])->pluck('key')->all();
        $this->assertContains('common.today', $commonKeys);
    }

    /** @test */
    public function test_field_catalog_buyer_group_flags_pii(): void
    {
        $company = $this->makeCompany('A');
        $admin = $this->makeUser($company, 'admin');

        $response = $this->actingAs($admin)->getJson('/api/documents/field-catalog');
        $response->assertOk();

        // buyer.* fields are PII — the catalogue must surface the pii flag so the
        // UI can mark them.
        $fullName = collect($response->json('groups.buyer'))->firstWhere('key', 'buyer.full_name');
        $this->assertNotNull($fullName);
        $this->assertTrue($fullName['pii'] ?? false);
    }

    /** @test */
    public function test_field_catalog_requires_authentication(): void
    {
        $this->getJson('/api/documents/field-catalog')->assertStatus(401);
    }
}
