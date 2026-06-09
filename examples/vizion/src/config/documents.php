<?php

declare(strict_types=1);

/**
 * Configuration for the Documents subsystem (DocumentController + rendering).
 *
 * - gotenberg_url: base URL of the Gotenberg service (Chromium HTML->PDF and
 *   LibreOffice docx->PDF). In the docker stack this is the internal service
 *   name http://gotenberg:3000 (injected into app + queue-worker via env).
 * - gotenberg_timeout: HTTP timeout (seconds) for a single conversion request.
 * - disk: filesystem disk used to store and serve generated document files
 *   (PDF/docx). Defaults to the dedicated "documents" disk whose public file +
 *   directory visibility (0644/0755) keeps queue-worker-written files (root)
 *   readable by the php-fpm pool (www-data). See config/filesystems.php.
 * - field_catalog: canonical grouped field reference.
 *
 *   STRUCTURE:
 *   Each field entry:
 *     key     — canonical ${group.field} placeholder name
 *     label   — {ru, en} localized label
 *     example — example value (for UI reference modal)
 *     group   — logical group name (repeats the parent key, for flat iteration)
 *     filters — allowed rendering filters (words|rouble|format|date|date_words)
 *     pii     — (optional) true = personally identifiable information; ACL flag
 *
 *   FILTERS reference:
 *     words      — number to words in Russian ("три миллиона пятьсот тысяч рублей")
 *     rouble     — append "рублей" / "рубль" / "рубля" (declension)
 *     format     — format number with space-thousands separator
 *     date       — format date as "DD.MM.YYYY"
 *     date_words — format date as "01 января 2024 г."
 *
 *   MONEY FIELDS (support words|rouble|format):
 *     estate.price, estate.price_m2, estate.price_action, estate.restoration_price,
 *     deal.sum, deal.price, deal.price_m2, deal.sum_addons,
 *     finances.first_payment_sum, finances.balance, finances.total_paid,
 *     discount.amount, discount.price_discounted
 *
 *   DATE FIELDS (support date|date_words):
 *     estate — none
 *     deal.date, deal.date_start,
 *     buyer.dob,
 *     finances.first_payment_date, finances.last_payment_date,
 *     common.today
 *
 *   NOT IMPLEMENTED (confirmed missing from MacroData schema):
 *     estate.cadastral     — no cadastral column exists in estate_sells
 *     estate.description   — no description column exists in estate_sells
 *     buyer.passport_series / passport_number / passport_issued_by / passport_date
 *                          — contacts table has passport_address + passport_bithplace only
 *
 *   discount.* and common.today values are injected by the backend rendering
 *   engine (GenerateDocumentJob / HtmlDocumentService), not by DocumentObjectDataService.
 *
 *   Keep field_catalog['object'] in lock-step with
 *   App\Services\MacroData\DocumentObjectDataService — keys MUST match.
 */
