<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * DocumentTemplate — a reusable document blueprint (PDF/Word) rendered from
 * MacroData fields. Mirrors Widget/Report's visibility model (system / published
 * / personal) and AI provenance (chat_message_id, metadata).
 *
 * type: 'html' (branded commercial proposal via Gotenberg Chromium) or 'docx'
 * (uploaded Word template with ${placeholders}, M5+). The render config lives in
 * config (jsonb); source_path points to the uploaded .docx on disk local.
 */
class DocumentTemplate extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'description',
        'type',
        'config',
        'source_path',
        'is_system',
        'is_published',
        'sort_order',
        'chat_message_id',
        'metadata',
    ];

    /** @var array<int, string> */
    public $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'config'       => 'array',
            'is_system'    => 'boolean',
            'is_published' => 'boolean',
            'sort_order'   => 'integer',
            // jsonb column carrying AI-pipeline flags (e.g. dry_run_failed),
            // mirroring reports.metadata / widgets.metadata.
            'metadata'     => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias of user() to expose the template author under an explicit `author`
     * key in API responses (mirrors Widget::author() / Report::author()).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeUserCreated($query)
    {
        return $query->where('is_system', false);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
