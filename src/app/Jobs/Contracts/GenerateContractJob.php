<?php

declare(strict_types=1);

namespace App\Jobs\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ContractGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GenerateContractJob — queue-safe wrapper around ContractGenerationService.
 *
 * NOT dispatched from HTTP endpoints (sync path handles single-document
 * generation via HTTP with timeout ~5–15 s).
 *
 * This Job is reserved for future bulk generation scenarios where many
 * documents need to be generated in parallel without blocking HTTP workers.
 *
 * Usage (future bulk path):
 *   GenerateContractJob::dispatch($document->id, $userId);
 */
class GenerateContractJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of retries (Gotenberg can be temporarily unavailable).
     */
    public int $tries = 3;

    /**
     * Timeout per attempt in seconds (Gotenberg may take up to 120 s for large docs).
     */
    public int $timeout = 150;

    public function __construct(
        public readonly int $documentId,
        public readonly int $userId,
    ) {}

    /**
     * Execute the generation job.
     */
    public function handle(ContractGenerationService $service): void
    {
        $doc = Document::query()->findOrFail($this->documentId);

        try {
            $service->generate($doc, $this->userId);
        } catch (\Throwable $e) {
            Log::error('GenerateContractJob failed', [
                'document_id' => $this->documentId,
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
