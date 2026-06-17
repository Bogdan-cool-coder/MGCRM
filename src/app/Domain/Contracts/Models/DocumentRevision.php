<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Contracts\DocumentRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentRevision — immutable snapshot created each time a Document is submitted.
 *
 * version_number is unique per document (1, 2, 3…).
 * context_snapshot is the full Document.context captured at submit time.
 * docx_path / pdf_path are null until S2.4 generates the file.
 *
 * Immutable: no updated_at. $timestamps = false with manual created_at.
 */
class DocumentRevision extends Model
{
    /** @use HasFactory<DocumentRevisionFactory> */
    use HasFactory;

    protected static function newFactory(): DocumentRevisionFactory
    {
        return DocumentRevisionFactory::new();
    }

    protected $table = 'document_revisions';

    public $timestamps = false;

    /** @var list<string> */
    protected $dates = ['created_at'];

    protected $fillable = [
        'document_id',
        'version_number',
        'attempt',
        'context_snapshot',
        'template_version',
        'docx_path',
        'pdf_path',
        'note',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'attempt' => 'integer',
            'context_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
