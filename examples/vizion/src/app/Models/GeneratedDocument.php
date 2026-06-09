<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GeneratedDocument — one render of a DocumentTemplate against a concrete object,
 * produced asynchronously by GenerateDocumentJob. Tracks status (pending ->
 * processing -> done|error) and the resulting file paths on disk local.
 */
class GeneratedDocument extends Model
{
    use HasFactory;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_ERROR      = 'error';

    protected $fillable = [
        'document_template_id',
        'company_id',
        'user_id',
        'title',
        'params',
        'status',
        'pdf_path',
        'docx_path',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
        ];
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
