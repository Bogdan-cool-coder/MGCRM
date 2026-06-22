<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

/**
 * EventExtractor — fetches the lead change-history (events) into events.jsonl.
 *
 * Temporary migration bounded-context (dropped at M12). Reads:
 *   /events?filter[entity]=lead&filter[entity_id][]=...  (batches of <= 10 ids)
 * over the lead ids from LeadExtractor. This is the heaviest stage (~85% of the
 * total call budget, ~110k events), so each lead id is marked processed in the
 * checkpoint individually — a crash resumes per-lead, not per-batch.
 *
 * AMO /events filter rules (confirmed against the live API):
 *   - filter[entity] is the entity type and MUST be present whenever entity_id is
 *     used; sending entity_id alone returns 400 "Required param missed.".
 *   - filter[entity_id] is hard-capped at 10 ids per request — an 11th id returns
 *     400 "More params given than allowed." Hence MAX_ENTITY_IDS below, which we
 *     clamp the configured batch to defensively.
 *   - limit caps at 100; a 10-lead batch routinely spans several pages, so each
 *     batch is drained through getPaginated() before its lead ids are checkpointed.
 *
 * Events stream straight to disk; we never hold the full ~110k set in memory.
 */
class EventExtractor extends AbstractExtractor
{
    /** AMO hard cap on filter[entity_id] cardinality per /events request. */
    private const MAX_ENTITY_IDS = 10;

    public function entityName(): string
    {
        return 'events';
    }

    public function run(): int
    {
        $checkpoint = $this->makeCheckpoint();

        if ($checkpoint->isDone() && $this->resume) {
            $this->log('events: already complete — skipping');

            return 0;
        }

        $leadIds = $this->readSidecarIds('leads');

        if ($leadIds === []) {
            $this->log('events: no lead ids (run leads first) — nothing to do');

            return 0;
        }

        if ($this->limit > 0) {
            $leadIds = array_slice($leadIds, 0, $this->limit);
        }

        // AMO caps filter[entity_id] at 10 ids per /events request; clamp whatever
        // is configured so a stale/over-large value can never produce a 400.
        $chunk = min(self::MAX_ENTITY_IDS, (int) config('amo_migration.api.batch.events', self::MAX_ENTITY_IDS));
        $batches = array_chunk(array_values(array_unique($leadIds)), max(1, $chunk));

        $writer = $this->makeWriter();
        $count = 0;
        $batchIndex = 0;

        foreach ($batches as $batch) {
            $batchIndex++;

            // On --resume, drop lead ids already processed in a prior run.
            $pending = $this->resume
                ? array_values(array_filter($batch, fn (int $id): bool => ! $checkpoint->isProcessed($id)))
                : $batch;

            if ($pending === []) {
                continue;
            }

            // filter[entity] is a single entity-type string (not an array) and is
            // required alongside filter[entity_id]; filter[entity_id] is the (<=10)
            // lead-id array. limit is the AMO max page size (100).
            $query = [
                'filter' => [
                    'entity' => 'lead',
                    'entity_id' => array_values($pending),
                ],
                'limit' => 100,
            ];

            foreach ($this->client->getPaginated('/events', $query) as $body) {
                foreach ($body['_embedded']['events'] ?? [] as $event) {
                    $writer->write($event);
                    $count++;
                }
            }

            foreach ($pending as $id) {
                $checkpoint->markProcessed($id);
            }

            $this->log(sprintf('events: batch %d/%d done, %d events written', $batchIndex, count($batches), $count));
        }

        $writer->close();
        $checkpoint->markDone();

        $this->log("events: {$count} written");

        return $count;
    }
}
