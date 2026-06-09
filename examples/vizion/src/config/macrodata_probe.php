<?php

/**
 * MacroData probe configuration for per-company semantic key resolution.
 *
 * Each entry in 'semantic_keys' describes how to discover IDs for a specific
 * semantic concept (e.g. "sale" finance type) from a MacroData table.
 *
 * Fields per semantic key:
 *   table        — MacroData table name to query
 *   value_field  — column whose value goes into the 'value' / mapping array
 *   match_field  — column used for LIKE-pattern matching
 *   patterns     — grouped by locale ('ru', 'en'), each a list of SQL LIKE
 *                  patterns (case-insensitive match is applied in PHP via mb_strtolower)
 *
 * Adding a new semantic key: add an entry here without touching CompanySchemaProbeService.
 */
return [
    'semantic_keys' => [
        'finance_type_sale_ids' => [
            'table'       => 'finances_types',
            'value_field' => 'id',
            'match_field' => 'types_name',
            'patterns'    => [
                'ru' => [
                    '%поступления от продажи%',
                    '%продажа недвижимости%',
                    '%доход от продажи%',
                ],
                'en' => [
                    '%proceeds from sale%',
                    '%sale of real estate%',
                    '%real estate sales%',
                    '%proceeds from real estate%',
                    '%sale proceeds%',
                ],
            ],
        ],
        'finance_type_booking_ids' => [
            'table'       => 'finances_types',
            'value_field' => 'id',
            'match_field' => 'types_name',
            'patterns'    => [
                'ru' => [
                    '%бронь%',
                    '%резерв%',
                    '%бронирование%',
                ],
                'en' => [
                    '%booking%',
                    '%reservation%',
                    '%deposit%',
                ],
            ],
        ],
    ],
];