return [
    'gotenberg_url'     => env('GOTENBERG_URL', 'http://gotenberg:3000'),
    'gotenberg_timeout' => (int) env('GOTENBERG_TIMEOUT', 120),
    'disk'              => env('DOCUMENTS_DISK', 'documents'),

    'field_catalog' => [

        // =====================================================================
        // object — fields resolved from MacroData by DocumentObjectDataService.
        // Canonical keys: estate.*
        // =====================================================================
        'object' => [
            [
                'key'     => 'estate.area',
                'label'   => ['ru' => 'Площадь (число)', 'en' => 'Area (number)'],
                'example' => '65.4',
                'group'   => 'object',
                'filters' => ['format'],
            ],
            [
                'key'     => 'estate.area_bti',
                'label'   => ['ru' => 'Площадь по БТИ', 'en' => 'BTI area'],
                'example' => '66.1',
                'group'   => 'object',
                'filters' => ['format'],
            ],
            [
                'key'     => 'estate.area_inside',
                'label'   => ['ru' => 'Площадь внутренняя', 'en' => 'Inner area'],
                'example' => '60.0',
                'group'   => 'object',
                'filters' => ['format'],
            ],
            [
                'key'     => 'estate.area_terrace',
                'label'   => ['ru' => 'Площадь террасы (БТИ)', 'en' => 'Terrace area (BTI)'],
                'example' => '5.4',
                'group'   => 'object',
                'filters' => ['format'],
            ],
            [
                'key'     => 'estate.price',
                'label'   => ['ru' => 'Цена объекта', 'en' => 'Object price'],
                'example' => '3500000',
                'group'   => 'object',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'estate.price_m2',
                'label'   => ['ru' => 'Цена за м²', 'en' => 'Price per m²'],
                'example' => '53000',
                'group'   => 'object',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'estate.price_action',
                'label'   => ['ru' => 'Акционная цена', 'en' => 'Promo price'],
                'example' => '3200000',
                'group'   => 'object',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'estate.floor',
                'label'   => ['ru' => 'Этаж', 'en' => 'Floor'],
                'example' => '7',
                'group'   => 'object',
                'filters' => [],
            ],
            [
                'key'     => 'estate.rooms',
                'label'   => ['ru' => 'Количество комнат', 'en' => 'Rooms'],
                'example' => '3',
                'group'   => 'object',
                'filters' => [],
            ],
            [
                'key'     => 'estate.number',
                'label'   => ['ru' => 'Номер квартиры / помещения', 'en' => 'Flat / unit number'],
                'example' => '42',
                'group'   => 'object',
                'filters' => [],
            ],
            [
                'key'     => 'estate.restoration_name',
                'label'   => ['ru' => 'Тип отделки', 'en' => 'Finishing type'],
                'example' => 'Под ключ',
                'group'   => 'object',
                'filters' => [],
            ],
            [
                'key'     => 'estate.restoration_price',
                'label'   => ['ru' => 'Стоимость отделки', 'en' => 'Finishing price'],
                'example' => '500000',
                'group'   => 'object',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'estate.house_name',
                'label'   => ['ru' => 'Название дома / корпуса', 'en' => 'Building name'],
                'example' => 'Корпус 1',
                'group'   => 'object',
                'filters' => [],
            ],
            [
                'key'     => 'estate.complex_name',
                'label'   => ['ru' => 'Название ЖК / проекта', 'en' => 'Complex name'],
                'example' => 'Солнечный',
                'group'   => 'object',
                'filters' => [],
            ],
            [
                'key'     => 'estate.address',
                'label'   => ['ru' => 'Адрес объекта', 'en' => 'Object address'],
                'example' => 'Краснодар, ул. Красная, 1',
                'group'   => 'object',
                'filters' => [],
            ],
            // Note: estate.cadastral and estate.description are NOT available —
            // the corresponding columns do not exist in the estate_sells table.
        ],

        // =====================================================================
        // deal — fields from EstateDeals (best-effort, may be absent for free units)
        // =====================================================================
        'deal' => [
            [
                'key'     => 'deal.number',
                'label'   => ['ru' => 'Номер договора', 'en' => 'Agreement number'],
                'example' => 'ДДУ-2024-001',
                'group'   => 'deal',
                'filters' => [],
            ],
            [
                'key'     => 'deal.date',
                'label'   => ['ru' => 'Дата договора', 'en' => 'Agreement date'],
                'example' => '2024-06-15',
                'group'   => 'deal',
                'filters' => ['date', 'date_words'],
            ],
            [
                'key'     => 'deal.date_start',
                'label'   => ['ru' => 'Дата начала сделки', 'en' => 'Deal start date'],
                'example' => '2024-05-01',
                'group'   => 'deal',
                'filters' => ['date', 'date_words'],
            ],
            [
                'key'     => 'deal.sum',
                'label'   => ['ru' => 'Сумма сделки', 'en' => 'Deal sum'],
                'example' => '3750000',
                'group'   => 'deal',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'deal.price',
                'label'   => ['ru' => 'Цена по договору', 'en' => 'Contract price'],
                'example' => '3750000',
                'group'   => 'deal',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'deal.area',
                'label'   => ['ru' => 'Площадь по договору', 'en' => 'Contract area'],
                'example' => '65.4',
                'group'   => 'deal',
                'filters' => ['format'],
            ],
            [
                'key'     => 'deal.price_m2',
                'label'   => ['ru' => 'Цена за м² по договору (вычисляемая)', 'en' => 'Contract price per m² (derived)'],
                'example' => '57340',
                'group'   => 'deal',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'deal.sum_addons',
                'label'   => ['ru' => 'Дополнительные платежи', 'en' => 'Additional payments'],
                'example' => '50000',
                'group'   => 'deal',
                'filters' => ['words', 'rouble', 'format'],
            ],
        ],

        // =====================================================================
        // buyer — fields from Contacts (PII, best-effort via deal→contactsBuy)
        // PII fields marked with pii:true — for future ACL enforcement.
        //
        // NOT AVAILABLE from Contacts schema:
        //   passport_series, passport_number, passport_issued_by, passport_date
        //   (contacts table has only passport_address + passport_bithplace)
        // =====================================================================
        'buyer' => [
            [
                'key'     => 'buyer.full_name',
                'label'   => ['ru' => 'ФИО покупателя', 'en' => 'Buyer full name'],
                'example' => 'Иванов Иван Иванович',
                'group'   => 'buyer',
                'filters' => [],
                'pii'     => true,
            ],
            [
                'key'     => 'buyer.dob',
                'label'   => ['ru' => 'Дата рождения', 'en' => 'Date of birth'],
                'example' => '1985-03-22',
                'group'   => 'buyer',
                'filters' => ['date', 'date_words'],
                'pii'     => true,
            ],
            [
                'key'     => 'buyer.phone',
                'label'   => ['ru' => 'Телефон покупателя', 'en' => 'Buyer phone'],
                'example' => '+7 (900) 123-45-67',
                'group'   => 'buyer',
                'filters' => [],
                'pii'     => true,
            ],
            [
                'key'     => 'buyer.email',
                'label'   => ['ru' => 'Email покупателя', 'en' => 'Buyer email'],
                'example' => 'ivanov@example.com',
                'group'   => 'buyer',
                'filters' => [],
                'pii'     => true,
            ],
            [
                'key'     => 'buyer.inn',
                'label'   => ['ru' => 'ИНН покупателя', 'en' => 'Buyer INN (tax ID)'],
                'example' => '234567890123',
                'group'   => 'buyer',
                'filters' => [],
                'pii'     => true,
            ],
            [
                'key'     => 'buyer.snils',
                'label'   => ['ru' => 'СНИЛС покупателя', 'en' => 'Buyer SNILS'],
                'example' => '123-456-789 01',
                'group'   => 'buyer',
                'filters' => [],
                'pii'     => true,
            ],
            [
                'key'     => 'buyer.address_reg',
                'label'   => ['ru' => 'Адрес регистрации покупателя', 'en' => 'Buyer registration address'],
                'example' => 'г. Москва, ул. Ленина, д. 1, кв. 5',
                'group'   => 'buyer',
                'filters' => [],
                'pii'     => true,
            ],
        ],

        // =====================================================================
        // finances — payment summary scalars (best-effort, from deal→finances)
        // Full payment schedule table loop is not supported in v1 (scalar only).
        // =====================================================================
        'finances' => [
            [
                'key'     => 'finances.first_payment_sum',
                'label'   => ['ru' => 'Первый платёж (сумма)', 'en' => 'First payment amount'],
                'example' => '500000',
                'group'   => 'finances',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'finances.first_payment_date',
                'label'   => ['ru' => 'Дата первого платежа', 'en' => 'First payment date'],
                'example' => '2024-07-01',
                'group'   => 'finances',
                'filters' => ['date', 'date_words'],
            ],
            [
                'key'     => 'finances.last_payment_date',
                'label'   => ['ru' => 'Дата последнего платежа', 'en' => 'Last payment date'],
                'example' => '2025-03-01',
                'group'   => 'finances',
                'filters' => ['date', 'date_words'],
            ],
            [
                'key'     => 'finances.balance',
                'label'   => ['ru' => 'Остаток по договору', 'en' => 'Outstanding balance'],
                'example' => '3250000',
                'group'   => 'finances',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'finances.count',
                'label'   => ['ru' => 'Количество платежей', 'en' => 'Payment count'],
                'example' => '12',
                'group'   => 'finances',
                'filters' => [],
            ],
            [
                'key'     => 'finances.total_paid',
                'label'   => ['ru' => 'Итого оплачено', 'en' => 'Total paid'],
                'example' => '500000',
                'group'   => 'finances',
                'filters' => ['words', 'rouble', 'format'],
            ],
        ],

        // =====================================================================
        // discount — values computed from the selected promotion + discount at
        // render time (GenerateDocumentJob / HtmlDocumentService).
        // Not resolved by DocumentObjectDataService.
        // =====================================================================
        'discount' => [
            [
                'key'     => 'discount.label',
                'label'   => ['ru' => 'Название акции', 'en' => 'Promotion name'],
                'example' => 'Акция "Летний сезон"',
                'group'   => 'discount',
                'filters' => [],
            ],
            [
                'key'     => 'discount.percent',
                'label'   => ['ru' => 'Размер скидки, %', 'en' => 'Discount percent'],
                'example' => '5',
                'group'   => 'discount',
                'filters' => ['format'],
            ],
            [
                'key'     => 'discount.amount',
                'label'   => ['ru' => 'Сумма скидки', 'en' => 'Discount amount'],
                'example' => '175000',
                'group'   => 'discount',
                'filters' => ['words', 'rouble', 'format'],
            ],
            [
                'key'     => 'discount.price_discounted',
                'label'   => ['ru' => 'Цена со скидкой', 'en' => 'Discounted price'],
                'example' => '3325000',
                'group'   => 'discount',
                'filters' => ['words', 'rouble', 'format'],
            ],
        ],

        // =====================================================================
        // common — system-level values injected at render time (not MacroData).
        // =====================================================================
        'common' => [
            [
                'key'     => 'common.today',
                'label'   => ['ru' => 'Сегодняшняя дата', 'en' => 'Today\'s date'],
                'example' => '2024-06-15',
                'group'   => 'common',
                'filters' => ['date', 'date_words'],
            ],
        ],

        // =====================================================================
        // branding — tokens injected from CompanyBranding profile.
        // Logo / palette are applied as CSS / <img> in HTML-КП, not as tokens.
        // req_* is a wildcard pattern for dynamic requisite keys.
        // =====================================================================
        'branding' => [
            [
                'key'     => 'brand_header',
                'label'   => ['ru' => 'Шапка (брендинг)', 'en' => 'Header (branding)'],
                'example' => 'ООО «Застройщик»',
                'group'   => 'branding',
                'filters' => [],
            ],
            [
                'key'     => 'brand_footer',
                'label'   => ['ru' => 'Подвал (брендинг)', 'en' => 'Footer (branding)'],
                'example' => 'г. Краснодар, ул. Красная, 1',
                'group'   => 'branding',
                'filters' => [],
            ],
            [
                'key'     => 'req_*',
                'label'   => [
                    'ru' => 'Реквизит компании (req_<ключ>, динамический по реквизитам бренда)',
                    'en' => 'Company requisite (req_<key>, dynamic per branding requisites)',
                ],
                'example' => 'req_ogrn → 1234567890123',
                'group'   => 'branding',
                'filters' => [],
            ],
        ],
    ],
];
