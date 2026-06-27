<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contracts;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Contracts\Models\Approval;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Services\ApprovalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contracts\DecideDocumentRequest;
use App\Http\Requests\Contracts\SubmitDocumentRequest;
use App\Http\Resources\Contracts\ApprovalResource;
use App\Http\Resources\Contracts\ApprovalSummaryResource;
use App\Http\Resources\Contracts\DocumentResource;
use App\Http\Resources\Contracts\MyApprovalResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentApprovalController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvalService,
    ) {}

    /**
     * POST /api/documents/{document}/submit
     *
     * Replaces the raw DocumentService::transition stub from S2.2.
     * Handles both Draft→Submitted and NeedsRework→Submitted (resubmit).
     * External signature unchanged — single entry point for both cases.
     */
    public function submit(SubmitDocumentRequest $request, Document $document): JsonResource
    {
        $this->authorize('submit', $document);

        $updated = $this->approvalService->submit(
            $document,
            $request->user(),
            $request->validated('note'),
        );

        return DocumentResource::make($updated);
    }

    /**
     * POST /api/documents/{document}/decide
     *
     * Record an approver's vote: approved / rejected / needs_rework.
     */
    public function decide(DecideDocumentRequest $request, Document $document): JsonResource
    {
        $this->authorize('decide', $document);

        $decision = ApprovalDecision::from($request->validated('decision'));

        $updated = $this->approvalService->decide(
            $document,
            $request->user(),
            $decision,
            $request->validated('comment'),
        );

        return DocumentResource::make($updated);
    }

    /**
     * GET /api/documents/{document}/approval-summary
     *
     * Returns current approval progress: stages, votes, counts.
     * Passes the authenticated user so the resource can compute is_current_user_approver.
     */
    public function approvalSummary(Request $request, Document $document): ApprovalSummaryResource
    {
        $this->authorize('approvalSummary', $document);

        $progress = $this->approvalService->getProgress($document);

        return new ApprovalSummaryResource($progress, $request->user());
    }

    /**
     * GET /api/approvals/my
     *
     * Authenticated user's own approvals, optionally filtered by ?status=pending|decided.
     * 'decided' maps to all non-pending decisions (approved / rejected / needs_rework).
     */
    public function myApprovals(Request $request): AnonymousResourceCollection
    {
        $query = Approval::query()
            ->where('user_id', $request->user()->id)
            ->with([
                'user:id,full_name',
                'document:id,title,status,kind,number,source_company_id',
                'document.sourceCompany:id,name',
            ])
            ->orderByDesc('created_at');

        $status = $request->query('status');
        if ($status === 'pending') {
            $query->where('decision', ApprovalDecision::Pending->value);
        } elseif ($status === 'decided') {
            $query->whereIn('decision', [
                ApprovalDecision::Approved->value,
                ApprovalDecision::Rejected->value,
                ApprovalDecision::NeedsRework->value,
            ]);
        }

        return MyApprovalResource::collection($query->paginate(25));
    }

    /**
     * GET /api/approvals/{approval}
     *
     * Single approval record. Accessible to the assigned approver, admin, and lawyer.
     */
    public function showApproval(Request $request, Approval $approval): ApprovalResource
    {
        $this->authorize('view', $approval);

        return ApprovalResource::make($approval->load('user:id,full_name'));
    }
}
