<?php

declare(strict_types=1);

namespace Tests\Unit\Contracts;

use App\Domain\Contracts\Models\LicensorBankAccount;
use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Contracts\Services\LicensorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LicensorServiceTest extends TestCase
{
    use RefreshDatabase;

    private LicensorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LicensorService;
    }

    public function test_for_country_returns_entity_by_country_code(): void
    {
        $entity = LicensorEntity::factory()->create(['country_code' => 'kz']);

        $result = $this->service->forCountry('kz');

        $this->assertNotNull($result);
        $this->assertEquals($entity->id, $result->id);
    }

    public function test_for_country_returns_override_when_id_given(): void
    {
        LicensorEntity::factory()->create(['country_code' => 'kz']);
        $override = LicensorEntity::factory()->create(['country_code' => 'uz']);

        $result = $this->service->forCountry('kz', $override->id);

        $this->assertEquals($override->id, $result?->id);
    }

    public function test_for_country_returns_null_when_not_found(): void
    {
        $result = $this->service->forCountry('xx');

        $this->assertNull($result);
    }

    public function test_primary_account_for_currency_returns_correct(): void
    {
        $entity = LicensorEntity::factory()->create(['country_code' => 'kz']);
        LicensorBankAccount::factory()->create([
            'licensor_id' => $entity->id,
            'currency' => 'KZT',
            'is_primary' => true,
        ]);
        LicensorBankAccount::factory()->create([
            'licensor_id' => $entity->id,
            'currency' => 'KZT',
            'is_primary' => false,
        ]);

        $account = $this->service->primaryAccountForCurrency($entity, 'KZT');

        $this->assertNotNull($account);
        $this->assertTrue($account->is_primary);
        $this->assertEquals('KZT', $account->currency);
    }

    public function test_primary_account_returns_null_when_no_primary(): void
    {
        $entity = LicensorEntity::factory()->create(['country_code' => 'uz']);
        LicensorBankAccount::factory()->create([
            'licensor_id' => $entity->id,
            'currency' => 'UZS',
            'is_primary' => false,
        ]);

        $account = $this->service->primaryAccountForCurrency($entity, 'UZS');

        $this->assertNull($account);
    }
}
