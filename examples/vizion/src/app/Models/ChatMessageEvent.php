<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable streaming event of an assistant ChatMessage. Append-only — no
 * updated_at, no edits expected. See create_chat_message_events_table migration
 * for the schema rationale.
 */
class ChatMessageEvent extends Model
{
    use HasFactory;


    /**
     * Known event types. Documented for callers (M4 ChatEventEmitter); not
     * enforced at the DB or model layer to keep room for future types without
     * a migration.
     */
    public const TYPE_STARTED        = 'started';
    public const TYPE_THINKING       = 'thinking';
    public const TYPE_TOOL_CALL      = 'tool_call';
    public const TYPE_TOOL_RESULT    = 'tool_result';
    public const TYPE_DRY_RUN_START  = 'dry_run_start';
    public const TYPE_DRY_RUN_RESULT = 'dry_run_result';
    public const TYPE_RETRY          = 'retry';
    public const TYPE_TEXT_DELTA     = 'text_delta';
    public const TYPE_FINAL_MESSAGE  = 'final_message';
    public const TYPE_ERROR          = 'error';

    /**
     * Widget-generation two-step flow: the AI proposes 2-4 candidate widget
     * configs (different chart types / groupings / metrics) instead of creating
     * one immediately. The frontend renders each variant as a preview card
     * (via POST /api/widgets/preview) and the user picks one; only then does a
     * follow-up turn call create_widget. Payload carries the numbered variants
     * (label + validated config). See WidgetTool::proposeWidgetVariantsTool().
     */
    public const TYPE_WIDGET_VARIANTS = 'widget_variants';

    /**
     * Document-template two-step flow (M7): for an uploaded .docx the AI reads
     * the document text + its ${placeholders} and proposes a placeholder→field
     * mapping (one suggested MacroData / field-catalog field per token) instead
     * of mapping silently. The frontend (M8) renders the proposed mapping as
     * confirmable cards (mirror of widget_variants). Payload carries the
     * validated placeholder list. See DocumentTool::proposeDocumentFieldsTool().
     */
    public const TYPE_DOCUMENT_FIELDS_PROPOSED = 'document_fields_proposed';

    /**
     * No updated_at — events are immutable once written. Eloquent still manages
     * created_at via the const below.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'chat_message_id',
        'sequence',
        'type',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'sequence'   => 'integer',
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }
}
