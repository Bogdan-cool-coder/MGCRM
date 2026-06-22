<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGroup;
use App\Domain\Catalog\Models\ProductPlan;
use App\Domain\Catalog\Models\ProductPrice;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING: idempotent seeder for MACRO Global 2026 official price-list catalog.
 * Source of truth — replaces the 5 demo products that existed before.
 *
 * Amounts are stored in MINOR UNITS (kopecks / tiyn / fils / cents / kopecks)
 * as per ARCHITECTURE.md §3 (integer, ×100 of the major unit).
 *
 * Currencies: KZT (tenge), UZS (sum), AED (dirham), USD (dollar), RUB (ruble).
 *
 * Pricing types:
 *   fixed      — flat annual subscription, base prices on product (no plan)
 *   fixed+plan — setup/one-time fee, plan unit=one_time
 *   per_minute — Voice AI Broker: per-minute + package bundles
 *   package    — Macro3D Tours: named package plans (USD only)
 *   custom     — ППИ / AEO: price-on-request, no price rows
 *
 * Stale plans (exist in DB but removed from seed) are deleted before upserting.
 * Re-running this seeder does NOT create duplicates.
 *
 * @see DemoDealsSeeder — references macro_sales_crm, touchlink, whatsapp_ai_broker
 */
class ProductSeeder extends Seeder
{
    // ── Group 1: MacroSales CRM ────────────────────────────────────────────────

