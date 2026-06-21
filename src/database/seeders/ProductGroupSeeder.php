<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\ProductGroup;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING: idempotent seeder for product groups.
 * 8 product families matching the MACRO Global 2026 official price list.
 * Repeating this seeder does NOT create duplicates.
 */
class ProductGroupSeeder extends Seeder
{
    /** @var list<array{name: string, description: string, sort_order: int}> */
    private static array $groups = [
        [
            'name' => 'MacroSales CRM',
            'description' => 'Отраслевая CRM полного цикла: канбан сделок, статусы объектов, договоры, платежи, интеграционная шина',
            'sort_order' => 1,
        ],
        [
            'name' => 'MACRO AI',
            'description' => 'Виртуальные AI-ассистенты на входящие обращения: WhatsApp, Telegram, Web, Facebook, Instagram, Voice, рассылки, аналитика',
            'sort_order' => 2,
        ],
        [
            'name' => 'MacroCatalog',
            'description' => 'Виджет-каталог объектов застройщика, корпоративный сайт ЖК, личный кабинет покупателя, Telegram Mini App',
            'sort_order' => 3,
        ],
        [
            'name' => 'MacroBroker',
            'description' => 'Портал для брокеров и агентов застройщика',
            'sort_order' => 4,
        ],
        [
            'name' => 'Macro3D Tours',
            'description' => 'AI-генерация 3D-туров объектов недвижимости; пакетная тарификация MacroTour360',
            'sort_order' => 5,
        ],
        [
            'name' => 'MacroERP',
            'description' => 'Управление строительными проектами: финансы, логистика, ресурсы, производственный учёт',
            'sort_order' => 6,
        ],
        [
            'name' => 'FlowFix',
            'description' => 'SaaS финансового менеджмента: cash flow, P&L, управленческий учёт для застройщика',
            'sort_order' => 7,
        ],
        [
            'name' => 'MACRO Services',
            'description' => 'VIP-сервис: предпроектные исследования (ППИ), AEO/GEO AI Optimizer, индивидуальные проекты',
            'sort_order' => 8,
        ],
    ];

    public function run(): void
    {
        foreach (self::$groups as $data) {
            ProductGroup::firstOrCreate(
                ['name' => $data['name']],
                array_merge($data, ['is_active' => true]),
            );
        }
    }
}
