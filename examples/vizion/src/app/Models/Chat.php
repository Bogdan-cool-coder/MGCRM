<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    /**
     * Allowed values for `scope_type` — the UI scope of the chat. Validated at
     * the model layer (no DB enum). Distinct from `type` (which gates the LLM
     * prompt cascade: report_generation vs quick_qa).
     *
     *  - 'report'    — chat is anchored to a specific report (mini-chat opened
     *                  from a report page, or a full-screen report_generation
     *                  thread that produced a report).
     *  - 'general'   — chat has no report anchor (general analytics question
     *                  from the chat page or mini-chat outside a report).
     *  - 'dashboard' — mini-chat (quick_qa) opened on a dashboard page; anchored
     *                  to a dashboard_id, context = configs of its widgets.
     *  - 'document'  — document_template chat (DocumentGenerationModal or the
     *                  document-page mini-chat); anchored to a document_id, the
     *                  DocumentTemplate the AI is authoring / mapping fields for.
     */
    public const SCOPE_REPORT    = 'report';
    public const SCOPE_GENERAL   = 'general';
    public const SCOPE_DASHBOARD = 'dashboard';
    public const SCOPE_DOCUMENT  = 'document';

    public const SCOPES = [
        self::SCOPE_REPORT,
        self::SCOPE_GENERAL,
        self::SCOPE_DASHBOARD,
        self::SCOPE_DOCUMENT,
    ];

    protected $fillable = [
        'user_id',
        'company_id',
        'type',
        'scope_type',
        'title',
        'report_id',
        'widget_id',
        'dashboard_id',
        'document_id',
        'ai_context',
    ];

    protected function casts(): array
    {
        return [
            'ai_context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * The widget a widget_generation chat is anchored to (chats.widget_id),
     * mirroring report() for report_generation chats.
     */
    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class);
    }

    /**
     * The dashboard a scope=dashboard mini-chat is anchored to
     * (chats.dashboard_id).
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /**
     * The document template a document_template chat is anchored to
     * (chats.document_id), mirroring report() / widget() for their chat types.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_id');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Narrow to a specific UI scope, optionally anchored to a target entity:
     *  - scope 'report'    + $reportId    => constrain to that report.
     *  - scope 'dashboard' + $dashboardId => constrain to that dashboard.
     *  - scope 'document'  + $documentId  => constrain to that document template.
     *  - scope 'general'                  => scope type only (no anchor).
     *
     * Anchors are only applied for their matching scope, so a stray
     * $dashboardId on a 'report' scope (or vice versa) is ignored. Used by the
     * mini-chat dropdown and the resume endpoint.
     */
    public function scopeForScope(
        $query,
        string $scopeType,
        ?int $reportId = null,
        ?int $dashboardId = null,
        ?int $documentId = null
    ) {
        $query->where('scope_type', $scopeType);

        if ($scopeType === self::SCOPE_REPORT && $reportId !== null) {
            $query->where('report_id', $reportId);
        }

        if ($scopeType === self::SCOPE_DASHBOARD && $dashboardId !== null) {
            $query->where('dashboard_id', $dashboardId);
        }

        if ($scopeType === self::SCOPE_DOCUMENT && $documentId !== null) {
            $query->where('document_id', $documentId);
        }

        return $query;
    }
}
