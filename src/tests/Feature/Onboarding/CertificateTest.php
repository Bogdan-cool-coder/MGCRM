<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Models\Certificate;
use App\Domain\Onboarding\Models\Course;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Domain\Onboarding\Models\CourseModule;
use App\Domain\Onboarding\Models\Lesson;
use App\Domain\Onboarding\Models\LessonProgress;
use App\Domain\Onboarding\Services\CertificateNumberingService;
use App\Domain\Onboarding\Services\CertificateService;
use App\Domain\Onboarding\Services\ProgressService;
use App\Jobs\Onboarding\GenerateCertificateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private CourseAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents');

        $this->admin = User::factory()->create(['role' => Role::Admin]);
        $this->student = User::factory()->create(['role' => Role::Manager]);

        $course = Course::factory()->create(['is_published' => true]);
        $this->assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $this->student->id,
            'status' => AssignmentStatus::Completed,
            'completed_at' => now(),
        ]);

        // Create a minimal valid docx template on the fake disk.
        $this->publishDemoTemplate();
    }

    // =========================================================================
    // Group 1: CertificateService — generate() + idempotency
    // =========================================================================

    public function test_job_creates_certificate_record(): void
    {
        $this->fakeGotenberg();

        $service = app(CertificateService::class);
        $certificate = $service->generate($this->assignment);

        $this->assertNotNull($certificate);
        $this->assertDatabaseHas('certificates', [
            'assignment_id' => $this->assignment->id,
        ]);
    }

    public function test_certificate_number_format(): void
    {
        $this->fakeGotenberg();

        $service = app(CertificateService::class);
        $certificate = $service->generate($this->assignment);

        $this->assertNotNull($certificate);
        $this->assertMatchesRegularExpression('/^CERT-\d{4}-\d{4}$/', $certificate->certificate_number);
    }

    public function test_certificate_number_increments(): void
    {
        $this->fakeGotenberg();

        $student2 = User::factory()->create(['role' => Role::Manager]);
        $course2 = Course::factory()->create(['is_published' => true]);
        $assignment2 = CourseAssignment::factory()->create([
            'course_id' => $course2->id,
            'user_id' => $student2->id,
            'status' => AssignmentStatus::Completed,
        ]);

        $service = app(CertificateService::class);

        $cert1 = $service->generate($this->assignment);
        $cert2 = $service->generate($assignment2);

        $this->assertNotNull($cert1);
        $this->assertNotNull($cert2);

        $year = now()->format('Y');
        $this->assertEquals("CERT-{$year}-0001", $cert1->certificate_number);
        $this->assertEquals("CERT-{$year}-0002", $cert2->certificate_number);
    }

    public function test_new_year_resets_sequence(): void
    {
        $numberingService = app(CertificateNumberingService::class);

        $num2025 = $numberingService->nextNumber(2025);
        $num2026 = $numberingService->nextNumber(2026);

        $this->assertEquals('CERT-2025-0001', $num2025);
        $this->assertEquals('CERT-2026-0001', $num2026);
    }

    public function test_second_job_does_not_create_duplicate(): void
    {
        $this->fakeGotenberg();

        $service = app(CertificateService::class);

        $cert1 = $service->generate($this->assignment);
        $cert2 = $service->generate($this->assignment); // idempotency guard

        $this->assertNotNull($cert1);
        $this->assertNull($cert2); // silent return

        $this->assertDatabaseCount('certificates', 1);
    }

    // =========================================================================
    // Group 2: Job dispatched from Listener on CourseCompleted
    // =========================================================================

    public function test_job_dispatched_on_course_completion(): void
    {
        Queue::fake();

        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => 'text',
            'is_published' => true,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $this->student->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        LessonProgress::factory()->completed()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
        ]);

        $progressService = app(ProgressService::class);
        $progressService->checkAndComplete($assignment);

        Queue::assertPushed(GenerateCertificateJob::class, function (GenerateCertificateJob $job) use ($assignment): bool {
            return $job->assignmentId === $assignment->id;
        });
    }

    public function test_listener_dispatches_job_only_once_per_completion(): void
    {
        Queue::fake();

        $course = Course::factory()->create(['is_published' => true]);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'module_id' => $module->id,
            'kind' => 'text',
            'is_published' => true,
        ]);

        $assignment = CourseAssignment::factory()->create([
            'course_id' => $course->id,
            'user_id' => $this->student->id,
            'status' => AssignmentStatus::InProgress,
        ]);

        LessonProgress::factory()->completed()->create([
            'assignment_id' => $assignment->id,
            'lesson_id' => $lesson->id,
        ]);

        $progressService = app(ProgressService::class);

        // First call — should complete and dispatch Job.
        $progressService->checkAndComplete($assignment);
        $assignment->refresh();

        // Second call — already completed, should be no-op (no extra Job).
        $progressService->checkAndComplete($assignment);

        Queue::assertPushed(GenerateCertificateJob::class, 1);
    }

    // =========================================================================
    // Group 3: Download endpoint
    // =========================================================================

    public function test_student_can_download_own_certificate(): void
    {
        $pdfContent = '%PDF-1.4 test certificate content';
        $pdfPath = "onboarding/certificates/{$this->assignment->id}/certificate.pdf";
        Storage::disk('documents')->put($pdfPath, $pdfContent);

        $certificate = Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
            'pdf_path' => $pdfPath,
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->getJson("/api/onboarding/certificates/{$this->assignment->id}/download");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_student_cannot_download_others_certificate(): void
    {
        $otherStudent = User::factory()->create(['role' => Role::Manager]);
        $otherCourse = Course::factory()->create();
        $otherAssignment = CourseAssignment::factory()->create([
            'course_id' => $otherCourse->id,
            'user_id' => $otherStudent->id,
            'status' => AssignmentStatus::Completed,
        ]);

        $pdfPath = "onboarding/certificates/{$otherAssignment->id}/certificate.pdf";
        Storage::disk('documents')->put($pdfPath, '%PDF-1.4 other');

        Certificate::factory()->create([
            'assignment_id' => $otherAssignment->id,
            'pdf_path' => $pdfPath,
        ]);

        // student (not owner) tries to download
        Sanctum::actingAs($this->student, ['*']);

        $this->getJson("/api/onboarding/certificates/{$otherAssignment->id}/download")
            ->assertForbidden();
    }

    public function test_admin_can_download_any_certificate(): void
    {
        $pdfPath = "onboarding/certificates/{$this->assignment->id}/certificate.pdf";
        Storage::disk('documents')->put($pdfPath, '%PDF-1.4 admin download');

        Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
            'pdf_path' => $pdfPath,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson("/api/onboarding/certificates/{$this->assignment->id}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_download_404_when_certificate_not_issued(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->getJson("/api/onboarding/certificates/{$this->assignment->id}/download")
            ->assertNotFound();
    }

    public function test_download_404_when_file_missing_from_disk(): void
    {
        // Certificate record exists but file is gone from disk.
        Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
            'pdf_path' => 'onboarding/certificates/999/certificate.pdf', // does not exist on fake disk
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->getJson("/api/onboarding/certificates/{$this->assignment->id}/download")
            ->assertNotFound();
    }

    // =========================================================================
    // Group 4: Show and List
    // =========================================================================

    public function test_student_sees_own_certificate(): void
    {
        Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
            'certificate_number' => 'CERT-2026-0001',
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->getJson("/api/onboarding/certificates/{$this->assignment->id}")
            ->assertOk()
            ->assertJsonPath('data.certificate_number', 'CERT-2026-0001');
    }

    public function test_student_gets_404_when_no_certificate(): void
    {
        Sanctum::actingAs($this->student, ['*']);

        $this->getJson("/api/onboarding/certificates/{$this->assignment->id}")
            ->assertNotFound();
    }

    public function test_admin_sees_any_certificate(): void
    {
        Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
            'certificate_number' => 'CERT-2026-0009',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson("/api/admin/onboarding/certificates/{$this->assignment->id}")
            ->assertOk()
            ->assertJsonPath('data.certificate_number', 'CERT-2026-0009');
    }

    public function test_my_certificates_returns_only_own(): void
    {
        $studentB = User::factory()->create(['role' => Role::Manager]);
        $courseB = Course::factory()->create();
        $assignmentB = CourseAssignment::factory()->create([
            'course_id' => $courseB->id,
            'user_id' => $studentB->id,
            'status' => AssignmentStatus::Completed,
        ]);

        // Certificate for student A (this->student)
        Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
            'certificate_number' => 'CERT-2026-0001',
        ]);

        // Certificate for student B
        Certificate::factory()->create([
            'assignment_id' => $assignmentB->id,
            'certificate_number' => 'CERT-2026-0002',
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $response = $this->getJson('/api/onboarding/my-certificates')
            ->assertOk();

        $numbers = collect($response->json('data'))->pluck('certificate_number')->all();
        $this->assertContains('CERT-2026-0001', $numbers);
        $this->assertNotContains('CERT-2026-0002', $numbers);
        $this->assertCount(1, $numbers);
    }

    // =========================================================================
    // Group 5: Regenerate (admin only)
    // =========================================================================

    public function test_admin_can_regenerate_certificate(): void
    {
        Queue::fake();

        Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/certificates/{$this->assignment->id}/regenerate")
            ->assertStatus(202);

        Queue::assertPushed(GenerateCertificateJob::class, function (GenerateCertificateJob $job): bool {
            return $job->assignmentId === $this->assignment->id;
        });
    }

    public function test_regenerate_deletes_existing_certificate(): void
    {
        Queue::fake();

        $cert = Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
            'certificate_number' => 'CERT-2026-0001',
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/admin/onboarding/certificates/{$this->assignment->id}/regenerate")
            ->assertStatus(202);

        $this->assertDatabaseMissing('certificates', ['id' => $cert->id]);
        Queue::assertPushed(GenerateCertificateJob::class);
    }

    public function test_student_cannot_regenerate(): void
    {
        Certificate::factory()->create([
            'assignment_id' => $this->assignment->id,
        ]);

        Sanctum::actingAs($this->student, ['*']);

        $this->postJson("/api/admin/onboarding/certificates/{$this->assignment->id}/regenerate")
            ->assertForbidden();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Register Http::fake for Gotenberg (office/libreoffice convert).
     */
    private function fakeGotenberg(): void
    {
        Http::fake([
            '*forms/libreoffice/convert*' => Http::response(
                '%PDF-1.4 test certificate content',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);
    }

    /**
     * Create a minimal valid docx template on the fake documents disk.
     * PHPWord TemplateProcessor requires a real Word2007 file.
     */
    private function publishDemoTemplate(): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('${learner_name} ${course_title} ${certificate_number} ${issued_date} ${course_description}');

        $tempPath = sys_get_temp_dir().'/mgcrm_test_cert_template_'.uniqid().'.docx';

        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tempPath);

            Storage::disk('documents')->put(
                'onboarding/templates/certificate.docx',
                (string) file_get_contents($tempPath)
            );
        } finally {
            @unlink($tempPath);
        }
    }
}
