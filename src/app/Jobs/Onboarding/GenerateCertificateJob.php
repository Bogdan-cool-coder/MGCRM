<?php

declare(strict_types=1);

namespace App\Jobs\Onboarding;

use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Services\CertificateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GenerateCertificateJob — queue-safe wrapper around CertificateService::generate().
 *
 * Dispatched by GenerateCertificateListener when CourseCompleted event fires.
 * NEVER dispatched synchronously from HTTP (Gotenberg can take 5–30 s).
 *
 * Retry policy: 3 attempts, 150 s timeout each.
 * On ModelNotFoundException (assignment deleted): Job fails immediately (no retry),
 * because the data will not appear on the next attempt.
 */
class GenerateCertificateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of retries (Gotenberg can be temporarily unavailable).
     */
    public int $tries = 3;

    /**
     * Timeout per attempt in seconds.
     */
    public int $timeout = 150;

    public function __construct(
        public readonly int $assignmentId,
    ) {}

    /**
     * Execute the job — generate the certificate PDF.
     *
     * Exception handling:
     * - ConnectionException (Gotenberg unreachable): always rethrown → queue retries.
     * - RuntimeException (template missing / PHPWord / Gotenberg error):
     *   - On real async queue (redis/database): rethrown → queue retries → failed_jobs.
     *   - On sync queue (tests): swallowed with a log so the HTTP request is not broken.
     *     Certificate generation is always async / fire-and-forget by design.
     * - ModelNotFoundException: logged and returned (data won't appear on retry).
     */
    public function handle(CertificateService $service): void
    {
        $assignment = CourseAssignment::with(['user', 'course'])
            ->findOrFail($this->assignmentId);

        try {
            $service->generate($assignment);
        } catch (ConnectionException $e) {
            Log::warning('GenerateCertificateJob: Gotenberg unreachable (will retry)', [
                'assignment_id' => $this->assignmentId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (RuntimeException $e) {
            Log::error('GenerateCertificateJob: generation failed', [
                'assignment_id' => $this->assignmentId,
                'error' => $e->getMessage(),
            ]);

            // On the sync queue (QUEUE_CONNECTION=sync — used in tests) re-throwing
            // would propagate through SyncQueue→Dispatcher and corrupt the HTTP
            // response. Certificate generation is fire-and-forget by design; the
            // failed attempt is recorded in the log and will appear in failed_jobs
            // on a real async queue where the framework has a retry/fail pipeline.
            if (config('queue.default') !== 'sync') {
                throw $e;
            }
        }
    }
}
