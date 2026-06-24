<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Contracts\Enums\AttachmentKind;
use App\Domain\Iam\Models\User;
use Database\Factories\Contracts\DocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentAttachment — file uploaded to a Document (disk: documents).
 *
 * kind = 'signed_scan' is the prerequisite for the Approved → Signed transition
 * (guard implemented in S2.5).
 *
 * Immutable: no updated_at. $timestamps = false with manual created_at.
 */
class DocumentAttachment extends Model
{
    /** @use HasFactory<DocumentAttachmentFactory> */
    use HasFactory;

    protected static function newFactory(): DocumentAttachmentFactory
    {
        return DocumentAttachmentFactory::new();
    }

    protected $table = 'document_attachments';

    public $timestamps = false;

    /** @var list<string> */
    protected $dates = ['created_at'];

    protected $fillable = [
        'document_id',
        'kind',
        'path',
        'original_name',
        'content_type',
        'size_bytes',
        'uploaded_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'created_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
