<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class Report extends Model
{
    use HasTranslations;

    protected $fillable = [
        'title',
        'description',
        'config',
        'is_system',
        'user_id',
        'company_id',
        'is_published',
        'sort_order',
        'chat_message_id',
        'metadata',
    ];

    public $translatable = ['title', 'description'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_system' => 'boolean',
            'is_published' => 'boolean',
            // metadata is a jsonb column carrying AI-pipeline flags
            // (e.g. dry_run_failed=true when ReportDataService::getData()
            // throws right after create_report / update_report — see
            // ReportTool and ReportController::index()).
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias of user() used to expose the report author in API responses under
     * the explicit `author` key. Eager-loaded with a `select` projection in
     * ReportController to avoid leaking unrelated user fields (and N+1).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }

    public function chat()
    {
        return $this->hasOne(Chat::class);
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
