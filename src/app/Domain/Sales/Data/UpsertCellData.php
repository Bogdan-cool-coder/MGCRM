<?php

declare(strict_types=1);

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\PlanLayer;
use App\Domain\Sales\Enums\PlanMetric;
use App\Domain\Sales\Enums\PlanScopeType;

/**
 * One cell in a bulk-upsert request (P-2, contract §6.2/§7.2). A null
 * value_kopecks/value_count on an EXISTING cell means "delete this cell"
 * (contract §O10).
 */
readonly class UpsertCellData
{
    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(
        public PlanMetric $metric,
        public PlanScopeType $scopeType,
        public int $periodYear,
        public ?int $periodMonth,
        public PlanLayer $layer,
        public ?int $scopeUserId = null,
        public ?int $scopePipelineId = null,
        public ?int $scopeProductGroupId = null,
        public ?int $valueKopecks = null,
        public ?int $valueCount = null,
        public ?string $currency = null,
        public ?array $config = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            metric: PlanMetric::from($row['metric']),
            scopeType: PlanScopeType::from($row['scope_type']),
            periodYear: (int) $row['period_year'],
            periodMonth: isset($row['period_month']) ? (int) $row['period_month'] : null,
            layer: PlanLayer::from($row['layer']),
            scopeUserId: isset($row['scope_user_id']) ? (int) $row['scope_user_id'] : null,
            scopePipelineId: isset($row['scope_pipeline_id']) ? (int) $row['scope_pipeline_id'] : null,
            scopeProductGroupId: isset($row['scope_product_group_id']) ? (int) $row['scope_product_group_id'] : null,
            valueKopecks: array_key_exists('value_kopecks', $row) && $row['value_kopecks'] !== null ? (int) $row['value_kopecks'] : null,
            valueCount: array_key_exists('value_count', $row) && $row['value_count'] !== null ? (int) $row['value_count'] : null,
            currency: $row['currency'] ?? null,
            config: $row['config'] ?? null,
        );
    }
}
