<?php

declare(strict_types=1);

namespace App\Domain\Migration\Loaders;

use Generator;
use RuntimeException;

/**
 * StagingReader — streams the EXTRACT-phase JSONL back off disk for transform /
 * load. Temporary migration bounded-context (dropped at M12).
 *
 * Mirrors StagingWriter: one record per line, read lazily so the ~110k events
 * never live in RAM all at once. Two access shapes:
 *   - stream(entity): a Generator yielding each decoded row (memory-flat).
 *   - indexByLead(entity): the per-lead rows grouped into an array keyed by the
 *     lead id, for the small-cardinality child entities (tasks/notes/events of a
 *     single deal are loaded together inside that deal's transaction). Callers
 *     that need the full grouping accept its memory cost knowingly; the load
 *     command builds it once per child entity and iterates deal-by-deal.
 */
final class StagingReader
{
    public function __construct(
        private readonly string $stagingDir,
    ) {}

    public static function fromConfig(): self
    {
        $relative = (string) config('amo_migration.api.staging_path', 'amo-migration');

        return new self(storage_path($relative));
    }

    public function exists(string $entity): bool
    {
        return is_file($this->path($entity));
    }

    /**
     * Lazily yield each decoded JSONL row for an entity.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(string $entity): Generator
    {
        $path = $this->path($entity);

        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("StagingReader: cannot open {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);

                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Count rows in an entity file (parity / progress).
     */
    public function count(string $entity): int
    {
        $count = 0;

        foreach ($this->stream($entity) as $_) {
            $count++;
        }

        return $count;
    }

    /**
     * Group an entity's rows by their parent AMO lead id. Used for tasks / notes
     * / events so each deal's children load inside that deal's transaction.
     *
     * @param  callable(array<string, mixed>): ?int  $leadIdOf  Extracts the lead id from a row.
     * @return array<int, list<array<string, mixed>>>
     */
    public function indexByLead(string $entity, callable $leadIdOf): array
    {
        $index = [];

        foreach ($this->stream($entity) as $row) {
            $leadId = $leadIdOf($row);

            if ($leadId !== null) {
                $index[$leadId][] = $row;
            }
        }

        return $index;
    }

    /**
     * Build a lookup of AMO contacts / companies keyed by their own id, so a
     * deal's _embedded stubs can be enriched with the full extracted record.
     *
     * @return array<int, array<string, mixed>>
     */
    public function keyById(string $entity): array
    {
        $byId = [];

        foreach ($this->stream($entity) as $row) {
            if (isset($row['id'])) {
                $byId[(int) $row['id']] = $row;
            }
        }

        return $byId;
    }

    private function path(string $entity): string
    {
        return $this->stagingDir.'/'.$entity.'.jsonl';
    }
}
