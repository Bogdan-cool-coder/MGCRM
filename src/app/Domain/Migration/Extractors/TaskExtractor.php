<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

/**
 * TaskExtractor — fetches lead tasks into tasks.jsonl.
 *
 * Temporary migration bounded-context (dropped at M12). Reads:
 *   /tasks?filter[entity_type]=leads&filter[entity_id][]=...  (batches of 50)
 * over the lead ids collected by LeadExtractor (leads.ids.json). Checkpoint
 * granularity is the entity_id batch index for --resume.
 */
class TaskExtractor extends AbstractExtractor
{
    public function entityName(): string
    {
        return 'tasks';
    }

    public function run(): int
    {
        $checkpoint = $this->makeCheckpoint();

        if ($checkpoint->isDone() && $this->resume) {
            $this->log('tasks: already complete — skipping');

            return 0;
        }

        $leadIds = $this->readSidecarIds('leads');

        if ($leadIds === []) {
            $this->log('tasks: no lead ids (run leads first) — nothing to do');

            return 0;
        }

        if ($this->limit > 0) {
            $leadIds = array_slice($leadIds, 0, $this->limit);
        }

        $chunk = (int) config('amo_migration.api.batch.tasks', 50);
        $batches = array_chunk(array_values(array_unique($leadIds)), max(1, $chunk));
        $startBatch = $this->resume ? $checkpoint->page() : 0;

        $writer = $this->makeWriter();
        $count = 0;

        foreach ($batches as $index => $batch) {
            if ($index < $startBatch) {
                continue;
            }

            $query = [
                'filter' => [
                    'entity_type' => 'leads',
                    'entity_id' => $batch,
                ],
                'limit' => 250,
            ];

            foreach ($this->client->getPaginated('/tasks', $query) as $body) {
                foreach ($body['_embedded']['tasks'] ?? [] as $task) {
                    $writer->write($task);
                    $count++;
                }
            }

            $checkpoint->setPage($index + 1);
            $this->log(sprintf('tasks: batch %d/%d done, %d written', $index + 1, count($batches), $count));
        }

        $writer->close();
        $checkpoint->markDone();

        $this->log("tasks: {$count} written");

        return $count;
    }
}
