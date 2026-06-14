<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Services;

use App\Domain\Onboarding\Models\Certificate;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Services\Helpers\CertificateHelper;
use App\Services\Documents\GotenbergClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

/**
 * CertificateService — orchestrates DOCX + PDF generation for a completed course.
 *
 * Pipeline (called from GenerateCertificateJob, NEVER from HTTP):
 *   1. Idempotency guard — return null if certificate already exists
 *   2. Reserve certificate number via CertificateNumberingService (SELECT FOR UPDATE)
 *   3. Load docx template from disk 'documents'
 *   4. PHPWord TemplateProcessor — fill placeholders
 *   5. Gotenberg officeToPdf → raw PDF bytes
 *   6. Save PDF to disk 'documents'
 *   7. Create Certificate record
 *   8. Return Certificate
 *
 * On Gotenberg failure: throws (Job retries up to 3 times).
 * On missing template: throws RuntimeException (Job retries → failed_jobs).
 */
class CertificateService
{
    public function __construct(
        private readonly CertificateNumberingService $numberingService,
        private readonly GotenbergClient $gotenbergClient,
    ) {}

    /**
     * Generate a PDF certificate for the given assignment.
     *
     * @return Certificate|null null if certificate already issued (idempotency)
     *
     * @throws RuntimeException when template is missing or Gotenberg fails
     * @throws ConnectionException when Gotenberg is unreachable
     */
    public function generate(CourseAssignment $assignment): ?Certificate
    {
        // 1. Idempotency guard — UNIQUE(assignment_id) on the DB level is the
        //    ultimate guard; this check prevents the expensive Gotenberg call.
        if (Certificate::where('assignment_id', $assignment->id)->exists()) {
            Log::info('CertificateService: certificate already exists, skipping', [
                'assignment_id' => $assignment->id,
            ]);

            return null;
        }

        // Eager-load relations needed for placeholders.
        $assignment->loadMissing(['user', 'course']);

        // 2. Reserve certificate number (SELECT FOR UPDATE in transaction).
        $year = (int) now()->format('Y');
        $number = $this->numberingService->nextNumber($year);

        // 3. Load docx template from disk 'documents'.
        $templateDiskPath = 'onboarding/templates/certificate.docx';
        $templateAbsPath = Storage::disk('documents')->path($templateDiskPath);

        if (! is_file($templateAbsPath)) {
            Log::error('CertificateService: template not found', [
                'assignment_id' => $assignment->id,
                'template_path' => $templateAbsPath,
            ]);
            throw new RuntimeException(
                "Certificate template not found at '{$templateDiskPath}'. "
                .'Run php artisan onboarding:publish-certificate-template to create it.'
            );
        }

        // 4. PHPWord TemplateProcessor — fill placeholders.
        $tempDocxPath = sys_get_temp_dir().'/mgcrm_cert_'.$assignment->id.'_'.uniqid().'.docx';

        try {
            $processor = new TemplateProcessor($templateAbsPath);

            $processor->setValue('learner_name', htmlspecialchars(
                $assignment->user->name ?? '',
                ENT_XML1 | ENT_COMPAT,
                'UTF-8'
            ));
            $processor->setValue('course_title', htmlspecialchars(
                $assignment->course->title ?? '',
                ENT_XML1 | ENT_COMPAT,
                'UTF-8'
            ));
            $processor->setValue('certificate_number', htmlspecialchars(
                $number,
                ENT_XML1 | ENT_COMPAT,
                'UTF-8'
            ));
            $processor->setValue('issued_date', htmlspecialchars(
                CertificateHelper::formatDate(now()),
                ENT_XML1 | ENT_COMPAT,
                'UTF-8'
            ));
            $processor->setValue('course_description', htmlspecialchars(
                $assignment->course->description ?? '',
                ENT_XML1 | ENT_COMPAT,
                'UTF-8'
            ));

            $processor->saveAs($tempDocxPath);
        } catch (\Throwable $e) {
            @unlink($tempDocxPath);
            Log::error('CertificateService: PHPWord render failed', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Certificate DOCX render failed: '.$e->getMessage(), 0, $e);
        }

        // 5. Gotenberg: convert docx → PDF.
        try {
            $pdfBytes = $this->gotenbergClient->officeToPdf($tempDocxPath);
        } catch (\Throwable $e) {
            @unlink($tempDocxPath);
            Log::error('CertificateService: Gotenberg failed', [
                'assignment_id' => $assignment->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        @unlink($tempDocxPath);

        // 6. Save PDF to disk 'documents'.
        $pdfDiskPath = "onboarding/certificates/{$assignment->id}/certificate.pdf";
        Storage::disk('documents')->put($pdfDiskPath, $pdfBytes);

        // 7. Create Certificate record.
        $certificate = Certificate::create([
            'assignment_id' => $assignment->id,
            'certificate_number' => $number,
            'issued_at' => now(),
            'pdf_path' => $pdfDiskPath,
        ]);

        Log::info('CertificateService: certificate issued', [
            'assignment_id' => $assignment->id,
            'certificate_number' => $number,
            'certificate_id' => $certificate->id,
        ]);

        return $certificate;
    }
}
