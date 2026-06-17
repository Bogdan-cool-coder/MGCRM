<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * Plain readonly value object — NOT a spatie/laravel-data class.
 * ARCHITECTURE.md §0.1: manual classes, no spatie/data.
 */
readonly class ImportResult
{
    public function __construct(
        public int $inserted,
        public int $updated,
        public int $skipped,
        public array $errors,   // [{row: int, message: string}]
        public bool $dryRun = false,
    ) {}
}
