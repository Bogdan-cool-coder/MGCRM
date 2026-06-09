<?php

namespace Tests\Unit\MacroData;

use App\Services\MacroData\ConfigNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConfigNormalizer.
 *
 * Tests use a hand-crafted canonical map (no real DB / cache) injected via
 * a spy subclass, so the suite runs completely offline.
 */
class ConfigNormalizerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Minimal stub map that mirrors real model structure
    // -------------------------------------------------------------------------

    /**
     * Returns a minimal canonical map for testing purposes.
     *
     * Mirrors a subset of the real MacroData model tree:
     *   EstateDeals
     *     → estateSells  (EstateSells)
     *         → estateHouses  (EstateHouses)
     *             → geoCityComplex  (GeoCityComplex)
     *     → contactsBuy  (Contacts)
     *   Finances
     *     → estateDeals  (EstateDeals)
     *     → estateSells  (EstateSells)
     */
    private function stubMap(): array
    {
        return [
            'models' => [
                // snake  → PascalCase
                'estate_deals'     => 'EstateDeals',
                'estate_sells'     => 'EstateSells',
                'estate_houses'    => 'EstateHouses',
                'geo_city_complex' => 'GeoCityComplex',
                'contacts'         => 'Contacts',
                'finances'         => 'Finances',
                // PascalCase identity
                'EstateDeals'      => 'EstateDeals',
                'EstateSells'      => 'EstateSells',
                'EstateHouses'     => 'EstateHouses',
                'GeoCityComplex'   => 'GeoCityComplex',
                'Contacts'         => 'Contacts',
                'Finances'         => 'Finances',
            ],
            'relations' => [
                'EstateDeals' => [
                    'estateSells'   => 'estateSells',
                    'estate_sells'  => 'estateSells',
                    'contactsBuy'   => 'contactsBuy',
                    'contacts_buy'  => 'contactsBuy',
                ],
                'EstateSells' => [
                    'estateHouses'  => 'estateHouses',
                    'estate_houses' => 'estateHouses',
                ],
                'EstateHouses' => [
                    'geoCityComplex'   => 'geoCityComplex',
                    'geo_city_complex' => 'geoCityComplex',
                ],
                'GeoCityComplex' => [],
                'Contacts'       => [],
                'Finances' => [
                    'estateDeals'   => 'estateDeals',
                    'estate_deals'  => 'estateDeals',
                    'estateSells'   => 'estateSells',
                    'estate_sells'  => 'estateSells',
                ],
            ],
            'related' => [
                'EstateDeals' => [
                    'estateSells' => 'EstateSells',
                    'contactsBuy' => 'Contacts',
                ],
                'EstateSells' => [
                    'estateHouses' => 'EstateHouses',
                ],
                'EstateHouses' => [
                    'geoCityComplex' => 'GeoCityComplex',
                ],
                'GeoCityComplex' => [],
                'Contacts'       => [],
                'Finances' => [
                    'estateDeals' => 'EstateDeals',
                    'estateSells' => 'EstateSells',
                ],
            ],
        ];
    }

    /**
     * Build a ConfigNormalizer whose getCanonicalMap() returns our stub.
     */
    private function normalizer(): ConfigNormalizer
    {
        $stub = new class($this->stubMap()) extends ConfigNormalizer {
            public function __construct(private readonly array $stubbedMap)
            {
                // Do not call parent — avoids Cache / filesystem dependency
            }

            public function getCanonicalMap(): array
            {
                return $this->stubbedMap;
            }
        };

        return $stub;
    }

    // -------------------------------------------------------------------------
    // primary_model normalization
    // -------------------------------------------------------------------------

    public function test_snake_case_primary_model_is_converted_to_pascal_case(): void
    {
        $config = ['primary_model' => 'estate_deals', 'columns' => []];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame('EstateDeals', $result['config']['primary_model']);
        $this->assertNotEmpty($result['changes']);
        $this->assertSame('primary_model', $result['changes'][0]['path']);
        $this->assertSame('estate_deals', $result['changes'][0]['from']);
        $this->assertSame('EstateDeals', $result['changes'][0]['to']);
    }

    public function test_pascal_case_primary_model_is_unchanged(): void
    {
        $config = ['primary_model' => 'EstateDeals', 'columns' => []];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame('EstateDeals', $result['config']['primary_model']);
        $this->assertEmpty($result['changes']);
    }

    public function test_unknown_model_returns_error_with_suggestion(): void
    {
        $config = ['primary_model' => 'estate_deal', 'columns' => []]; // singular typo

        $result = $this->normalizer()->normalize($config);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame('primary_model', $result['errors'][0]['path']);
        // Should suggest EstateDeals (close match)
        $this->assertStringContainsString('estate_deals', strtolower($result['errors'][0]['reason']));
    }

    // -------------------------------------------------------------------------
    // columns[i].field  — relation chain normalization
    // -------------------------------------------------------------------------

    public function test_snake_case_relation_chain_in_column_field_is_converted(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'estate_sells.estate_houses.geo_city_complex.geo_complex_name'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            'estateSells.estateHouses.geoCityComplex.geo_complex_name',
            $result['config']['columns'][0]['field'],
        );
    }

    public function test_camel_case_relation_chain_passes_through_unchanged(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'estateSells.estateHouses.geoCityComplex.geo_complex_name'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            'estateSells.estateHouses.geoCityComplex.geo_complex_name',
            $result['config']['columns'][0]['field'],
        );
        // No changes should have been recorded
        $this->assertEmpty($result['changes']);
    }

    public function test_direct_column_field_without_dot_is_not_modified(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'deal_sum'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame('deal_sum', $result['config']['columns'][0]['field']);
        $this->assertEmpty($result['changes']);
    }

    public function test_unknown_relation_in_column_field_returns_error(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'nonexistent_relation.some_field'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame('columns.0.field', $result['errors'][0]['path']);
        $this->assertStringContainsString('nonexistent_relation', $result['errors'][0]['reason']);
    }

    // -------------------------------------------------------------------------
    // extra_relations
    // -------------------------------------------------------------------------

    public function test_extra_relations_snake_case_is_converted(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field'           => 'deal_sum',
                    'extra_relations' => ['estate_sells.estate_houses'],
                ],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            'estateSells.estateHouses',
            $result['config']['columns'][0]['extra_relations'][0],
        );
    }

    public function test_extra_relations_already_canonical_is_unchanged(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                [
                    'field'           => 'deal_sum',
                    'extra_relations' => ['estateSells.estateHouses'],
                ],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            'estateSells.estateHouses',
            $result['config']['columns'][0]['extra_relations'][0],
        );
        $this->assertEmpty($result['changes']);
    }

    // -------------------------------------------------------------------------
    // totals
    // -------------------------------------------------------------------------

    public function test_totals_relation_path_snake_is_converted(): void
    {
        $config = [
            'primary_model' => 'Finances',
            'columns'       => [],
            'totals'        => ['estate_deals.deal_sum'],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertContains('estateDeals.deal_sum', $result['config']['totals']);
    }

    public function test_totals_associative_key_is_normalized(): void
    {
        $config = [
            'primary_model' => 'Finances',
            'columns'       => [],
            'totals'        => ['estate_deals.deal_sum' => 'sum'],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('estateDeals.deal_sum', $result['config']['totals']);
        $this->assertSame('sum', $result['config']['totals']['estateDeals.deal_sum']);
    }

    // -------------------------------------------------------------------------
    // where[i].relation  (whereHas)
    // -------------------------------------------------------------------------

    public function test_where_has_relation_snake_is_converted(): void
    {
        $config = [
            'primary_model' => 'Finances',
            'columns'       => [],
            'where' => [
                ['type' => 'whereHas', 'relation' => 'estate_deals', 'closure' => 'function ($q) { }'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame('estateDeals', $result['config']['where'][0]['relation']);
    }

    public function test_where_non_has_condition_is_untouched(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns'       => [],
            'where' => [
                ['type' => 'whereNotNull', 'field' => 'deal_date'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame('deal_date', $result['config']['where'][0]['field']);
    }

    // -------------------------------------------------------------------------
    // filters[i].field
    // -------------------------------------------------------------------------

    public function test_filters_field_snake_chain_is_converted(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns'       => [],
            'filters' => [
                ['field' => 'estate_sells.estate_houses.geo_city_complex.geo_complex_name', 'type' => 'select'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            'estateSells.estateHouses.geoCityComplex.geo_complex_name',
            $result['config']['filters'][0]['field'],
        );
    }

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    public function test_already_canonical_config_returns_no_changes(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'deal_date', 'type' => 'date'],
                ['field' => 'estateSells.estateHouses.geoCityComplex.geo_complex_name', 'type' => 'text'],
                ['field' => 'contactsBuy.contacts_buy_name', 'type' => 'text'],
            ],
            'totals' => ['deal_sum', 'finances_income'],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['changes'], 'No changes expected for already-canonical config');
    }

    // -------------------------------------------------------------------------
    // Mixed path (some segments snake, some camel)
    // -------------------------------------------------------------------------

    public function test_mixed_snake_camel_path_is_fully_normalized(): void
    {
        // "estate_sells.estateHouses.geo_city_complex.geo_complex_name"
        //  snake          camel         snake             leaf field
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'estate_sells.estateHouses.geo_city_complex.geo_complex_name'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertTrue($result['ok']);
        $this->assertSame(
            'estateSells.estateHouses.geoCityComplex.geo_complex_name',
            $result['config']['columns'][0]['field'],
        );
    }

    // -------------------------------------------------------------------------
    // Error details
    // -------------------------------------------------------------------------

    public function test_unknown_relation_error_contains_model_name(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'badRelation.some_field'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('EstateDeals', $result['errors'][0]['reason']);
    }

    public function test_multiple_errors_are_all_collected(): void
    {
        $config = [
            'primary_model' => 'EstateDeals',
            'columns' => [
                ['field' => 'bad_one.some_field'],
                ['field' => 'bad_two.other_field'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        $this->assertFalse($result['ok']);
        $this->assertCount(2, $result['errors']);
        $this->assertSame('columns.0.field', $result['errors'][0]['path']);
        $this->assertSame('columns.1.field', $result['errors'][1]['path']);
    }

    // -------------------------------------------------------------------------
    // Cross-model chain (Finances → EstateDeals → EstateSells)
    // -------------------------------------------------------------------------

    public function test_multi_hop_cross_model_chain_is_normalized(): void
    {
        // Finances → estateDeals → estateSells → estateHouses → geo_complex_name
        $config = [
            'primary_model' => 'Finances',
            'columns' => [
                ['field' => 'estate_deals.estate_sells.estate_houses.geo_city_complex.geo_complex_name'],
            ],
        ];

        $result = $this->normalizer()->normalize($config);

        // estateDeals is defined on Finances; but estateDeals → estateSells is
        // NOT defined in our stub map's related['EstateDeals'] for the hop from
        // EstateDeals (accessed via Finances). Our stub has EstateDeals.estateSells
        // but related['EstateDeals']['estateSells'] = 'EstateSells' so it should work.
        $this->assertTrue($result['ok']);
        $this->assertSame(
            'estateDeals.estateSells.estateHouses.geoCityComplex.geo_complex_name',
            $result['config']['columns'][0]['field'],
        );
    }

    // Note: the legacy top-level `chart` key was removed from the report-config
    // contract along with the whole dashboard-on-report visualisation. A report
    // is now a dry table; ConfigNormalizer no longer normalises `chart` and
    // ReportTool no longer special-cases it.
}
