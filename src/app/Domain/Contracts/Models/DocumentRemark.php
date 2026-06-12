<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentRemark — an approver's remark during the review cycle.
 *
 * Table and model created in S2.2; service-level logic (creation on
 * reject/needs_rework, marking is_resolved) is implemented in S2.5.
 *
 * attempt and stage_order link the remark to a specific approval round
 * and ApprovalRoute stage.
 */
class DocumentRemark extends Model
{
    protected $table = 'document_remarks';

    protected $fillable = [
        'document_id',
        'attempt',
        'stage_order',
        'author_user_id',
        'text',
        'is_resolved',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'stage_order' => 'integer',
            'is_resolved' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
