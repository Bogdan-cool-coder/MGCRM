<?php

declare(strict_types=1);

namespace Tests\Unit\MacroData;

use App\Models\Company;
use App\Services\MacroData\ConnectionService;
use App\Services\MacroData\DocumentObjectDataService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unit tests for DocumentObjectDataService.
 *
 * Strategy: file-backed SQLite for the 'macrodata' connection.
 * ConnectionService::connect() is mocked as a no-op so no real MySQL is required.
 *
 * Tables: estate_sells, estate_houses, geo_city_complex, estate_restoration,
 *         estate_deals, contacts, finances
 *
 * Canonical key format: group.field  (e.g. estate.area, deal.sum, buyer.full_name)
 */
class DocumentObjectDataServiceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private DocumentObjectDataService $service;
    private Company $company;
    private string $macrodataDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->macrodataDbPath = sys_get_temp_dir() . '/doc_obj_test_' . uniqid('', true) . '.sqlite';
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

        $schema = DB::connection('macrodata')->getSchemaBuilder();

        $schema->create('geo_city_complex', function ($t) {
            $t->integer('geo_complex_id')->primary();
            $t->string('geo_complex_name')->nullable();
        });

        $schema->create('estate_houses', function ($t) {
            $t->integer('house_id')->primary();
            $t->integer('geo_city_complex_id')->nullable();
            $t->string('public_house_name')->nullable();
            $t->string('geo_city_name')->nullable();
            $t->string('geo_street_name')->nullable();
            $t->string('geo_house')->nullable();
            $t->string('geo_korpus')->nullable();
        });

        $schema->create('estate_restoration', function ($t) {
            $t->integer('id')->primary();
            $t->string('name')->nullable();
        });

        $schema->create('contacts', function ($t) {
            $t->integer('id')->primary();
            $t->string('contacts_buy_name')->nullable();
            $t->string('name_last')->nullable();
            $t->string('name_first')->nullable();
            $t->string('name_middle')->nullable();
            $t->date('contacts_buy_dob')->nullable();
            $t->string('contacts_buy_phones')->nullable();
            $t->string('contacts_buy_emails')->nullable();
            $t->string('fl_inn')->nullable();
            $t->string('comm_inn')->nullable();
            $t->string('snils')->nullable();
            $t->string('passport_address')->nullable();
        });

        $schema->create('estate_deals', function ($t) {
            $t->integer('deal_id')->primary();
            $t->string('agreement_number')->nullable();
            $t->decimal('deal_sum', 18, 2)->nullable();
            $t->decimal('deal_price', 18, 2)->nullable();
            $t->decimal('deal_area', 10, 4)->nullable();
            $t->decimal('deal_sum_addons', 18, 2)->nullable();
            $t->date('deal_date')->nullable();
            $t->date('deal_date_start')->nullable();
            $t->integer('estate_sell_id')->nullable();
            $t->integer('contacts_buy_id')->nullable();
            $t->decimal('finances_income', 18, 2)->nullable();
        });

        $schema->create('finances', function ($t) {
            $t->integer('id')->primary();
            $t->integer('deal_id')->nullable();
            $t->decimal('summa', 18, 2)->nullable();
            $t->datetime('date_added')->nullable();
            $t->datetime('date_to')->nullable();
            $t->tinyInteger('status')->nullable();
            $t->integer('types_id')->nullable();
            $t->tinyInteger('is_first_payment')->nullable();
        });

        $schema->create('estate_sells', function ($t) {
            $t->integer('estate_sell_id')->primary();
            $t->integer('house_id')->nullable();
            $t->integer('estate_restoration_id')->nullable();
            $t->integer('deal_id')->nullable();
            $t->string('geo_flatnum')->nullable();
            $t->decimal('estate_area', 10, 4)->nullable();
            $t->decimal('estate_area_inside', 10, 4)->nullable();
            $t->decimal('estate_areaBti', 10, 4)->nullable();
            $t->decimal('estate_areaBti_terrace', 10, 4)->nullable();
            $t->integer('estate_floor')->nullable();
            $t->integer('estate_rooms')->nullable();
            $t->decimal('estate_price', 18, 4)->nullable();
            $t->decimal('estate_price_m2', 18, 4)->nullable();
            $t->decimal('estate_price_action', 18, 4)->nullable();
            $t->decimal('estate_restoration_price', 18, 4)->nullable();
        });

        $this->company = new Company(['id' => 1]);
        $this->company->setAttribute('id', 1);

        $mockConn = $this->createMock(ConnectionService::class);
        $mockConn->method('connect')->willReturnCallback(static function () {});

        $this->service = new DocumentObjectDataService($mockConn);
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
    // Not found / empty scenarios
    // -------------------------------------------------------------------------

    public function test_returns_empty_array_when_object_not_found(): void
    {
        $result = $this->service->resolve($this->company, 999);

        $this->assertSame([], $result);
    }

    public function test_returns_empty_array_when_connection_throws(): void
    {
        $mockConn = $this->createMock(ConnectionService::class);
        $mockConn->method('connect')->willThrowException(new \RuntimeException('no config'));

        $service = new DocumentObjectDataService($mockConn);
        $result  = $service->resolve($this->company, 1);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Minimal object — estate.* keys, no relations
    // -------------------------------------------------------------------------

    public function test_resolves_minimal_estate_fields(): void
    {
        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id'           => 1,
            'geo_flatnum'              => '42',
            'estate_area'              => 55.50,
            'estate_floor'             => 7,
            'estate_rooms'             => 2,
            'estate_price'             => 3500000.0,
            'estate_price_m2'          => 63063.0,
            'estate_price_action'      => 3200000.0,
            'estate_restoration_price' => 500000.0,
        ]);

        $result = $this->service->resolve($this->company, 1);

        $this->assertSame('42', $result['estate.number']);
        $this->assertSame('7', $result['estate.floor']);
        $this->assertSame('2', $result['estate.rooms']);
        $this->assertSame('55.5', $result['estate.area']);
        $this->assertSame('', $result['estate.complex_name']);
        $this->assertSame('', $result['estate.house_name']);
        $this->assertSame('', $result['estate.address']);
        $this->assertSame('', $result['estate.restoration_name']);

        // deal.* keys absent (no deal linked)
        $this->assertArrayNotHasKey('deal.number', $result);
        $this->assertArrayNotHasKey('deal.sum', $result);
    }

    // -------------------------------------------------------------------------
    // estate.address assembled from geo_* columns
    // -------------------------------------------------------------------------

    public function test_resolves_address_from_geo_columns(): void
    {
        DB::connection('macrodata')->table('estate_houses')->insert([
            'house_id'        => 5,
            'geo_city_name'   => 'Краснодар',
            'geo_street_name' => 'ул. Красная',
            'geo_house'       => '1',
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 2,
            'house_id'       => 5,
            'geo_flatnum'    => '101',
            'estate_area'    => 65.40,
        ]);

        $result = $this->service->resolve($this->company, 2);

        $this->assertStringContainsString('Краснодар', $result['estate.address']);
        $this->assertStringContainsString('ул. Красная', $result['estate.address']);
        $this->assertStringContainsString('1', $result['estate.address']);
    }

    // -------------------------------------------------------------------------
    // complex_name via house → geo_city_complex
    // -------------------------------------------------------------------------

    public function test_resolves_complex_name_from_house_relation(): void
    {
        DB::connection('macrodata')->table('geo_city_complex')->insert([
            'geo_complex_id'   => 10,
            'geo_complex_name' => 'Солнечный',
        ]);

        DB::connection('macrodata')->table('estate_houses')->insert([
            'house_id'            => 5,
            'geo_city_complex_id' => 10,
            'public_house_name'   => 'Корпус 1',
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 2,
            'house_id'       => 5,
            'geo_flatnum'    => '101',
            'estate_area'    => 65.40,
        ]);

        $result = $this->service->resolve($this->company, 2);

        $this->assertSame('Солнечный', $result['estate.complex_name']);
        $this->assertSame('Корпус 1', $result['estate.house_name']);
    }

    // -------------------------------------------------------------------------
    // deal.* fields
    // -------------------------------------------------------------------------

    public function test_resolves_deal_fields(): void
    {
        DB::connection('macrodata')->table('estate_deals')->insert([
            'deal_id'          => 20,
            'agreement_number' => 'ДДУ-2024-001',
            'deal_sum'         => 3750000.0,
            'deal_price'       => 3750000.0,
            'deal_area'        => 65.4,
            'deal_date'        => '2024-06-15',
            'deal_date_start'  => '2024-05-01',
            'deal_sum_addons'  => 50000.0,
            'estate_sell_id'   => 3,
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 3,
            'deal_id'        => 20,
            'geo_flatnum'    => '77',
            'estate_price'   => 3750000.0,
        ]);

        $result = $this->service->resolve($this->company, 3);

        $this->assertSame('ДДУ-2024-001', $result['deal.number']);
        $this->assertSame('2024-06-15', $result['deal.date']);
        $this->assertSame('2024-05-01', $result['deal.date_start']);
        $this->assertSame('3750000', $result['deal.sum']);
        $this->assertSame('3750000', $result['deal.price']);
        $this->assertSame('65.4', $result['deal.area']);
        $this->assertSame('50000', $result['deal.sum_addons']);

        // deal.price_m2 derived: 3750000 / 65.4 ≈ 57339.xx
        $this->assertIsNumeric($result['deal.price_m2']);
        $this->assertGreaterThan(50000, (float) $result['deal.price_m2']);
    }

    // -------------------------------------------------------------------------
    // buyer.* fields from contactsBuy
    // -------------------------------------------------------------------------

    public function test_resolves_buyer_fields(): void
    {
        DB::connection('macrodata')->table('contacts')->insert([
            'id'                 => 100,
            'name_last'          => 'Иванов',
            'name_first'         => 'Иван',
            'name_middle'        => 'Иванович',
            'contacts_buy_dob'   => '1985-03-22',
            'contacts_buy_phones' => '+79001234567',
            'contacts_buy_emails' => 'ivanov@example.com',
            'fl_inn'             => '234567890123',
            'snils'              => '123-456-789 01',
            'passport_address'   => 'г. Москва, ул. Ленина, д.1',
        ]);

        DB::connection('macrodata')->table('estate_deals')->insert([
            'deal_id'         => 30,
            'agreement_number' => 'ДДУ-001',
            'deal_sum'        => 4000000.0,
            'estate_sell_id'  => 4,
            'contacts_buy_id' => 100,
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 4,
            'deal_id'        => 30,
            'geo_flatnum'    => '10',
        ]);

        $result = $this->service->resolve($this->company, 4);

        $this->assertSame('Иванов Иван Иванович', $result['buyer.full_name']);
        $this->assertSame('1985-03-22', $result['buyer.dob']);
        $this->assertSame('+79001234567', $result['buyer.phone']);
        $this->assertSame('ivanov@example.com', $result['buyer.email']);
        $this->assertSame('234567890123', $result['buyer.inn']);
        $this->assertSame('123-456-789 01', $result['buyer.snils']);
        $this->assertSame('г. Москва, ул. Ленина, д.1', $result['buyer.address_reg']);
    }

    public function test_buyer_full_name_falls_back_to_contacts_buy_name(): void
    {
        DB::connection('macrodata')->table('contacts')->insert([
            'id'                => 101,
            'contacts_buy_name' => 'Петров Пётр Петрович',
        ]);

        DB::connection('macrodata')->table('estate_deals')->insert([
            'deal_id'         => 31,
            'estate_sell_id'  => 5,
            'contacts_buy_id' => 101,
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 5,
            'deal_id'        => 31,
        ]);

        $result = $this->service->resolve($this->company, 5);

        $this->assertSame('Петров Пётр Петрович', $result['buyer.full_name']);
    }

    public function test_buyer_inn_falls_back_to_comm_inn(): void
    {
        DB::connection('macrodata')->table('contacts')->insert([
            'id'      => 102,
            'fl_inn'  => null,
            'comm_inn' => '7700000000',
        ]);

        DB::connection('macrodata')->table('estate_deals')->insert([
            'deal_id'         => 32,
            'estate_sell_id'  => 6,
            'contacts_buy_id' => 102,
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 6,
            'deal_id'        => 32,
        ]);

        $result = $this->service->resolve($this->company, 6);

        $this->assertSame('7700000000', $result['buyer.inn']);
    }

    // -------------------------------------------------------------------------
    // finances.* aggregation
    // -------------------------------------------------------------------------

    public function test_resolves_finances_aggregates(): void
    {
        DB::connection('macrodata')->table('estate_deals')->insert([
            'deal_id'          => 40,
            'estate_sell_id'   => 7,
            'deal_sum'         => 1000000.0,
            'finances_income'  => 300000.0,
        ]);

        DB::connection('macrodata')->table('finances')->insert([
            'id'         => 1,
            'deal_id'    => 40,
            'summa'      => 200000.0,
            'date_added' => '2024-01-15 10:00:00',
        ]);

        DB::connection('macrodata')->table('finances')->insert([
            'id'         => 2,
            'deal_id'    => 40,
            'summa'      => 100000.0,
            'date_added' => '2024-03-01 10:00:00',
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 7,
            'deal_id'        => 40,
        ]);

        $result = $this->service->resolve($this->company, 7);

        $this->assertSame('200000', $result['finances.first_payment_sum']);
        $this->assertSame('2024-01-15', $result['finances.first_payment_date']);
        $this->assertSame('2024-03-01', $result['finances.last_payment_date']);
        $this->assertSame('2', $result['finances.count']);

        // total_paid from denorm field finances_income
        $this->assertSame('300000', $result['finances.total_paid']);

        // balance = deal_sum - finances_income = 700000
        $this->assertSame('700000', $result['finances.balance']);
    }

    public function test_finances_empty_when_no_payments(): void
    {
        DB::connection('macrodata')->table('estate_deals')->insert([
            'deal_id'         => 41,
            'estate_sell_id'  => 8,
            'deal_sum'        => 2000000.0,
            'finances_income' => 0.0,
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 8,
            'deal_id'        => 41,
        ]);

        $result = $this->service->resolve($this->company, 8);

        $this->assertSame('', $result['finances.first_payment_sum']);
        $this->assertSame('', $result['finances.first_payment_date']);
        $this->assertSame('', $result['finances.last_payment_date']);
        $this->assertSame('0', $result['finances.count']);
    }

    // -------------------------------------------------------------------------
    // Restoration name
    // -------------------------------------------------------------------------

    public function test_resolves_restoration_name(): void
    {
        DB::connection('macrodata')->table('estate_restoration')->insert([
            'id'   => 3,
            'name' => 'Под ключ',
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id'        => 9,
            'estate_restoration_id' => 3,
            'geo_flatnum'           => '12',
        ]);

        $result = $this->service->resolve($this->company, 9);

        $this->assertSame('Под ключ', $result['estate.restoration_name']);
    }

    // -------------------------------------------------------------------------
    // Numeric helpers
    // -------------------------------------------------------------------------

    public function test_area_terrace_resolved(): void
    {
        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id'       => 10,
            'estate_areaBti_terrace' => 5.4,
        ]);

        $result = $this->service->resolve($this->company, 10);

        $this->assertSame('5.4', $result['estate.area_terrace']);
    }

    public function test_zero_price_returns_zero_raw(): void
    {
        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 11,
            'estate_price'   => 0.0,
        ]);

        $result = $this->service->resolve($this->company, 11);

        $this->assertSame('0', $result['estate.price']);
    }

    public function test_null_price_returns_empty(): void
    {
        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 12,
            'estate_price'   => null,
        ]);

        $result = $this->service->resolve($this->company, 12);

        $this->assertSame('', $result['estate.price']);
    }

    // -------------------------------------------------------------------------
    // Required estate.* keys always present
    // -------------------------------------------------------------------------

    public function test_all_estate_keys_present(): void
    {
        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 20,
        ]);

        $result   = $this->service->resolve($this->company, 20);

        $required = [
            'estate.area', 'estate.area_bti', 'estate.area_inside', 'estate.area_terrace',
            'estate.price', 'estate.price_m2', 'estate.price_action',
            'estate.floor', 'estate.rooms', 'estate.number',
            'estate.restoration_name', 'estate.restoration_price',
            'estate.house_name', 'estate.complex_name', 'estate.address',
        ];

        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    // -------------------------------------------------------------------------
    // buyer.phone first value extraction
    // -------------------------------------------------------------------------

    public function test_buyer_phone_extracts_first_from_comma_list(): void
    {
        DB::connection('macrodata')->table('contacts')->insert([
            'id'                  => 110,
            'contacts_buy_phones' => '+79001234567, +79009999999',
        ]);

        DB::connection('macrodata')->table('estate_deals')->insert([
            'deal_id'         => 50,
            'estate_sell_id'  => 21,
            'contacts_buy_id' => 110,
        ]);

        DB::connection('macrodata')->table('estate_sells')->insert([
            'estate_sell_id' => 21,
            'deal_id'        => 50,
        ]);

        $result = $this->service->resolve($this->company, 21);

        $this->assertSame('+79001234567', $result['buyer.phone']);
    }
}
