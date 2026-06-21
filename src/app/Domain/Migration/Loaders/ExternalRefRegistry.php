<?php

declare(strict_types=1);

namespace App\Domain\Migration\Loaders;

use App\Domain\Migration\Models\ExternalRef;

/**
 * ExternalRefRegistry — the idempotency core of the load phase. Temporary
 * migration bounded-context (dropped at M12).
 *
 * Every imported entity records a provenance row in external_refs keyed by
 * (source='amocrm', entity_type, external_id). A re-run resolves the existing
 * local id from that row instead of inserting a duplicate. The registry caches
 * the (entity_type, external_id) → local id map in memory so the per-deal loop
 * does not re-query external_refs for every contact / company link.
 */
final class ExternalRefRegistry
{
    private const SOURCE = 'amocrm';

    /** @var array<string, int> "type:external" => local id */
    private array $cache = [];

    /**
     * Resolve the local entity id for an AMO id, or null if never imported.
     */
    public function resolve(string $entityType, int|string $externalId): ?int
    {
        $key = $entityType.':'.$externalId;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $id = ExternalRef::query()
            ->where('source', self::SOURCE)
            ->where('entity_type', $entityType)
            ->where('external_id', (string) $externalId)
            ->value('entity_id');

        if ($id !== null) {
            return $this->cache[$key] = (int) $id;
        }

        return null;
    }

    /**
     * Record (or refresh) the provenance row linking an AMO id to a local id.
     * Idempotent: re-running upserts on (source, entity_type, external_id).
     *
     * @param  array<string, mixed>|null  $payload  Raw source record (optional).
     */
    public function remember(string $entityType, int|string $externalId, int $localId, ?array $payload = null): void
    {
        ExternalRef::query()->updateOrCreate(
            [
                'source' => self::SOURCE,
                'entity_type' => $entityType,
                'external_id' => (string) $externalId,
            ],
            [
                'entity_id' => $localId,
                'external_payload' => $payload,
                'imported_at' => now(),
            ],
        );

        $this->cache[$entityType.':'.$externalId] = $localId;
    }
}
