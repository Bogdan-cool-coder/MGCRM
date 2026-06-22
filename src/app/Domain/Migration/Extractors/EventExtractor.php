<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

/**
 * EventExtractor — fetches the lead change-history (events) into events.jsonl.
 *
 * Temporary migration bounded-context (dropped at M12). Reads:
 *   /events?filter[entity][]=lead&filter[entity_id][]=...  (small batches)
 * over the lead ids from LeadExtractor. This is the heaviest stage (~85% of the
 * total call budget, ~110k events), so each lead id is marked processed in the
 * checkpoint individually — a crash resumes per-lead, not per-batch.
 *
 * Events stream straight to disk; we never hold the full ~110k set in memory.
 */
class EventExtractor extends AbstractExtractor
{
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

        $chunk = (int) config('amo_migration.api.batch.events', 50);
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

            $query = [
                'filter' => [
                    'entity' => ['lead'],
                    'entity_id' => $pending,
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
