<?php

declare(strict_types=1);

namespace App\Jobs\Contracts;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Domain\Contracts\Services\TemplateCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * CheckTemplateJob — run AI review for a template version.
 *
 * Dispatched by TemplateService::createVersion() and by the
 * POST /api/templates/{template}/versions/{version}/check endpoint.
 *
 * tries=1: AI cascade already retries internally (AiRetryService, 3 attempts).
 * Double retry (job-level + cascade) is intentional to avoid redundancy.
 * Re-run by the lawyer via POST /check if needed.
 *
 * timeout=600: queue worker must be started with --timeout=600 --tries=1
 * and config/queue.php retry_after=660.
 *
 * Any Throwable → status=failed + error remark. Job does NOT re-throw
 * (no failed-queue entry, no alert noise for expected transient failures).
 */
class CheckTemplateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Queue worker SIGTERM timeout (seconds) */
    public int $timeout = 600;

    /** @var int One attempt only — cascade handles retries internally */
    public int $tries = 1;

    public function __construct(private readonly int $versionId)
    {
        $this->onQueue('default');
    }

    public function handle(TemplateCheckService $checkService): void
    {
        $version = TemplateVersion::findOrFail($this->versionId);

        $version->update(['ai_check_status' => AiCheckStatus::Checking]);

        try {
            $result = $checkService->check($version);

            $version->update([
                'ai_check_status' => AiCheckStatus::Checked,
                'ai_checked_at' => now(),
                'ai_remarks' => $result['remarks'],
                'pdf_ok' => $result['pdf_ok'],
            ]);
        } catch (\Throwable $e) {
            Log::error('CheckTemplateJob failed', [
                'version_id' => $this->versionId,
                'error' => $e->getMessage(),
            ]);

            $version->update([
                'ai_check_status' => AiCheckStatus::Failed,
                'ai_checked_at' => now(),
                'ai_remarks' => [[
                    'type' => 'system_error',
                    'severity' => 'error',
                    'text' => 'Проверка не выполнена: '.$e->getMessage(),
                ]],
                'pdf_ok' => null,
            ]);
            // Intentionally NOT re-throwing — job must not enter the failed queue.
            // The lawyer can retry via POST /check.
        }
    }
}
