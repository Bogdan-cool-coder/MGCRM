<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Contracts\Enums\ApprovalDecision;
use App\Domain\Iam\Models\User;
use Database\Factories\Contracts\ApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Approval — single approver vote on a document for a specific attempt + stage.
 *
 * Immutable after decided_at is set.
 * UNIQUE(document_id, attempt, stage_order, user_id) enforced at DB level.
 *
 * No updated_at — record is immutable once decided.
 */
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    const UPDATED_AT = null; // no updated_at column

    protected static function newFactory(): ApprovalFactory
    {
        return ApprovalFactory::new();
    }

    protected $table = 'approvals';

    protected $fillable = [
        'document_id',
        'attempt',
        'stage_order',
        'user_id',
        'decision',
        'comment',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => ApprovalDecision::class,
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
