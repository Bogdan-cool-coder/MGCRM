<?php

// MGCRM project-level configuration. Anything domain-specific that other layers
// need at runtime lives here so application code reads config('crm.*') instead
// of env() directly (ARCHITECTURE.md §3 — env() only in config/).

return [

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | The six fixed RBAC roles, derived from the legacy product spec
    | (./examples/contracts/ — app/models.py UserRole). Authorization is wired
    | through spatie/laravel-permission; this list is the seeder's source of
    | truth (RolePermissionSeeder, added in M0.4). Order matters for default
    | landing/visibility resolution.
    |
    |   admin      — full access
    |   director   — full access, no system administration
    |   lawyer     — contracts/legal, elevated visibility
    |   manager    — sales, own-scope visibility
    |   accountant — finance: data entry / posting / manual journals
    |   cfo        — finance: + period close + settings + management reports
    |
    */
    'roles' => [
        'admin',
        'director',
        'lawyer',
        'manager',
        'accountant',
        'cfo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Currencies
    |--------------------------------------------------------------------------
    |
    | Supported currencies. Monetary amounts are stored as integer minor units
    | (kopecks/cents) per ARCHITECTURE.md §3 — never float/decimal. The default
    | reporting currency is RUB (VAT 20% RF).
    |
    */
    'currencies' => [
        'default' => env('CRM_DEFAULT_CURRENCY', 'RUB'),
        'supported' => ['RUB', 'USD', 'EUR', 'KZT', 'UZS', 'AED'],
    ],

    /*
    |--------------------------------------------------------------------------
    | VAT (РФ)
    |--------------------------------------------------------------------------
    |
    | Current Russian VAT rate is 20% (the legacy 0/10/18 rates remain valid for
    | historical documents). Stored as basis points (2000 = 20.00%) to stay
    | integer-safe.
    |
    */
    'vat' => [
        'default_bps' => 2000,
        'historical_bps' => [0, 1000, 1800, 2000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | MGCRM is RU-first with EN as the fallback. Translatable fields are stored
    | as jsonb (spatie/laravel-translatable) for a future EN UI.
    |
    */
    'locale' => [
        'default' => 'ru',
        'supported' => ['ru', 'en'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exchange Rate
    |--------------------------------------------------------------------------
    |
    | Configuration for the daily exchange rate refresh job (UpdateExchangeRatesJob).
    | Source: exchangerate.host (or compatible API). The job upserts rates for all
    | supported currency pairs into catalog_exchange_rates using ON CONFLICT DO UPDATE
    | — no duplicate rows on UNIQUE (from_code, to_code, date).
    |
    | PLAN §Д: FxRate in scope S1.2. Finance (M9) reads via ExchangeRateService,
    | never directly from catalog_exchange_rates.
    |
    */
    'exchange_rate' => [
        'api_url' => env('EXCHANGE_RATE_API_URL', 'https://api.exchangerate.host'),
        'api_key' => env('EXCHANGE_RATE_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline / Sales Dashboard
    |--------------------------------------------------------------------------
    |
    | Probability keywords for forecast computation (SalesDashboardService).
    | Keys are lowercase substrings matched against stage.name; values are
    | weights [0.0 – 1.0] applied to deal.amount in the weighted forecast.
    |
    | HOT threshold: stages with probability >= 0.7 are placed in the "hot"
    | bucket regardless of which keyword matched.
    |
    */
    'pipeline' => [
        'hot_threshold' => 0.7,
        'probability_keywords' => [
            'won' => 1.0,
            'успех' => 1.0,
            'signed' => 1.0,
            'paid' => 1.0,
            'оплачен' => 1.0,
            'выигран' => 1.0,
            'hot' => 0.7,
            'горяч' => 0.7,
            'trial' => 0.5,
            'negotiation' => 0.5,
            'согласован' => 0.5,
            'warm' => 0.4,
            'тёпл' => 0.4,
            'теплый' => 0.4,
            'proposal' => 0.3,
            'кп' => 0.3,
            'meeting' => 0.2,
            'встреч' => 0.2,
            'qualif' => 0.15,
            'квалиф' => 0.15,
            'cold' => 0.1,
            'холод' => 0.1,
            'lost' => 0.0,
            'проигран' => 0.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | KPI / Manager Cabinet (S1.8)
    |--------------------------------------------------------------------------
    |
    | Thresholds for the score_pct badge in the manager cabinet.
    | score_warning_threshold: pct >= this → 'warning' badge (below = 'danger').
    | Both thresholds are 80 by design (danger < 80, warning 80–99, success >= 100).
    |
    */
    'kpi' => [
        'score_warning_threshold' => 80,
        'score_danger_threshold' => 80,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage paths
    |--------------------------------------------------------------------------
    |
    | Relative paths (under the configured filesystem disk) where uploaded and
    | generated artefacts live. Used by avatar uploads, generated contract PDFs,
    | exports, etc. Public assets go under storage/app/public (served via the
    | public/storage symlink created by entrypoint.sh).
    |
    */
    'storage' => [
        'disk' => env('CRM_STORAGE_DISK', 'public'),
        'avatars' => 'avatars',
        'contracts' => 'contracts',
        'documents' => 'documents',
        'exports' => 'exports',
    ],

];
