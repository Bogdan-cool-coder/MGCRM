<?php

declare(strict_types=1);

namespace App\Support\System\Data;

/**
 * Immutable payload of GET /api/system/reset/preview — the live per-category row
 * counts + prerequisite matrix the UI drives its checkboxes from (contract §6.1).
 * Surfaced through SystemResetPreviewResource.
 *
 * `categories` is a list of per-category rows in the enum's declared order, each:
 *   [ 'key' => 'deals', 'label' => 'Сделки', 'count' => 1248, 'requires' => [] ]
 */
final readonly class ResetPreviewData
{
    /**
     * @param  list<array{key: string, label: string, count: int, requires: list<string>}>  $categories
     */
    public function __construct(
        public bool $enabled,
        public string $confirmationPhrase,
        public array $categories,
    ) {}
}
