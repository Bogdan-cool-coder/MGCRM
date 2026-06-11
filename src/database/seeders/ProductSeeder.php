<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductPrice;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING: idempotent seeder for demo products with plans and prices.
 * Prices in KZT, RUB, USD. Amounts are integer kopecks.
 * Re-running this seeder does NOT create duplicates.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $aiGroup = ProductGroup::where('name', 'MACRO AI')->first();
        $servicesGroup = ProductGroup::where('name', 'Сервисы')->first();
        $integrationsGroup = ProductGroup::where('name', 'Интеграции')->first();

        $this->seedProduct(
            code: 'macro_ai_core',
            name: 'MACRO AI Core',
            description: 'Базовая платформа искусственного интеллекта для автоматизации бизнес-процессов',
            groupId: $aiGroup?->id,
            pricingType: 'tiered',
            plans: [
                [
                    'code' => 'start_annual',
                    'name' => 'Start',
                    'unit' => 'year',
                    'sort_order' => 1,
                    'prices' => [
                        ['currency_code' => 'KZT', 'amount' => 15_000_000_00], // 15,000,000 KZT
                        ['currency_code' => 'RUB', 'amount' => 3_500_000_00],  // 3,500,000 RUB
                        ['currency_code' => 'USD', 'amount' => 8_000_00],      // 8,000 USD
                    ],
                ],
                [
                    'code' => 'business_annual',
                    'name' => 'Business',
                    'unit' => 'year',
                    'sort_order' => 2,
                    'prices' => [
                        ['currency_code' => 'KZT', 'amount' => 35_000_000_00],
                        ['currency_code' => 'RUB', 'amount' => 8_000_000_00],
                        ['currency_code' => 'USD', 'amount' => 18_000_00],
                    ],
                ],
                [
                    'code' => 'enterprise_annual',
                    'name' => 'Enterprise',
                    'unit' => 'year',
                    'sort_order' => 3,
                    'prices' => [
                        ['currency_code' => 'KZT', 'amount' => 80_000_000_00],
                        ['currency_code' => 'RUB', 'amount' => 18_000_000_00],
                        ['currency_code' => 'USD', 'amount' => 40_000_00],
                    ],
                ],
            ],
        );

        $this->seedProduct(
            code: 'macro_crm',
            name: 'MACRO CRM',
            description: 'CRM-система для управления продажами и клиентской базой',
            groupId: $servicesGroup?->id,
            pricingType: 'fixed',
            plans: [],
            basePrices: [
                ['currency_code' => 'KZT', 'amount' => 5_000_000_00],
                ['currency_code' => 'RUB', 'amount' => 1_200_000_00],
                ['currency_code' => 'USD', 'amount' => 2_800_00],
            ],
        );

        $this->seedProduct(
            code: 'macro_integration_kit',
            name: 'MACRO Integration Kit',
            description: 'Набор готовых интеграций с популярными сервисами',
            groupId: $integrationsGroup?->id,
            pricingType: 'package',
            plans: [
                [
                    'code' => 'basic_pkg',
                    'name' => 'Basic (5 integrations)',
                    'unit' => 'one_time',
                    'sort_order' => 1,
                    'prices' => [
                        ['currency_code' => 'KZT', 'amount' => 800_000_00],
                        ['currency_code' => 'RUB', 'amount' => 180_000_00],
                        ['currency_code' => 'USD', 'amount' => 400_00],
                    ],
                ],
                [
                    'code' => 'pro_pkg',
                    'name' => 'Pro (unlimited)',
                    'unit' => 'one_time',
                    'sort_order' => 2,
                    'prices' => [
                        ['currency_code' => 'KZT', 'amount' => 2_500_000_00],
                        ['currency_code' => 'RUB', 'amount' => 560_000_00],
                        ['currency_code' => 'USD', 'amount' => 1_250_00],
                    ],
                ],
            ],
        );

        $this->seedProduct(
            code: 'macro_ai_assistant',
            name: 'MACRO AI Assistant',
            description: 'Голосовой и текстовый AI-ассистент для поддержки клиентов',
            groupId: $aiGroup?->id,
            pricingType: 'per_minute',
            plans: [
                [
                    'code' => 'per_min',
                    'name' => 'Per Minute',
                    'unit' => 'minute',
                    'sort_order' => 1,
                    'prices' => [
                        ['currency_code' => 'KZT', 'amount' => 15_00], // 15 KZT per minute
                        ['currency_code' => 'RUB', 'amount' => 3_50],  // 3.50 RUB — stored as 350 kopecks
                        ['currency_code' => 'USD', 'amount' => 1],     // $0.01
                    ],
                ],
            ],
        );

        $this->seedProduct(
            code: 'implementation_standard',
            name: 'Внедрение (Стандарт)',
            description: 'Услуги по стандартному внедрению продуктов MACRO',
            groupId: $servicesGroup?->id,
            pricingType: 'fixed',
            plans: [],
            basePrices: [
                ['currency_code' => 'KZT', 'amount' => 3_000_000_00],
                ['currency_code' => 'RUB', 'amount' => 680_000_00],
                ['currency_code' => 'USD', 'amount' => 1_500_00],
            ],
        );
    }

    private function seedProduct(
        string $code,
        string $name,
        ?string $description,
        ?int $groupId,
        string $pricingType,
        array $plans,
        array $basePrices = [],
    ): void {
        $product = Product::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'description' => $description,
                'group_id' => $groupId,
                'pricing_type' => $pricingType,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        // Collect plan codes defined in this seed call so we can remove stale ones.
        $seededPlanCodes = array_column($plans, 'code');

        // Remove stale plans that exist in DB but are no longer in the seed definition.
        // This handles migrations from old seed versions (e.g. fixed products that once
        // had a plan but should now use base prices instead).
        $product->plans()
            ->when(
                count($seededPlanCodes) > 0,
                fn ($q) => $q->whereNotIn('code', $seededPlanCodes),
                fn ($q) => $q, // no seeded plans → ALL existing plans are stale
            )
            ->get()
            ->each(function ($stalePlan): void {
                // Cascade prices first (DB cascade handles it too, but be explicit).
                ProductPrice::where('plan_id', $stalePlan->id)->delete();
                $stalePlan->delete();
            });

        // Upsert plans and their prices.
        foreach ($plans as $planData) {
            $planPrices = $planData['prices'] ?? [];
            unset($planData['prices']);

            $plan = $product->plans()->firstOrCreate(
                ['code' => $planData['code']],
                array_merge($planData, ['product_id' => $product->id, 'is_active' => true]),
            );

            foreach ($planPrices as $priceData) {
                ProductPrice::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'plan_id' => $plan->id,
                        'currency_code' => $priceData['currency_code'],
                    ],
                    ['amount' => $priceData['amount']],
                );
            }
        }

        // Upsert base prices (no plan).
        foreach ($basePrices as $priceData) {
            ProductPrice::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'plan_id' => null,
                    'currency_code' => $priceData['currency_code'],
                ],
                ['amount' => $priceData['amount']],
            );
        }
    }
}
