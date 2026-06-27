<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PriceImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    /**
     * Build a minimal xlsx UploadedFile for testing.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function buildExcel(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['code', 'name', 'description', 'group', 'pricing_type', 'plan_name', 'plan_unit', 'plan_code', 'currency_code', 'amount', 'sort_order', 'is_active'];
        $sheet->fromArray([$headers], null, 'A1');

        foreach ($rows as $i => $row) {
            $rowData = [];
            foreach ($headers as $header) {
                $rowData[] = $row[$header] ?? '';
            }
            $sheet->fromArray([$rowData], null, 'A'.($i + 2));
        }

        $path = sys_get_temp_dir().'/price_import_feature_'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            path: $path,
            originalName: 'import.xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            error: null,
            test: true,
        );
    }

    private function postImport(string $url, UploadedFile $file, array $extra = []): TestResponse
    {
        return $this->post($url, array_merge(['file' => $file], $extra), [
            'Accept' => 'application/json',
        ]);
    }

    public function test_import_excel_inserts_new_products(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $file = $this->buildExcel([
            ['code' => 'new_prod_a', 'name' => 'New Product A', 'currency_code' => 'KZT', 'amount' => 10000],
            ['code' => 'new_prod_b', 'name' => 'New Product B', 'currency_code' => 'RUB', 'amount' => 5000],
        ]);

        $response = $this->postImport('/api/catalog/price-import', $file);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(2, $data['inserted']);
        $this->assertSame(0, $data['updated']);

        $this->assertDatabaseHas('catalog_products', ['code' => 'new_prod_a']);
        $this->assertDatabaseHas('catalog_products', ['code' => 'new_prod_b']);
    }

    public function test_import_excel_updates_existing_products(): void
    {
        $existing = Product::factory()->create(['code' => 'existing_prod', 'name' => 'Old Name']);
        ProductPrice::factory()->create(['product_id' => $existing->id, 'currency_code' => 'KZT', 'amount' => 100_00, 'plan_id' => null]);

        Sanctum::actingAs($this->admin, ['*']);

        // amount 200.00 → 20000 kopecks
        $file = $this->buildExcel([
            ['code' => 'existing_prod', 'name' => 'Updated Name', 'currency_code' => 'KZT', 'amount' => 200.00],
        ]);

        $response = $this->postImport('/api/catalog/price-import', $file);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame(0, $data['inserted']);
        $this->assertSame(1, $data['updated']);

        // Price should be updated: 20000 kopecks (200.00 × 100).
        $this->assertDatabaseHas('catalog_product_prices', [
            'product_id' => $existing->id,
            'currency_code' => 'KZT',
            'amount' => 20000,
        ]);
    }

    public function test_import_excel_dry_run_does_not_write(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $file = $this->buildExcel([
            ['code' => 'dry_run_prod', 'name' => 'Dry Run Product', 'currency_code' => 'KZT', 'amount' => 5000],
        ]);

        $response = $this->postImport('/api/catalog/price-import/preview', $file);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertTrue($data['dry_run']);

        // Nothing written to DB.
        $this->assertDatabaseMissing('catalog_products', ['code' => 'dry_run_prod']);
    }

    /**
     * Regression CRM-audit: dry-run preview must return would_insert/would_update
     * so the FE preview dialog shows real projected counts instead of 0/0.
     * inserted/updated must be 0 in dry-run mode; they carry counts on real runs only.
     */
    public function test_dry_run_preview_returns_projected_counts(): void
    {
        // One product already in DB → it will be "updated".
        Product::factory()->create(['code' => 'existing_for_dry']);

        Sanctum::actingAs($this->admin, ['*']);

        $file = $this->buildExcel([
            ['code' => 'new_for_dry',      'name' => 'New For Dry',      'currency_code' => 'KZT', 'amount' => 1000],
            ['code' => 'existing_for_dry', 'name' => 'Existing For Dry', 'currency_code' => 'KZT', 'amount' => 2000],
        ]);

        $response = $this->postImport('/api/catalog/price-import/preview', $file);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertTrue($data['dry_run']);
        // Projected counts present and correct.
        $this->assertSame(1, $data['would_insert'], 'would_insert must count new products');
        $this->assertSame(1, $data['would_update'], 'would_update must count existing products');
        // Real-run counters must be zero in dry-run.
        $this->assertSame(0, $data['inserted']);
        $this->assertSame(0, $data['updated']);
        // Nothing actually written.
        $this->assertDatabaseMissing('catalog_products', ['code' => 'new_for_dry']);
    }

    /**
     * Regression: on a real (non-dry) run inserted/updated must carry counts,
     * and would_insert/would_update must be zero (not meaningful outside preview).
     */
    public function test_real_run_returns_actual_counts_not_projected(): void
    {
        Product::factory()->create(['code' => 'existing_real']);

        Sanctum::actingAs($this->admin, ['*']);

        $file = $this->buildExcel([
            ['code' => 'new_real',      'name' => 'New Real',      'currency_code' => 'KZT', 'amount' => 1000],
            ['code' => 'existing_real', 'name' => 'Existing Real', 'currency_code' => 'KZT', 'amount' => 2000],
        ]);

        $response = $this->postImport('/api/catalog/price-import', $file);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertFalse($data['dry_run']);
        $this->assertSame(1, $data['inserted']);
        $this->assertSame(1, $data['updated']);
        // Projected counters must be zero on real run.
        $this->assertSame(0, $data['would_insert']);
        $this->assertSame(0, $data['would_update']);
        // Data was written.
        $this->assertDatabaseHas('catalog_products', ['code' => 'new_real']);
    }

    public function test_import_excel_invalid_currency_returns_validation_error(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $file = $this->buildExcel([
            ['code' => 'prod_bad_currency', 'name' => 'Bad Currency', 'currency_code' => 'XYZ', 'amount' => 1000],
        ]);

        $response = $this->postImport('/api/catalog/price-import', $file);

        $response->assertStatus(422);
        $data = $response->json('data');
        $this->assertNotEmpty($data['errors']);
        $this->assertSame(1, $data['skipped']);
    }

    public function test_import_excel_negative_amount_error(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $file = $this->buildExcel([
            ['code' => 'prod_neg_amount', 'name' => 'Negative Amount', 'currency_code' => 'KZT', 'amount' => -100],
        ]);

        $response = $this->postImport('/api/catalog/price-import', $file);

        $response->assertStatus(422);
        $data = $response->json('data');
        $this->assertNotEmpty($data['errors']);
    }

    // ---- template download ----

    public function test_admin_can_download_import_template(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->get('/api/catalog/price-import/template', ['Accept' => '*/*']);

        $response->assertOk();
        $this->assertStringContainsString(
            'openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type') ?? '',
        );
        // The body must be non-empty (xlsx PK zip signature).
        $this->assertNotEmpty($response->getContent());
    }

    public function test_manager_cannot_download_import_template(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        Sanctum::actingAs($manager, ['*']);

        $this->get('/api/catalog/price-import/template', ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