    /** MacroSales CRM annual subscription */
    private const P_MACRO_SALES_CRM = [
        'code' => 'macro_sales_crm',
        'name' => 'MacroSales CRM',
        'description' => 'Отраслевая CRM полного цикла для застройщика: канбан сделок, статусы квартир, договоры, платежи, аналитика.',
        'group' => 'MacroSales CRM',
        'pricing_type' => 'fixed',
        'sort_order' => 1,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 250_000_000],  // 2 500 000 × 100
            ['currency_code' => 'UZS', 'amount' => 5_000_000_000], // 50 000 000 × 100
            ['currency_code' => 'AED', 'amount' => 6_000_000],     // 60 000 × 100
            ['currency_code' => 'USD', 'amount' => 450_000],       // 4 500 × 100
            ['currency_code' => 'RUB', 'amount' => 36_000_000],    // 360 000 × 100
        ],
    ];

    /** MacroSales CRM — setup / implementation (one-time) */
    private const P_SETUP_MACRO_SALES_CRM = [
        'code' => 'setup_macro_sales_crm',
        'name' => 'Настройка MacroSales CRM',
        'description' => 'Внедрение MacroSales CRM «под ключ»: настройка, обучение, запуск.',
        'group' => 'MacroSales CRM',
        'pricing_type' => 'fixed',
        'sort_order' => 2,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 50_000_000],
                    ['currency_code' => 'UZS', 'amount' => 1_000_000_000],
                    ['currency_code' => 'AED', 'amount' => 1_000_000],
                    ['currency_code' => 'USD', 'amount' => 90_000],
                    ['currency_code' => 'RUB', 'amount' => 8_000_000],
                ],
            ],
        ],
    ];

    /** TouchLink — integration bus (ESB + social channels) */
    private const P_TOUCHLINK = [
        'code' => 'touchlink',
        'name' => 'TouchLink',
        'description' => 'Интеграционная шина ESB: соцсети, мессенджеры, сторонние сервисы.',
        'group' => 'MacroSales CRM',
        'pricing_type' => 'fixed',
        'sort_order' => 3,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 30_000_000],
            ['currency_code' => 'UZS', 'amount' => 720_000_000],
            ['currency_code' => 'AED', 'amount' => 222_000],
            ['currency_code' => 'USD', 'amount' => 60_000],
            ['currency_code' => 'RUB', 'amount' => 4_800_000],
        ],
    ];

    /** TouchLink — setup */
    private const P_SETUP_TOUCHLINK = [
        'code' => 'setup_touchlink',
        'name' => 'Настройка TouchLink',
        'description' => 'Внедрение TouchLink: подключение каналов и настройка правил маршрутизации.',
        'group' => 'MacroSales CRM',
        'pricing_type' => 'fixed',
        'sort_order' => 4,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 10_000_000],
                    ['currency_code' => 'UZS', 'amount' => 240_000_000],
                    ['currency_code' => 'AED', 'amount' => 74_000],
                    ['currency_code' => 'USD', 'amount' => 20_000],
                    ['currency_code' => 'RUB', 'amount' => 1_600_000],
                ],
            ],
        ],
    ];

    // ── Group 2: MACRO AI ──────────────────────────────────────────────────────

    /** AI mailings — omnichannel inbox + campaigns */
    private const P_AI_MAILINGS = [
        'code' => 'ai_mailings',
        'name' => 'AI mailings',
        'description' => 'Омниканальный inbox + AI-рассылки по базе клиентов через все каналы.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 5,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 30_000_000],
            ['currency_code' => 'UZS', 'amount' => 720_000_000],
            ['currency_code' => 'AED', 'amount' => 222_000],
            ['currency_code' => 'USD', 'amount' => 60_000],
            ['currency_code' => 'RUB', 'amount' => 4_800_000],
        ],
    ];

    /** AI mailings — setup */
    private const P_SETUP_AI_MAILINGS = [
        'code' => 'setup_ai_mailings',
        'name' => 'Настройка AI mailings',
        'description' => 'Настройка омниканального inbox и шаблонов рассылок.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 6,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 10_000_000],
                    ['currency_code' => 'UZS', 'amount' => 240_000_000],
                    ['currency_code' => 'AED', 'amount' => 74_000],
                    ['currency_code' => 'USD', 'amount' => 20_000],
                    ['currency_code' => 'RUB', 'amount' => 1_600_000],
                ],
            ],
        ],
    ];

    /** WhatsApp AI Broker */
    private const P_WHATSAPP_AI_BROKER = [
        'code' => 'whatsapp_ai_broker',
        'name' => 'WhatsApp AI Broker',
        'description' => 'AI-брокер в WhatsApp: квалификация обращений, ответы на вопросы, запись на просмотр.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 7,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 180_000_000],
            ['currency_code' => 'UZS', 'amount' => 3_000_000_000],
            ['currency_code' => 'AED', 'amount' => 3_120_000],
            ['currency_code' => 'USD', 'amount' => 480_000],
            ['currency_code' => 'RUB', 'amount' => 28_000_000],
        ],
    ];

    /** WhatsApp AI Broker — setup */
    private const P_SETUP_WHATSAPP_AI_BROKER = [
        'code' => 'setup_whatsapp_ai_broker',
        'name' => 'Настройка WhatsApp AI Broker',
        'description' => 'Подключение и настройка AI-брокера в WhatsApp.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 8,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 20_000_000],
                    ['currency_code' => 'UZS', 'amount' => 300_000_000],
                    ['currency_code' => 'AED', 'amount' => 350_000],
                    ['currency_code' => 'USD', 'amount' => 80_000],
                    ['currency_code' => 'RUB', 'amount' => 6_000_000],
                ],
            ],
        ],
    ];

    /** Telegram AI Broker */
    private const P_TELEGRAM_AI_BROKER = [
        'code' => 'telegram_ai_broker',
        'name' => 'Telegram AI Broker',
        'description' => 'AI-брокер в Telegram: автоответы, квалификация, запись на просмотр.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 9,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 120_000_000],
            ['currency_code' => 'UZS', 'amount' => 4_800_000_000],
            ['currency_code' => 'AED', 'amount' => 1_080_000],
            ['currency_code' => 'USD', 'amount' => 300_000],
            ['currency_code' => 'RUB', 'amount' => 24_000_000],
        ],
    ];

    /** Telegram AI Broker — setup */
    private const P_SETUP_TELEGRAM_AI_BROKER = [
        'code' => 'setup_telegram_ai_broker',
        'name' => 'Настройка Telegram AI Broker',
        'description' => 'Подключение и настройка AI-брокера в Telegram.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 10,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 20_000_000],
                    ['currency_code' => 'UZS', 'amount' => 300_000_000],
                    ['currency_code' => 'AED', 'amount' => 200_000],
                    ['currency_code' => 'USD', 'amount' => 55_000],
                    ['currency_code' => 'RUB', 'amount' => 6_000_000],
                ],
            ],
        ],
    ];

    /** WEB AI Broker */
    private const P_WEB_AI_BROKER = [
        'code' => 'web_ai_broker',
        'name' => 'WEB AI Broker',
        'description' => 'AI-брокер на сайте ЖК: чат-виджет, автоответы, квалификация лидов.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 11,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 80_000_000],
            ['currency_code' => 'UZS', 'amount' => 2_400_000_000],
            ['currency_code' => 'AED', 'amount' => 1_200_000],
            ['currency_code' => 'USD', 'amount' => 280_000],
            ['currency_code' => 'RUB', 'amount' => 18_000_000],
        ],
    ];

    /** WEB AI Broker — setup */
    private const P_SETUP_WEB_AI_BROKER = [
        'code' => 'setup_web_ai_broker',
        'name' => 'Настройка WEB AI Broker',
        'description' => 'Внедрение и настройка AI-чат-виджета на сайт ЖК.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 12,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 20_000_000],
                    ['currency_code' => 'UZS', 'amount' => 300_000_000],
                    ['currency_code' => 'AED', 'amount' => 200_000],
                    ['currency_code' => 'USD', 'amount' => 55_000],
                    ['currency_code' => 'RUB', 'amount' => 6_000_000],
                ],
            ],
        ],
    ];

    /** Facebook AI Broker */
    private const P_FACEBOOK_AI_BROKER = [
        'code' => 'facebook_ai_broker',
        'name' => 'FaceBook AI Broker',
        'description' => 'AI-брокер в Facebook Messenger: квалификация обращений и автоответы.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 13,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 180_000_000],
            ['currency_code' => 'UZS', 'amount' => 3_000_000_000],
            ['currency_code' => 'AED', 'amount' => 3_120_000],
            ['currency_code' => 'USD', 'amount' => 480_000],
            ['currency_code' => 'RUB', 'amount' => 28_000_000],
        ],
    ];

    /** Facebook AI Broker — setup */
    private const P_SETUP_FACEBOOK_AI_BROKER = [
        'code' => 'setup_facebook_ai_broker',
        'name' => 'Настройка FaceBook AI Broker',
        'description' => 'Подключение и настройка AI-брокера в Facebook Messenger.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 14,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 20_000_000],
                    ['currency_code' => 'UZS', 'amount' => 300_000_000],
                    ['currency_code' => 'AED', 'amount' => 350_000],
                    ['currency_code' => 'USD', 'amount' => 80_000],
                    ['currency_code' => 'RUB', 'amount' => 6_000_000],
                ],
            ],
        ],
    ];

    /** Instagram AI Broker */
    private const P_INSTAGRAM_AI_BROKER = [
        'code' => 'instagram_ai_broker',
        'name' => 'Instagram AI Broker',
        'description' => 'AI-брокер в Instagram Direct: автоответы, квалификация входящих обращений.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 15,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 180_000_000],
            ['currency_code' => 'UZS', 'amount' => 3_000_000_000],
            ['currency_code' => 'AED', 'amount' => 3_120_000],
            ['currency_code' => 'USD', 'amount' => 480_000],
            ['currency_code' => 'RUB', 'amount' => 28_000_000],
        ],
    ];

    /** Instagram AI Broker — setup */
    private const P_SETUP_INSTAGRAM_AI_BROKER = [
        'code' => 'setup_instagram_ai_broker',
        'name' => 'Настройка Instagram AI Broker',
        'description' => 'Подключение и настройка AI-брокера в Instagram Direct.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 16,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 20_000_000],
                    ['currency_code' => 'UZS', 'amount' => 300_000_000],
                    ['currency_code' => 'AED', 'amount' => 350_000],
                    ['currency_code' => 'USD', 'amount' => 80_000],
                    ['currency_code' => 'RUB', 'amount' => 6_000_000],
                ],
            ],
        ],
    ];

    /**
     * Voice AI Broker Consultant — per-minute licensing with package bundles.
     * Plans: per_min (minute), start_100 (package 100 min), basic_250 (250 min), pro_500 (500 min).
     */
    private const P_VOICE_AI_BROKER = [
        'code' => 'voice_ai_broker',
        'name' => 'Voice AI Broker Consultant',
        'description' => 'Голосовой AI-брокер-консультант: поминутная лицензия или пакеты минут. Квалификация и ответы на звонки.',
        'group' => 'MACRO AI',
        'pricing_type' => 'per_minute',
        'sort_order' => 17,
        'plans' => [
            [
                'code' => 'per_min',
                'name' => 'Минутная (за 1 мин)',
                'unit' => 'minute',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 10_000],  // 100 × 100
                    ['currency_code' => 'UZS', 'amount' => 230_000], // 2 300 × 100
                    ['currency_code' => 'AED', 'amount' => 100],     // 1 × 100
                    ['currency_code' => 'USD', 'amount' => 0],       // $0 — listed as 0 in price list
                    ['currency_code' => 'RUB', 'amount' => 1_500],   // 15 × 100
                ],
            ],
            [
                'code' => 'start_100',
                'name' => 'Start 100 мин',
                'unit' => 'package',
                'sort_order' => 2,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 900_000],
                    ['currency_code' => 'UZS', 'amount' => 20_000_000],
                    ['currency_code' => 'AED', 'amount' => 9_000],
                    ['currency_code' => 'USD', 'amount' => 1_900],
                    ['currency_code' => 'RUB', 'amount' => 135_000],
                ],
            ],
            [
                'code' => 'basic_250',
                'name' => 'Basic 250 мин',
                'unit' => 'package',
                'sort_order' => 3,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 2_100_000],
                    ['currency_code' => 'UZS', 'amount' => 49_000_000],
                    ['currency_code' => 'AED', 'amount' => 21_000],
                    ['currency_code' => 'USD', 'amount' => 4_200],
                    ['currency_code' => 'RUB', 'amount' => 320_000],
                ],
            ],
            [
                'code' => 'pro_500',
                'name' => 'PRO 500 мин',
                'unit' => 'package',
                'sort_order' => 4,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 4_000_000],
                    ['currency_code' => 'UZS', 'amount' => 92_000_000],
                    ['currency_code' => 'AED', 'amount' => 40_000],
                    ['currency_code' => 'USD', 'amount' => 8_000],
                    ['currency_code' => 'RUB', 'amount' => 600_000],
                ],
            ],
        ],
    ];

    /** Voice AI Broker — setup */
    private const P_SETUP_VOICE_AI_BROKER = [
        'code' => 'setup_voice_ai_broker',
        'name' => 'Настройка Voice AI Broker Consultant',
        'description' => 'Внедрение и настройка голосового AI-брокера-консультанта.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 18,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 60_000_000],
                    ['currency_code' => 'UZS', 'amount' => 1_000_000_000],
                    ['currency_code' => 'AED', 'amount' => 1_500_000],
                    ['currency_code' => 'USD', 'amount' => 200_000],
                    ['currency_code' => 'RUB', 'amount' => 15_000_000],
                ],
            ],
        ],
    ];

    /** Data Analytics AI */
    private const P_DATA_ANALYTICS_AI = [
        'code' => 'data_analytics_ai',
        'name' => 'Data Analytics AI',
        'description' => 'Data Warehouse + BI: AI-аналитика данных застройщика, интерактивные дашборды.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 19,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 150_000_000],
            ['currency_code' => 'UZS', 'amount' => 2_400_000_000],
            ['currency_code' => 'AED', 'amount' => 1_500_000],
            ['currency_code' => 'USD', 'amount' => 330_000],
            ['currency_code' => 'RUB', 'amount' => 25_000_000],
        ],
    ];

    /** Data Analytics AI — setup */
    private const P_SETUP_DATA_ANALYTICS_AI = [
        'code' => 'setup_data_analytics_ai',
        'name' => 'Настройка Data Analytics AI',
        'description' => 'Внедрение Data Warehouse + BI: подключение источников, настройка дашбордов.',
        'group' => 'MACRO AI',
        'pricing_type' => 'fixed',
        'sort_order' => 20,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 20_000_000],
                    ['currency_code' => 'UZS', 'amount' => 300_000_000],
                    ['currency_code' => 'AED', 'amount' => 350_000],
                    ['currency_code' => 'USD', 'amount' => 80_000],
                    ['currency_code' => 'RUB', 'amount' => 6_000_000],
                ],
            ],
        ],
    ];

    // ── Group 3: MacroCatalog ──────────────────────────────────────────────────

    /** MacroCatalog — property listing widget */
    private const P_MACRO_CATALOG = [
        'code' => 'macro_catalog',
        'name' => 'MacroCatalog',
        'description' => 'Виджет-каталог объектов застройщика: шахматка квартир, фильтры, планировки.',
        'group' => 'MacroCatalog',
        'pricing_type' => 'fixed',
        'sort_order' => 21,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 60_000_000],
            ['currency_code' => 'UZS', 'amount' => 1_400_000_000],
            ['currency_code' => 'AED', 'amount' => 1_000_000],
            ['currency_code' => 'USD', 'amount' => 120_000],
            ['currency_code' => 'RUB', 'amount' => 12_000_000],
        ],
    ];

    /** MacroWeb — corporate website for residential complex */
    private const P_MACRO_WEB = [
        'code' => 'macro_web',
        'name' => 'MacroWeb',
        'description' => 'Корпоративный сайт жилого комплекса застройщика: лендинг, новости, галерея, форма заявки.',
        'group' => 'MacroCatalog',
        'pricing_type' => 'fixed',
        'sort_order' => 22,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 120_000_000],
            ['currency_code' => 'UZS', 'amount' => 2_000_000_000],
            ['currency_code' => 'AED', 'amount' => 2_000_000],
            ['currency_code' => 'USD', 'amount' => 200_000],
            ['currency_code' => 'RUB', 'amount' => 15_000_000],
        ],
    ];

    /** Customer Portal — buyer personal account */
    private const P_CUSTOMER_PORTAL = [
        'code' => 'customer_portal',
        'name' => 'Customer Portal',
        'description' => 'Личный кабинет покупателя: просмотр статуса договора, документов, оплат.',
        'group' => 'MacroCatalog',
        'pricing_type' => 'fixed',
        'sort_order' => 23,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 60_000_000],
            ['currency_code' => 'UZS', 'amount' => 1_400_000_000],
            ['currency_code' => 'AED', 'amount' => 1_000_000],
            ['currency_code' => 'USD', 'amount' => 120_000],
            ['currency_code' => 'RUB', 'amount' => 12_000_000],
        ],
    ];

    /** Telegram Mini App */
    private const P_TELEGRAM_MINI_APP = [
        'code' => 'telegram_mini_app',
        'name' => 'Telegram Mini App',
        'description' => 'Бронирование квартиры и просмотр каталога прямо внутри Telegram.',
        'group' => 'MacroCatalog',
        'pricing_type' => 'fixed',
        'sort_order' => 24,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 60_000_000],
            ['currency_code' => 'UZS', 'amount' => 1_400_000_000],
            ['currency_code' => 'AED', 'amount' => 1_000_000],
            ['currency_code' => 'USD', 'amount' => 120_000],
            ['currency_code' => 'RUB', 'amount' => 12_000_000],
        ],
    ];

    // ── Group 4: MacroBroker ───────────────────────────────────────────────────

    /** MacroBroker — broker/agent portal */
    private const P_MACRO_BROKER = [
        'code' => 'macro_broker',
        'name' => 'MacroBroker',
        'description' => 'Портал для брокеров и агентов: каталог объектов, бронирование, комиссии.',
        'group' => 'MacroBroker',
        'pricing_type' => 'fixed',
        'sort_order' => 25,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 60_000_000],
            ['currency_code' => 'UZS', 'amount' => 1_400_000_000],
            ['currency_code' => 'AED', 'amount' => 1_000_000],
            ['currency_code' => 'USD', 'amount' => 120_000],
            ['currency_code' => 'RUB', 'amount' => 12_000_000],
        ],
    ];

    // ── Group 5: Macro3D Tours ─────────────────────────────────────────────────

    /**
     * Macro3D Tours — AI-generated 3D tours, package pricing (MacroTour360 plans).
     * Prices are USD-only (listed in price list only as USD "Прайс MACRO" column).
     * Plans: pro_multi_set, multi_set, pro_set, plus_set, basic_set.
     */
    private const P_MACRO3D_TOURS = [
        'code' => 'macro3d_tours',
        'name' => 'Macro3D Tours',
        'description' => 'AI-генерация 3D-туров объектов недвижимости. Пакетная тарификация MacroTour360.',
        'group' => 'Macro3D Tours',
        'pricing_type' => 'package',
        'sort_order' => 26,
        'plans' => [
            [
                'code' => 'pro_multi_set',
                'name' => 'Pro Multi Set',
                'unit' => 'package',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'USD', 'amount' => 15_000], // $150 × 100
                ],
            ],
            [
                'code' => 'multi_set',
                'name' => 'Multi Set',
                'unit' => 'package',
                'sort_order' => 2,
                'prices' => [
                    ['currency_code' => 'USD', 'amount' => 11_300], // $113 × 100
                ],
            ],
            [
                'code' => 'pro_set',
                'name' => 'Pro Set',
                'unit' => 'package',
                'sort_order' => 3,
                'prices' => [
                    ['currency_code' => 'USD', 'amount' => 9_900], // $99 × 100
                ],
            ],
            [
                'code' => 'plus_set',
                'name' => 'Plus Set',
                'unit' => 'package',
                'sort_order' => 4,
                'prices' => [
                    ['currency_code' => 'USD', 'amount' => 7_500], // $75 × 100
                ],
            ],
            [
                'code' => 'basic_set',
                'name' => 'Basic Set',
                'unit' => 'package',
                'sort_order' => 5,
                'prices' => [
                    ['currency_code' => 'USD', 'amount' => 4_200], // $42 × 100
                ],
            ],
        ],
    ];

    // ── Group 6: MacroERP ──────────────────────────────────────────────────────

    /** MacroERP */
    private const P_MACRO_ERP = [
        'code' => 'macro_erp',
        'name' => 'MacroERP',
        'description' => 'Управление строительными проектами: финансы, логистика, ресурсы, производственный учёт.',
        'group' => 'MacroERP',
        'pricing_type' => 'fixed',
        'sort_order' => 27,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 1_000_000_000],
            ['currency_code' => 'UZS', 'amount' => 23_000_000_000],
            ['currency_code' => 'AED', 'amount' => 7_500_000],
            ['currency_code' => 'USD', 'amount' => 1_500_000],
            ['currency_code' => 'RUB', 'amount' => 150_000_000],
        ],
    ];

    /** MacroERP — setup */
    private const P_SETUP_MACRO_ERP = [
        'code' => 'setup_macro_erp',
        'name' => 'Настройка MacroERP',
        'description' => 'Внедрение MacroERP: конфигурация, интеграция с 1С, обучение команды.',
        'group' => 'MacroERP',
        'pricing_type' => 'fixed',
        'sort_order' => 28,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 300_000_000],
                    ['currency_code' => 'UZS', 'amount' => 8_000_000_000],
                    ['currency_code' => 'AED', 'amount' => 2_500_000],
                    ['currency_code' => 'USD', 'amount' => 500_000],
                    ['currency_code' => 'RUB', 'amount' => 50_000_000],
                ],
            ],
        ],
    ];

    // ── Group 7: FlowFix ───────────────────────────────────────────────────────

    /** FlowFix */
    private const P_FLOWFIX = [
        'code' => 'flowfix',
        'name' => 'FlowFix',
        'description' => 'SaaS финансового менеджмента для застройщика: cash flow, P&L, управленческий учёт.',
        'group' => 'FlowFix',
        'pricing_type' => 'fixed',
        'sort_order' => 29,
        'base_prices' => [
            ['currency_code' => 'KZT', 'amount' => 60_000_000],
            ['currency_code' => 'UZS', 'amount' => 1_400_000_000],
            ['currency_code' => 'AED', 'amount' => 1_000_000],
            ['currency_code' => 'USD', 'amount' => 120_000],
            ['currency_code' => 'RUB', 'amount' => 12_000_000],
        ],
    ];

    /** FlowFix — setup */
    private const P_SETUP_FLOWFIX = [
        'code' => 'setup_flowfix',
        'name' => 'Настройка FlowFix',
        'description' => 'Внедрение FlowFix: настройка статей, интеграция с банком, обучение.',
        'group' => 'FlowFix',
        'pricing_type' => 'fixed',
        'sort_order' => 30,
        'plans' => [
            [
                'code' => 'setup',
                'name' => 'Настройка (единоразово)',
                'unit' => 'one_time',
                'sort_order' => 1,
                'prices' => [
                    ['currency_code' => 'KZT', 'amount' => 30_000_000],
                    ['currency_code' => 'UZS', 'amount' => 600_000_000],
                    ['currency_code' => 'AED', 'amount' => 500_000],
                    ['currency_code' => 'USD', 'amount' => 60_000],
                    ['currency_code' => 'RUB', 'amount' => 6_000_000],
                ],
            ],
        ],
    ];

    // ── Group 8: MACRO Services ────────────────────────────────────────────────

    /**
     * ППИ — Предпроектное исследование (custom quote).
     * Price-on-request: no price rows seeded. Description notes the pricing policy.
     */
    private const P_PPI = [
        'code' => 'ppi',
        'name' => 'Предпроектное исследование (ППИ)',
        'description' => 'Аудит и предпроектный анализ бизнес-процессов застройщика. Стоимость оценивается индивидуально под проект — уточняйте у менеджера.',
        'group' => 'MACRO Services',
        'pricing_type' => 'custom',
        'sort_order' => 31,
        // No base_prices, no plans — price on request
    ];

    /**
     * AEO/GEO AI Optimizer — managed SEO/AI search optimization service (custom quote).
     * Price-on-request.
     */
    private const P_AEO_GEO_AI_OPTIMIZER = [
        'code' => 'aeo_geo_ai_optimizer',
        'name' => 'AEO/GEO AI Optimizer',
        'description' => 'Managed-сервис оптимизации присутствия в AI-поисковиках (AEO/GEO). Стоимость — под запрос.',
        'group' => 'MACRO Services',
        'pricing_type' => 'custom',
        'sort_order' => 32,
        // No prices — custom quote
    ];

    // ──────────────────────────────────────────────────────────────────────────
    // Master product list (order = sort_order in price-list)
    // ──────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private static function allProducts(): array
    {
        return [
            self::P_MACRO_SALES_CRM,
            self::P_SETUP_MACRO_SALES_CRM,
            self::P_TOUCHLINK,
            self::P_SETUP_TOUCHLINK,
            self::P_AI_MAILINGS,
            self::P_SETUP_AI_MAILINGS,
            self::P_WHATSAPP_AI_BROKER,
            self::P_SETUP_WHATSAPP_AI_BROKER,
            self::P_TELEGRAM_AI_BROKER,
            self::P_SETUP_TELEGRAM_AI_BROKER,
            self::P_WEB_AI_BROKER,
            self::P_SETUP_WEB_AI_BROKER,
            self::P_FACEBOOK_AI_BROKER,
            self::P_SETUP_FACEBOOK_AI_BROKER,
            self::P_INSTAGRAM_AI_BROKER,
            self::P_SETUP_INSTAGRAM_AI_BROKER,
            self::P_VOICE_AI_BROKER,
            self::P_SETUP_VOICE_AI_BROKER,
            self::P_DATA_ANALYTICS_AI,
            self::P_SETUP_DATA_ANALYTICS_AI,
            self::P_MACRO_CATALOG,
            self::P_MACRO_WEB,
            self::P_CUSTOMER_PORTAL,
            self::P_TELEGRAM_MINI_APP,
            self::P_MACRO_BROKER,
            self::P_MACRO3D_TOURS,
            self::P_MACRO_ERP,
            self::P_SETUP_MACRO_ERP,
            self::P_FLOWFIX,
            self::P_SETUP_FLOWFIX,
            self::P_PPI,
            self::P_AEO_GEO_AI_OPTIMIZER,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Seeder entry point
    // ──────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        // Resolve all group IDs up-front (one query each).
        $groupCache = [];

        foreach (self::allProducts() as $spec) {
            $groupName = $spec['group'];

            if (! array_key_exists($groupName, $groupCache)) {
                $groupCache[$groupName] = ProductGroup::where('name', $groupName)->value('id');
            }

            $this->seedProduct($spec, $groupCache[$groupName]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $spec */
    private function seedProduct(array $spec, ?int $groupId): void
    {
        $product = Product::firstOrCreate(
            ['code' => $spec['code']],
            [
                'name' => $spec['name'],
                'description' => $spec['description'] ?? null,
                'group_id' => $groupId,
                'pricing_type' => $spec['pricing_type'],
                'is_active' => true,
                'sort_order' => $spec['sort_order'],
            ],
        );

        // ── Plans ─────────────────────────────────────────────────────────────

        /** @var list<array<string, mixed>> $plans */
        $plans = $spec['plans'] ?? [];
        $seededPlanCodes = array_column($plans, 'code');

        // Remove plans that are no longer in the spec (stale from old seed versions).
        $product->plans()
            ->when(
                count($seededPlanCodes) > 0,
                fn ($q) => $q->whereNotIn('code', $seededPlanCodes),
                fn ($q) => $q,
            )
            ->get()
            ->each(function (ProductPlan $stalePlan): void {
                ProductPrice::where('plan_id', $stalePlan->id)->delete();
                $stalePlan->delete();
            });

        foreach ($plans as $planSpec) {
            /** @var list<array<string, mixed>> $planPrices */
            $planPrices = $planSpec['prices'] ?? [];
            unset($planSpec['prices']);

            $plan = $product->plans()->firstOrCreate(
                ['code' => $planSpec['code']],
                array_merge($planSpec, ['product_id' => $product->id, 'is_active' => true]),
            );

            foreach ($planPrices as $priceSpec) {
                if ($priceSpec['amount'] === 0) {
                    // Skip zero-amount entries (e.g. Voice AI USD per-min placeholder).
                    continue;
                }

                ProductPrice::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'plan_id' => $plan->id,
                        'currency_code' => $priceSpec['currency_code'],
                    ],
                    ['amount' => $priceSpec['amount']],
                );
            }
        }

        // ── Base prices (no plan) ──────────────────────────────────────────────

        /** @var list<array<string, mixed>> $basePrices */
        $basePrices = $spec['base_prices'] ?? [];

        foreach ($basePrices as $priceSpec) {
            ProductPrice::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'plan_id' => null,
                    'currency_code' => $priceSpec['currency_code'],
                ],
                ['amount' => $priceSpec['amount']],
            );
        }
    }
}
