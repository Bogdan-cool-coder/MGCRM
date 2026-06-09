<?php

declare(strict_types=1);

namespace Tests\Unit\MacroData;

use App\Http\Controllers\MacroDataLookupController;
use App\Models\Company;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\DocumentObjectDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for MacroDataLookupController.
 *
 * Focuses on:
 *   1. Schema-endpoint whitelist enforcement (no real DB needed).
 *   2. Schema-endpoint response shape using an in-memory SQLite macrodata stub.
 *   3. showEstateSell: 404 when resolver returns [].
 *
 * We do NOT test searchEstateSells + showEstateSell with real data here because
 * those rely on EstateSells Eloquent model which requires a real table — covered
 * by DocumentObjectDataServiceTest instead.
 */
class MacroDataLookupControllerTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private string $macrodataDbPath;
    private ConnectionService $mockConn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->macrodataDbPath = sys_get_temp_dir() . '/lookup_ctrl_test_' . uniqid('', true) . '.sqlite';
        touch($this->macrodataDbPath);

        config([
            'database.connections.macrodata' => [
                'driver'                  => 'sqlite',
                'database'                => $this->macrodataDbPath,
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('macrodata');

        $this->mockConn = $this->createMock(ConnectionService::class);
        $this->mockConn->method('connect')->willReturnCallback(static function () {});
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('macrodata');
        } catch (\Throwable) {
        }
        if ($this->macrodataDbPath && file_exists($this->macrodataDbPath)) {
            @unlink($this->macrodataDbPath);
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeController(): MacroDataLookupController
    {
        $mockResolver = $this->createMock(DocumentObjectDataService::class);

        return new MacroDataLookupController($this->mockConn, $mockResolver);
    }

    /**
     * Build a Request with `active_company_id` in attributes — the only attribute
     * that the active.company middleware actually sets.
     *
     * Previously the helper set `active_company` (a Company instance), which is
     * NOT what the middleware sets and was the root cause of the search/show/schema
     * returning [] / 503 in production (null → ConnectionService → TypeError).
     */
    private function makeRequest(array $query = [], ?int $companyId = null): Request
    {
        $request = Request::create('/api/macrodata/schema', 'GET', $query);

        // Seed a Company row so resolveActiveCompany() → Company::find() succeeds.
        $company = Company::create(['name' => 'Test Company']);
        $id      = $companyId ?? $company->id;
        $request->attributes->set('active_company_id', $id);

        return $request;
    }

    // -------------------------------------------------------------------------
    // Schema: whitelist enforcement
    // -------------------------------------------------------------------------

    public function test_schema_rejects_non_whitelisted_model(): void
    {
        $ctrl     = $this->makeController();
        $request  = $this->makeRequest(['model' => 'User']);   // not in whitelist
        $response = $ctrl->schema($request);

        $this->assertSame(422, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('allowed', $body);
    }

    public function test_schema_rejects_empty_model_param(): void
    {
        $ctrl     = $this->makeController();
        $request  = $this->makeRequest([]);
        $response = $ctrl->schema($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_schema_rejects_arbitrary_class_traversal(): void
    {
        $ctrl     = $this->makeController();
        $request  = $this->makeRequest(['model' => '../../../config/database']);
        $response = $ctrl->schema($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_schema_rejects_absolute_namespace(): void
    {
        // Full namespace should be rejected — only short class names allowed
        $ctrl     = $this->makeController();
        $request  = $this->makeRequest(['model' => 'App\\Models\\MacroData\\EstateDeals']);
        $response = $ctrl->schema($request);

        $this->assertSame(422, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Schema: connection unavailable graceful degradation
    // -------------------------------------------------------------------------

    public function test_schema_returns_503_when_connection_throws(): void
    {
        $mockConn = $this->createMock(ConnectionService::class);
        $mockConn->method('connect')->willThrowException(new \RuntimeException('no config'));

        $mockResolver = $this->createMock(DocumentObjectDataService::class);
        $ctrl         = new MacroDataLookupController($mockConn, $mockResolver);

        $request  = $this->makeRequest(['model' => 'EstateDeals']);
        $response = $ctrl->schema($request);

        $this->assertSame(503, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Schema: valid whitelisted model returns correct shape
    // -------------------------------------------------------------------------

    public function test_schema_returns_fields_for_whitelisted_model(): void
    {
        // Create a stub table in the SQLite macrodata DB to simulate the model's table.
        // We use 'estate_deals' which is what EstateDeals::getTable() returns.
        DB::connection('macrodata')->getSchemaBuilder()->create('estate_deals', function ($t) {
            $t->integer('deal_id')->primary();
            $t->string('agreement_number')->nullable();
            $t->decimal('deal_sum', 18, 2)->nullable();
            $t->date('deal_date')->nullable();
        });

        $ctrl     = $this->makeController();
        $request  = $this->makeRequest(['model' => 'EstateDeals']);
        $response = $ctrl->schema($request);

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);

        $this->assertSame('EstateDeals', $body['model']);
        $this->assertSame('estate_deals', $body['table']);
        $this->assertIsArray($body['fields']);
        $this->assertNotEmpty($body['fields']);

        // Each field must have 'name' and 'type' keys.
        foreach ($body['fields'] as $field) {
            $this->assertArrayHasKey('name', $field);
            $this->assertArrayHasKey('type', $field);
        }

        // deal_sum should be resolved as 'decimal' via casts
        $dealSumField = collect($body['fields'])->firstWhere('name', 'deal_sum');
        $this->assertNotNull($dealSumField, 'deal_sum field must be present');
        $this->assertSame('decimal', $dealSumField['type']);
    }

    // -------------------------------------------------------------------------
    // showEstateSell: 404 when resolver returns []
    // -------------------------------------------------------------------------

    public function test_show_estate_sell_returns_404_when_not_found(): void
    {
        $mockResolver = $this->createMock(DocumentObjectDataService::class);
        $mockResolver->method('resolve')->willReturn([]);

        $ctrl = new MacroDataLookupController($this->mockConn, $mockResolver);

        // Use active_company_id (int) — the attribute actually set by the middleware.
        $company = Company::create(['name' => 'Test Company']);
        $request = Request::create('/api/macrodata/estate-sells/999', 'GET');
        $request->attributes->set('active_company_id', $company->id);

        $response = $ctrl->showEstateSell($request, 999);

        $this->assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // showEstateSell: 200 with data + label
    // -------------------------------------------------------------------------

    public function test_show_estate_sell_returns_data_and_label(): void
    {
        // Canonical group.field keys from DocumentObjectDataService v2.
        $fields = [
            'estate.number'       => '42',
            'estate.area'         => '65.5',
            'estate.complex_name' => 'Солнечный',
            'estate.house_name'   => 'Корпус 1',
            'estate.price'        => '3500000',
        ];

        $mockResolver = $this->createMock(DocumentObjectDataService::class);
        $mockResolver->method('resolve')->willReturn($fields);

        $ctrl = new MacroDataLookupController($this->mockConn, $mockResolver);

        $company = Company::create(['name' => 'Test Company']);
        $request = Request::create('/api/macrodata/estate-sells/42', 'GET');
        $request->attributes->set('active_company_id', $company->id);

        $response = $ctrl->showEstateSell($request, 42);

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('label', $body);
        $this->assertSame('42', $body['data']['estate.number']);
        $this->assertStringContainsString('кв.42', $body['label']);
        $this->assertStringContainsString('Солнечный', $body['label']);
    }

    // -------------------------------------------------------------------------
    // resolveActiveCompany: connect receives valid Company (root-cause regression test)
    // -------------------------------------------------------------------------

    /**
     * Regression: all three public methods used to read `active_company` (a Company
     * object) from request attributes — an attribute that the middleware never sets.
     * The middleware sets `active_company_id` (int) only. Passing null to
     * ConnectionService::connect() caused a TypeError that the try/catch swallowed,
     * returning [] / 503 silently.
     *
     * This test verifies that ConnectionService::connect() is called with a Company
     * instance (not null) when `active_company_id` is present in attributes.
     */
    public function test_schema_passes_company_instance_to_connection_service(): void
    {
        $company = Company::create(['name' => 'Test Company']);

        $capturedArg = null;
        $mockConn    = $this->createMock(ConnectionService::class);
        $mockConn->method('connect')->willReturnCallback(static function ($arg) use (&$capturedArg) {
            $capturedArg = $arg;
        });

        $mockResolver = $this->createMock(DocumentObjectDataService::class);
        $ctrl         = new MacroDataLookupController($mockConn, $mockResolver);

        DB::connection('macrodata')->getSchemaBuilder()->create('estate_deals', function ($t) {
            $t->integer('deal_id')->primary();
        });

        $request = Request::create('/api/macrodata/schema', 'GET', ['model' => 'EstateDeals']);
        $request->attributes->set('active_company_id', $company->id);

        $response = $ctrl->schema($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(Company::class, $capturedArg,
            'ConnectionService::connect() must receive a Company instance, not null — ' .
            'root cause of the silent [] / 503 bug (active_company_id vs active_company).'
        );
        $this->assertSame($company->id, $capturedArg->id);
    }

    public function test_show_estate_sell_returns_503_when_company_not_found(): void
    {
        // active_company_id pointing to a non-existent company → resolveActiveCompany returns null.
        $mockResolver = $this->createMock(DocumentObjectDataService::class);
        $mockResolver->expects($this->never())->method('resolve');

        $ctrl    = new MacroDataLookupController($this->mockConn, $mockResolver);
        $request = Request::create('/api/macrodata/estate-sells/1', 'GET');
        $request->attributes->set('active_company_id', 99999);

        $response = $ctrl->showEstateSell($request, 1);

        $this->assertSame(503, $response->getStatusCode());
    }
}
