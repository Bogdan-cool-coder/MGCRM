<?php

declare(strict_types=1);

namespace App\Domain\Migration\Services;

/**
 * Checkpoint — tiny JSON resume-state file for one extractor.
 *
 * Temporary migration bounded-context (dropped at M12). Stores the cursor an
 * extractor needs to skip already-done work on --resume: a `done` flag, the
 * last completed page, and the set of already-processed entity ids (for the
 * per-lead extractors: events / notes). Written after each unit of progress so
 * a crash mid-run loses at most one page/lead.
 */
class Checkpoint
{
    /** @var array<string, mixed> */
    private array $state;

    public function __construct(private readonly string $path)
    {
        $this->state = $this->load();
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        if (! is_file($this->path)) {
            return ['done' => false, 'page' => 0, 'processed_ids' => []];
        }

        $raw = file_get_contents($this->path);

        if ($raw === false || $raw === '') {
            return ['done' => false, 'page' => 0, 'processed_ids' => []];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return ['done' => false, 'page' => 0, 'processed_ids' => []];
        }

        return $decoded + ['done' => false, 'page' => 0, 'processed_ids' => []];
    }

    public function isDone(): bool
    {
        return (bool) ($this->state['done'] ?? false);
    }

    public function markDone(): void
    {
        $this->state['done'] = true;
        $this->persist();
    }

    public function page(): int
    {
        return (int) ($this->state['page'] ?? 0);
    }

    public function setPage(int $page): void
    {
        $this->state['page'] = $page;
        $this->persist();
    }

    public function isProcessed(int|string $id): bool
    {
        return in_array((string) $id, $this->processedIds(), true);
    }

    public function markProcessed(int|string $id): void
    {
        $ids = $this->processedIds();

        if (! in_array((string) $id, $ids, true)) {
            $ids[] = (string) $id;
            $this->state['processed_ids'] = $ids;
            $this->persist();
        }
    }

    /**
     * @return list<string>
     */
    private function processedIds(): array
    {
        return array_values(array_map('strval', (array) ($this->state['processed_ids'] ?? [])));
    }

    /**
     * Reset the checkpoint (used when --resume is NOT passed: fresh run).
     */
    public function reset(): void
    {
        $this->state = ['done' => false, 'page' => 0, 'processed_ids' => []];

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private function persist(): void
    {
        $dir = dirname($this->path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $this->path,
            (string) json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }
}
