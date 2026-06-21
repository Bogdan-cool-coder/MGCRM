<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

use App\Domain\Migration\Services\AmoClient;
use App\Domain\Migration\Services\Checkpoint;
use App\Domain\Migration\Services\StagingWriter;

/**
 * AbstractExtractor — shared plumbing for the AMO EXTRACT phase.
 *
 * Temporary migration bounded-context (dropped at M12). Concrete extractors
 * override entityName() (the JSONL/checkpoint basename) and run() (the actual
 * extraction). The base resolves staging paths, builds the writer/checkpoint,
 * and exposes a progress callback so the command can log live.
 *
 * --limit (int|null) caps the number of records written (smoke runs / samples).
 * --resume reuses the checkpoint and appends; a fresh run truncates + resets.
 */
abstract class AbstractExtractor
{
    /** @var callable(string): void */
    protected $progress;

    protected int $limit = 0;

    protected bool $resume = false;

    public function __construct(
        protected readonly AmoClient $client,
    ) {
        $this->progress = static function (string $_): void {};
    }

    /** Basename used for <name>.jsonl and <name>.ckpt.json. */
    abstract public function entityName(): string;

    /**
     * Run the extraction. Returns the number of records written this run.
     */
    abstract public function run(): int;

    /**
     * @param  callable(string): void  $progress
     */
    public function withProgress(callable $progress): static
    {
        $this->progress = $progress;

        return $this;
    }

    public function withLimit(?int $limit): static
    {
        $this->limit = $limit !== null && $limit > 0 ? $limit : 0;

        return $this;
    }

    public function withResume(bool $resume): static
    {
        $this->resume = $resume;

        return $this;
    }

    protected function log(string $message): void
    {
        ($this->progress)($message);
    }

    protected function stagingDir(): string
    {
        $relative = (string) config('amo_migration.api.staging_path', 'amo-migration');

        return storage_path($relative);
    }

    protected function jsonlPath(?string $name = null): string
    {
        return $this->stagingDir().'/'.($name ?? $this->entityName()).'.jsonl';
    }

    protected function checkpointPath(?string $name = null): string
    {
        return $this->stagingDir().'/'.($name ?? $this->entityName()).'.ckpt.json';
    }

    protected function makeWriter(?string $name = null): StagingWriter
    {
        // --resume appends to an existing file; a fresh run truncates it.
        return new StagingWriter($this->jsonlPath($name), append: $this->resume);
    }

    protected function makeCheckpoint(?string $name = null): Checkpoint
    {
        $checkpoint = new Checkpoint($this->checkpointPath($name));

        if (! $this->resume) {
            $checkpoint->reset();
        }

        return $checkpoint;
    }

    /**
     * Read the deduplicated id list this extractor depends on (collected by an
     * earlier extractor and written to a sidecar <name>.ids.json file).
     *
     * @return list<int>
     */
    protected function readSidecarIds(string $name): array
    {
        $path = $this->stagingDir().'/'.$name.'.ids.json';

        if (! is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return [];
        }

        /** @var list<int>|null $ids */
        $ids = json_decode($raw, true);

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * Persist a deduplicated id list for downstream extractors.
     *
     * @param  list<int>  $ids
     */
    protected function writeSidecarIds(string $name, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $dir = $this->stagingDir();

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $dir.'/'.$name.'.ids.json',
            (string) json_encode($ids, JSON_UNESCAPED_SLASHES),
        );
    }
}
