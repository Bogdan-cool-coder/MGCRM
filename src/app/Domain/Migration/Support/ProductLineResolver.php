<?php

declare(strict_types=1);

namespace App\Domain\Migration\Support;

use App\Domain\Catalog\Models\ProductPrice;
use App\Domain\Migration\Enums\AmoProductMappingAction;
use App\Domain\Migration\Models\AmoProductMapping;
use App\Domain\Migration\Models\MigrationMap;

/**
 * ProductLineResolver — turns AMO "Продукт" (multiselect, field 590196) enum
 * option ids into MGCRM catalog product/plan deal-line attributes via the
 * hand-curated amo_product_mappings table. Temporary migration bounded-context
 * (dropped at M12).
 *
 * Two-layer lookup (this is what brings BOTH curation tables to life):
 *   1) amo_product_mappings — the hand-curated source of truth (action +
 *      catalog_product_id/catalog_plan_id per AMO enum option).
 *   2) migration_maps — the high-volume runtime translation cache the build plan
 *      (Phase-0 #2) describes. On the first resolve of an AMO enum id the
 *      resolver MATERIALISES the resolution into migration_maps
 *      (map_type='product_option') and reads it back on subsequent runs, so the
 *      per-deal loop does not re-hit amo_product_mappings for every line and the
 *      table is no longer dead.
 *
 * A resolution is one of:
 *   - SKIP   (action=skip, or action=other with no catch-all product): the option
 *            is dropped, no deal_products row. Tallied so the report shows it.
 *   - MAP    (action=map with a catalog_product_id): a deal_products line is
 *            created, unit_price snapshotted from the catalog price book (RUB,
 *            kopecks) or 0 when the product has no list price.
 *   - UNMAPPED (no amo_product_mappings row at all): a NEW AMO option appeared
 *            after the curation pass — tallied for the coverage report so the
 *            operator can add it, never silently mapped.
 */
final class ProductLineResolver
{
    private const MAP_TYPE = 'product_option';

    /**
     * Per-run cache of resolved enum ids → resolution descriptor.
     *
     * @var array<int, array{action: string, product_id: ?int, plan_id: ?int}|null>
     */
    private array $cache = [];

    /**
     * Resolve a single AMO product enum option id to a deal-line descriptor.
     *
     *   - null            → the AMO option has no amo_product_mappings row at all
     *                       (a NEW, un-curated option). Caller tallies it as unmapped.
     *   - action=skip     → curated drop; caller skips it (no deal_products row).
     *   - action=map      → caller creates a deal_products line from product_id/plan_id.
     *
     * @return array{action: string, product_id: ?int, plan_id: ?int}|null
     */
    public function resolve(int $amoEnumId): ?array
    {
        if (array_key_exists($amoEnumId, $this->cache)) {
            return $this->cache[$amoEnumId];
        }

        return $this->cache[$amoEnumId] = $this->resolveFresh($amoEnumId);
    }

    /**
     * Build a deal_products attribute row for a MAP resolution, snapshotting the
     * unit price (kopecks) from the catalog price book in the given currency.
     *
     * @param  array{action: string, product_id: ?int, plan_id: ?int}  $resolution
     * @return array<string, mixed>|null deal_products attrs, or null when not a map.
     */
    public function dealLineAttributes(array $resolution, string $currency, int $sortOrder): ?array
    {
        if ($resolution['action'] !== AmoProductMappingAction::Map->value || $resolution['product_id'] === null) {
            return null;
        }

        $unitPrice = $this->listPrice($resolution['product_id'], $resolution['plan_id'], $currency);

        return [
            'product_id' => $resolution['product_id'],
            'plan_id' => $resolution['plan_id'],
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'discount' => 0,
            'currency' => $currency,
            // Imported budget is locked on the deal (amount_locked=true), so the
            // per-line amount is the price snapshot "for reference" (DEC Feature 5);
            // it does NOT drive Deal.amount on import.
            'amount' => $unitPrice,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * @return array{action: string, product_id: ?int, plan_id: ?int}|null
     */
    private function resolveFresh(int $amoEnumId): ?array
    {
        // 1) Runtime cache table (migration_maps) — read it first so a re-run does
        //    not re-resolve from amo_product_mappings.
        $cached = MigrationMap::query()
            ->where('map_type', self::MAP_TYPE)
            ->where('amo_id', (string) $amoEnumId)
            ->first();

        if ($cached !== null) {
            $meta = is_array($cached->target_meta) ? $cached->target_meta : [];

            return [
                'action' => is_string($cached->target_code) ? $cached->target_code : AmoProductMappingAction::Skip->value,
                'product_id' => $cached->target_id !== null ? (int) $cached->target_id : null,
                'plan_id' => isset($meta['plan_id']) ? (int) $meta['plan_id'] : null,
            ];
        }

        // 2) Curated source of truth (amo_product_mappings).
        $mapping = AmoProductMapping::query()->where('amo_enum_id', $amoEnumId)->first();

        if ($mapping === null) {
            // A brand-new AMO option not in the curation table — leave it unmapped
            // (tallied by the caller). Do NOT materialise a guess into migration_maps.
            return null;
        }

        $action = $mapping->action instanceof AmoProductMappingAction
            ? $mapping->action->value
            : (string) $mapping->action;

        $resolution = [
            'action' => $action,
            'product_id' => $action === AmoProductMappingAction::Map->value ? $mapping->catalog_product_id : null,
            'plan_id' => $action === AmoProductMappingAction::Map->value ? $mapping->catalog_plan_id : null,
        ];

        // Materialise into the runtime cache (migration_maps) so this option is
        // resolved once per archive and the table reflects what the load actually used.
        $this->materialise($amoEnumId, $resolution);

        return $resolution;
    }

    /**
     * Persist a resolved enum option into migration_maps so subsequent runs read
     * it from there. Idempotent on (map_type, amo_id, amo_parent_id).
     *
     * @param  array{action: string, product_id: ?int, plan_id: ?int}  $resolution
     */
    private function materialise(int $amoEnumId, array $resolution): void
    {
        MigrationMap::query()->updateOrCreate(
            [
                'map_type' => self::MAP_TYPE,
                'amo_id' => (string) $amoEnumId,
                'amo_parent_id' => (string) AmoFields::LEAD_PRODUCT,
            ],
            [
                'target_code' => $resolution['action'],
                'target_id' => $resolution['product_id'],
                'target_meta' => ['plan_id' => $resolution['plan_id']],
            ],
        );
    }

    private function listPrice(int $productId, ?int $planId, string $currency): int
    {
        $query = ProductPrice::query()
            ->where('product_id', $productId)
            ->where('currency_code', $currency);

        $query = $planId !== null
            ? $query->where('plan_id', $planId)
            : $query->whereNull('plan_id');

        $amount = $query->orderByDesc('valid_from')->value('amount');

        return $amount !== null ? (int) $amount : 0;
    }
}
