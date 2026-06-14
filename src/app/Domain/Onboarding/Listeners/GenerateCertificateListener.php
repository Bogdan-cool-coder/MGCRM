<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Listeners;

use App\Domain\Onboarding\Events\CourseCompleted;
use App\Jobs\Onboarding\GenerateCertificateJob;

/**
 * GenerateCertificateListener (S3.6) — on CourseCompleted, dispatch the
 * certificate generation Job to the queue.
 *
 * Synchronous listener: it only dispatches a queued Job (no network here),
 * so the web request that triggered the course completion is not blocked
 * by Gotenberg I/O. Registered in AppServiceProvider::boot via Event::listen.
 *
 * Idempotency: if CourseCompleted fires again (edge case), the Job's
 * CertificateService::generate() will detect the existing Certificate and
 * return null silently. The UNIQUE(assignment_id) DB constraint is the
 * ultimate guard.
 */
class GenerateCertificateListener
{
    public function handle(CourseCompleted $event): void
    {
        GenerateCertificateJob::dispatch($event->assignment->id);
    }
}
