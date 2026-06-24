<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\Domain\Onboarding\Enums\AssignmentStatus;
use App\Domain\Onboarding\Models\Certificate;
use App\Domain\Onboarding\Models\CourseAssignment;
use App\Http\Controllers\Controller;
use App\Http\Resources\Onboarding\CertificateResource;
use App\Jobs\Onboarding\GenerateCertificateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class CertificateController extends Controller
{
    /**
     * GET /api/onboarding/my-certificates
     *
     * Return all certificates for the currently authenticated student.
     * No Policy gate needed — always filtered to own assignments.
     */
    public function index(Request $request): JsonResponse
    {
        $certificates = Certificate::query()
            ->whereHas('assignment', function ($q) use ($request): void {
                $q->where('user_id', $request->user()->id);
            })
            ->with('assignment')
            ->orderByDesc('issued_at')
            ->get();

        return response()->json([
            'data' => CertificateResource::collection($certificates),
        ]);
    }

    /**
     * GET /api/onboarding/certificates/{assignment}
     * GET /api/admin/onboarding/certificates/{assignment}
     *
     * Show the certificate for a given assignment.
     * 404 if no certificate has been issued yet.
     */
    public function show(CourseAssignment $assignment): JsonResponse
    {
        $certificate = Certificate::where('assignment_id', $assignment->id)->first();

        if ($certificate === null) {
            return response()->json(['message' => 'Certificate not issued yet.'], 404);
        }

        Gate::authorize('view', $certificate);

        return response()->json([
            'data' => new CertificateResource($certificate),
        ]);
    }

    /**
     * GET /api/onboarding/certificates/{assignment}/download
     *
     * Stream the PDF file to the authenticated user.
     * 404 if no certificate, 404 if file missing from disk.
     */
    public function download(CourseAssignment $assignment): Response|JsonResponse
    {
        $certificate = Certificate::where('assignment_id', $assignment->id)->first();

        if ($certificate === null) {
            return response()->json(['message' => 'Certificate not issued yet.'], 404);
        }

        Gate::authorize('view', $certificate);

        $disk = Storage::disk('documents');

        if (! $disk->exists($certificate->pdf_path)) {
            return response()->json(['message' => 'Certificate file not found.'], 404);
        }

        $filename = 'certificate-'.$certificate->certificate_number.'.pdf';

        return response(
            (string) $disk->get($certificate->pdf_path),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.rawurlencode($filename).'"',
            ],
        );
    }

    /**
     * POST /api/admin/onboarding/certificates/{assignment}/regenerate
     *
     * Delete existing certificate (if any) and dispatch a new generation Job.
     * Returns 202 Accepted immediately.
     *
     * #8 fix: guard against regeneration for incomplete assignments — prevents burning
     * a sequence number (CERT-YYYY-NNNN) on an assignment that was never completed.
     */
    public function regenerate(CourseAssignment $assignment): JsonResponse
    {
        // Completion guard (#8): only completed assignments may have a certificate regenerated.
        if ($assignment->status !== AssignmentStatus::Completed) {
            return response()->json([
                'message' => 'Certificate can only be regenerated for completed assignments.',
            ], 422);
        }

        // The policy check is against Certificate model — create a dummy for the
        // gate check. Admin/director only can regenerate.
        // We check directly via role since the certificate may not exist yet.
        $user = request()->user();
        $existingCertificate = Certificate::where('assignment_id', $assignment->id)->first();

        if ($existingCertificate !== null) {
            Gate::authorize('regenerate', $existingCertificate);
            // Delete the existing certificate record (file on disk remains — see plan §Н).
            $existingCertificate->delete();
        } else {
            // No certificate yet — gate against a fresh instance (policy checks role only).
            Gate::authorize('viewAny', Certificate::class);
        }

        GenerateCertificateJob::dispatch($assignment->id);

        return response()->json(['message' => 'Certificate regeneration queued.'], 202);
    }
}
