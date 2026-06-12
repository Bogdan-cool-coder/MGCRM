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

    /*
    |--------------------------------------------------------------------------
    | Document attachment upload constraints
    |--------------------------------------------------------------------------
    | allowed_mimes     — MIME types checked in AttachmentService::upload()
    | allowed_extensions— file extensions checked in UploadAttachmentRequest
    | max_size_bytes    — hard limit (15 MB default)
    | extensions        — MIME → extension mapping used to derive file names
    */
    'attachments' => [
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ],
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'max_size_bytes' => 15 * 1024 * 1024, // 15 MB
        'extensions' => [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ],
    ],
];
