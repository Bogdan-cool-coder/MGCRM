<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Models\Company;
use App\Models\CompanyBranding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for CompanyBrandingController:
 *   - GET returns defaults when no branding row exists, and the saved values
 *     when it does (any role with company access).
 *   - PUT upsert: admin of the company / superadmin OK; analyst / viewer 403.
 *   - PUT is partial (a colors-only update doesn't wipe fonts).
 *   - POST logo: mime + size validation, ACL, returns the public URL.
 *   - Cross-company: superadmin OK, admin of another company 403.
 */
class CompanyBrandingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name): Company
    {
        return Company::create([
            'name'               => $name,
            'macrodata_host'     => '127.0.0.1',
            'macrodata_port'     => 3306,
            'macrodata_database' => 'macro_test',
            'macrodata_username' => 'root',
            'macrodata_password' => 'secret',
            'crm_url'            => 'https://crm.test',
        ]);
    }

    private function makeUser(Company $company, string $role): User
    {
        return User::factory()->create([
            'company_id'        => $company->id,
            'active_company_id' => $company->id,
            'role'              => $role,
            'company_accesses'  => [['company_id' => $company->id, 'role' => $role]],
        ]);
    }

    /**
     * Bytes of a valid 1×1 PNG. Used instead of UploadedFile::fake()->image()
     * because the test container lacks the GD extension (image() needs it),
     * and the `mimes:png` rule guesses the extension from the file's real magic
     * bytes — so the content must be a genuine PNG, not arbitrary bytes.
     */
    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        );
    }

    // -------------------------------------------------------------------------
    // GET — defaults + saved
    // -------------------------------------------------------------------------

    /** @test */
    public function test_get_returns_defaults_when_no_branding(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');

        $response = $this->actingAs($viewer)->getJson("/api/companies/{$company->id}/branding");

        $response->assertOk();
        $response->assertJsonPath('company_id', $company->id);
        $response->assertJsonPath('logo_path', null);
        $response->assertJsonPath('logo_url', null);
        $response->assertJsonPath('colors.primary', CompanyBranding::DEFAULT_COLORS['primary']);
        $response->assertJsonPath('colors.bg', CompanyBranding::DEFAULT_COLORS['bg']);
        $this->assertNotNull($response->json('fonts'));
    }

    /** @test */
    public function test_get_returns_saved_branding(): void
    {
        $company = $this->makeCompany('A');
        $admin   = $this->makeUser($company, 'admin');
        CompanyBranding::factory()->create([
            'company_id' => $company->id,
            'colors'     => ['primary' => '#abcdef', 'bg' => '#000000'],
        ]);

        $response = $this->actingAs($admin)->getJson("/api/companies/{$company->id}/branding");

        $response->assertOk();
        $response->assertJsonPath('colors.primary', '#abcdef');
        $response->assertJsonPath('header.ru', 'Шапка');
    }

    /** @test */
    public function test_analyst_can_read_branding(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $this->actingAs($analyst)->getJson("/api/companies/{$company->id}/branding")->assertOk();
    }

    /** @test */
    public function test_get_forbidden_without_company_access(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $adminA   = $this->makeUser($companyA, 'admin');

        $this->actingAs($adminA)->getJson("/api/companies/{$companyB->id}/branding")->assertStatus(403);
    }

    /** @test */
    public function test_superadmin_can_read_cross_company_branding(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $superadmin = User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $home->id,
            'role'              => 'superadmin',
            'company_accesses'  => [['company_id' => $home->id, 'role' => 'superadmin']],
        ]);

        $this->actingAs($superadmin)->getJson("/api/companies/{$other->id}/branding")->assertOk();
    }

    // -------------------------------------------------------------------------
    // PUT — upsert + ACL
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_update_branding(): void
    {
        $company = $this->makeCompany('A');
        $admin   = $this->makeUser($company, 'admin');

        $response = $this->actingAs($admin)->putJson("/api/companies/{$company->id}/branding", [
            'colors' => ['primary' => '#112233', 'secondary' => '#445566', 'accent' => '#778899', 'text' => '#000', 'bg' => '#fff'],
            'fonts'  => ['heading' => 'Roboto', 'body' => 'Roboto'],
            'header' => ['ru' => 'Шапка КП', 'en' => 'Proposal header'],
            'footer' => ['ru' => 'Подвал', 'en' => 'Footer'],
            'requisites' => ['inn' => '7701234567'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('colors.primary', '#112233');
        $response->assertJsonPath('header.en', 'Proposal header');

        $this->assertDatabaseHas('company_brandings', [
            'company_id' => $company->id,
            'updated_by' => $admin->id,
        ]);
    }

    /** @test */
    public function test_put_is_partial_and_preserves_other_fields(): void
    {
        $company = $this->makeCompany('A');
        $admin   = $this->makeUser($company, 'admin');
        CompanyBranding::factory()->create([
            'company_id' => $company->id,
            'fonts'      => ['heading' => 'Inter', 'body' => 'Inter'],
            'colors'     => ['primary' => '#000000'],
        ]);

        // Send only colors — fonts must survive untouched.
        $response = $this->actingAs($admin)->putJson("/api/companies/{$company->id}/branding", [
            'colors' => ['primary' => '#ff0000'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('colors.primary', '#ff0000');
        $response->assertJsonPath('fonts.heading', 'Inter');
    }

    /** @test */
    public function test_analyst_cannot_update_branding(): void
    {
        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $this->actingAs($analyst)->putJson("/api/companies/{$company->id}/branding", [
            'colors' => ['primary' => '#fff'],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('company_brandings', ['company_id' => $company->id]);
    }

    /** @test */
    public function test_viewer_cannot_update_branding(): void
    {
        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');

        $this->actingAs($viewer)->putJson("/api/companies/{$company->id}/branding", [
            'colors' => ['primary' => '#fff'],
        ])->assertStatus(403);
    }

    /** @test */
    public function test_admin_cannot_update_other_company_branding(): void
    {
        $companyA = $this->makeCompany('A');
        $companyB = $this->makeCompany('B');
        $adminA   = $this->makeUser($companyA, 'admin');

        $this->actingAs($adminA)->putJson("/api/companies/{$companyB->id}/branding", [
            'colors' => ['primary' => '#fff'],
        ])->assertStatus(403);
    }

    /** @test */
    public function test_superadmin_can_update_cross_company_branding(): void
    {
        $home  = $this->makeCompany('Home');
        $other = $this->makeCompany('Other');
        $superadmin = User::factory()->create([
            'company_id'        => $home->id,
            'active_company_id' => $home->id,
            'role'              => 'superadmin',
            'company_accesses'  => [['company_id' => $home->id, 'role' => 'superadmin']],
        ]);

        $this->actingAs($superadmin)->putJson("/api/companies/{$other->id}/branding", [
            'colors' => ['primary' => '#abcdef'],
        ])->assertOk();

        $this->assertDatabaseHas('company_brandings', ['company_id' => $other->id]);
    }

    // -------------------------------------------------------------------------
    // POST logo
    // -------------------------------------------------------------------------

    /** @test */
    public function test_admin_can_upload_png_logo(): void
    {
        Storage::fake('public');

        $company = $this->makeCompany('A');
        $admin   = $this->makeUser($company, 'admin');

        $response = $this->actingAs($admin)->postJson("/api/companies/{$company->id}/branding/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', $this->pngBytes()),
        ]);

        $response->assertOk();
        $path = $response->json('logo_path');
        $this->assertNotNull($path);
        $this->assertStringContainsString("branding/{$company->id}/", $path);
        $this->assertStringContainsString('/storage/', (string) $response->json('logo_url'));
        Storage::disk('public')->assertExists($path);

        $this->assertDatabaseHas('company_brandings', [
            'company_id' => $company->id,
            'logo_path'  => $path,
        ]);
    }

    /** @test */
    public function test_logo_upload_rejects_non_image_mime(): void
    {
        Storage::fake('public');

        $company = $this->makeCompany('A');
        $admin   = $this->makeUser($company, 'admin');

        $this->actingAs($admin)->postJson("/api/companies/{$company->id}/branding/logo", [
            'logo' => UploadedFile::fake()->create('payload.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    /** @test */
    public function test_logo_upload_rejects_oversize_file(): void
    {
        Storage::fake('public');

        $company = $this->makeCompany('A');
        $admin   = $this->makeUser($company, 'admin');

        // 3 MB — over the 2 MB cap.
        $this->actingAs($admin)->postJson("/api/companies/{$company->id}/branding/logo", [
            'logo' => UploadedFile::fake()->create('big.png', 3072, 'image/png'),
        ])->assertStatus(422);
    }

    /** @test */
    public function test_analyst_cannot_upload_logo(): void
    {
        Storage::fake('public');

        $company = $this->makeCompany('A');
        $analyst = $this->makeUser($company, 'analyst');

        $this->actingAs($analyst)->postJson("/api/companies/{$company->id}/branding/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', $this->pngBytes()),
        ])->assertStatus(403);
    }

    /** @test */
    public function test_viewer_cannot_upload_logo(): void
    {
        Storage::fake('public');

        $company = $this->makeCompany('A');
        $viewer  = $this->makeUser($company, 'viewer');

        $this->actingAs($viewer)->postJson("/api/companies/{$company->id}/branding/logo", [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', $this->pngBytes()),
        ])->assertStatus(403);
    }

    /** @test */
    public function test_logo_upload_replaces_old_file(): void
    {
        Storage::fake('public');

        $company = $this->makeCompany('A');
        $admin   = $this->makeUser($company, 'admin');

        $first = $this->actingAs($admin)->postJson("/api/companies/{$company->id}/branding/logo", [
            'logo' => UploadedFile::fake()->createWithContent('one.png', $this->pngBytes()),
        ]);
        $firstPath = $first->json('logo_path');

        $second = $this->actingAs($admin)->postJson("/api/companies/{$company->id}/branding/logo", [
            'logo' => UploadedFile::fake()->createWithContent('two.png', $this->pngBytes()),
        ]);
        $secondPath = $second->json('logo_path');

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }
}
