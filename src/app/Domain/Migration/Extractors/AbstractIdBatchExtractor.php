<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

/**
 * AbstractIdBatchExtractor — fetch a list of entities by id in filter[id][]
 * batches and stream each into <name>.jsonl.
 *
 * Temporary migration bounded-context (dropped at M12). Used by
 * Contact/CompanyExtractor: the ids come from the sidecar collected by
 * LeadExtractor. Checkpoint granularity is the batch index, so --resume skips
 * already-written batches.
 */
abstract class AbstractIdBatchExtractor extends AbstractExtractor
{
    /** AMO list path, e.g. /contacts. */
    abstract protected function path(): string;

    /** Sidecar id-list basename (contacts / companies). */
    abstract protected function idSidecar(): string;

    /** Embedded list key inside a page body (contacts / companies). */
    abstract protected function embeddedKey(): string;

    /** Config key under amo_migration.api.batch for the chunk size. */
    abstract protected function batchConfigKey(): string;

    public function run(): int
    {
        $checkpoint = $this->makeCheckpoint();

        if ($checkpoint->isDone() && $this->resume) {
            $this->log("{$this->entityName()}: already complete — skipping");

            return 0;
        }

        $ids = $this->readSidecarIds($this->idSidecar());

        if ($ids === []) {
            $this->log("{$this->entityName()}: no ids in sidecar (run leads first) — nothing to do");

            return 0;
        }

        if ($this->limit > 0) {
            $ids = array_slice($ids, 0, $this->limit);
        }

        $chunk = (int) config("amo_migration.api.batch.{$this->batchConfigKey()}", 250);
        $writer = $this->makeWriter();

        $batches = array_chunk(array_values(array_unique($ids)), max(1, $chunk));
        $startBatch = $this->resume ? $checkpoint->page() : 0;

        $count = 0;

        foreach ($batches as $index => $batch) {
            if ($index < $startBatch) {
                continue;
            }

            // The outer loop already chunked; fetch this batch (may itself span
            // pages if AMO splits a 250-id filter) via the id-filter query.
            $query = ['filter' => ['id' => $batch], 'limit' => 250];

            foreach ($this->client->getPaginated($this->path(), $query) as $body) {
                foreach ($body['_embedded'][$this->embeddedKey()] ?? [] as $entity) {
                    $writer->write($entity);
                    $count++;
                }
            }

            $checkpoint->setPage($index + 1);
            $this->log(sprintf(
                '%s: batch %d/%d done, %d written',
                $this->entityName(),
                $index + 1,
                count($batches),
                $count,
            ));
        }

        $writer->close();
        $checkpoint->markDone();

        $this->log("{$this->entityName()}: {$count} written");

        return $count;
    }
}
