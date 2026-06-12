<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Gotenberg PDF renderer
    |--------------------------------------------------------------------------
    | HTTP service that converts docx → PDF via LibreOffice.
    | docker-compose.dev.yml: service 'gotenberg', port 3000, internal network.
    */
    'gotenberg_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),

    /*
    |--------------------------------------------------------------------------
    | Storage disk for contracts and templates
    |--------------------------------------------------------------------------
    | Driver: local (non-public). Root: storage/app/private/documents.
    | Access via Sanctum Bearer + Storage::disk('documents')->download().
    */
    'documents_disk' => 'documents',

    /*
    |--------------------------------------------------------------------------
    | Template storage path on the documents disk
    |--------------------------------------------------------------------------
    | Naming convention: templates/{template_code}/v{version_number}_{timestamp}.docx
    */
    'template_path' => 'templates',

    /*
    |--------------------------------------------------------------------------
    | Contract number format
    |--------------------------------------------------------------------------
    | Pattern: {city_code}-{number}/{country_suffix}
    | Example: ТШК-220/UZ
    */
    'number_format' => '{city_code}-{number}/{country_suffix}',

    /*
    |--------------------------------------------------------------------------
    | Supported currencies
    |--------------------------------------------------------------------------
    */
    'currencies' => ['KZT', 'UZS', 'RUB', 'USD', 'EUR'],

    /*
    |--------------------------------------------------------------------------
    | Supported product codes
    |--------------------------------------------------------------------------
    */
    'product_codes' => ['macrocrm', 'macrosales', 'macroerp'],

    /*
    |--------------------------------------------------------------------------
    | Supported country codes
    |--------------------------------------------------------------------------
    */
    'country_codes' => ['kz', 'uz'],
];
