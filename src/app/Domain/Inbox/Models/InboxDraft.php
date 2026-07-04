<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Inbox\InboxDraftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InboxDraft — an unsent reply note, per-author (the ONE per-user Inbox entity,
 * contract §1). Model: fillable, casts, relations only; all queries live in
 * InboxDraftService.
 */
class InboxDraft extends Model
{
    /** @use HasFactory<InboxDraftFactory> */
    use HasFactory;

    protected static function newFactory(): InboxDraftFactory
    {
        return InboxDraftFactory::new();
    }

    protected $table = 'inbox_drafts';

    protected $fillable = [
        'user_id',
        'related_message_id',
        'subject',
        'body',
    ];

    // ---- Relations ----

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class, 'related_message_id');
    }
}
