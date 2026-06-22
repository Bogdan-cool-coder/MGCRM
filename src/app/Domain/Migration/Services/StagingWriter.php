<?php

declare(strict_types=1);

namespace App\Domain\Migration\Services;

use RuntimeException;

/**
 * StagingWriter — append-only JSONL file sink for the EXTRACT phase.
 *
 * Temporary migration bounded-context (dropped at M12). One instance owns one
 * open file handle for one entity (leads.jsonl, contacts.jsonl, …). Each record
 * is written as a single JSON line (one AMO object per line) so transform/load
 * can stream the file back line-by-line without loading ~110k events into RAM.
 *
 * Checkpoint companion file (<entity>.ckpt.json) stores resume state (last page
 * / last processed id) so a 40–55-minute run survives a crash via --resume.
 */
class StagingWriter
{
    /** @var resource|null */
    private $handle = null;

    private int $written = 0;

    public function __construct(
        private readonly string $path,
        private readonly bool $append = false,
    ) {
        $dir = dirname($this->path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("StagingWriter: cannot create staging dir {$dir}");
        }

        $mode = $this->append ? 'a' : 'w';
        $handle = fopen($this->path, $mode);

        if ($handle === false) {
            throw new RuntimeException("StagingWriter: cannot open {$this->path} for writing");
        }

        $this->handle = $handle;
    }

    /**
     * Write one record as a JSON line.
     *
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('StagingWriter: handle already closed');
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            throw new RuntimeException('StagingWriter: failed to encode record to JSON');
        }

        fwrite($this->handle, $line."\n");
        $this->written++;
    }

    public function written(): int
    {
        return $this->written;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
