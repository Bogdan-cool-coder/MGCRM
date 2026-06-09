<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Translatable\HasTranslations;

/**
 * Widget — a standalone, reusable visualization unit: a small aggregating
 * query (resolved by the same machinery as reports) plus chart presentation.
 * Mirrors Report's visibility model (system / published / personal) and AI
 * provenance. The chart type lives only in config.chart.type (decision O5).
 */
class Widget extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'config',
        'is_system',
        'is_published',
        'chat_message_id',
        'metadata',
    ];

    /** @var array<int, string> */
    public $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_system' => 'boolean',
            'is_published' => 'boolean',
            // jsonb column carrying AI-pipeline flags (e.g. dry_run_failed),
            // mirroring reports.metadata.
            'metadata' => 'array',
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
     * Alias of user() to expose the widget author under an explicit `author`
     * key in API responses (mirrors Report::author()).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }

    /**
     * The widget_generation chat that produced/edits this widget (chats.widget_id).
     */
    public function chat(): HasOne
    {
        return $this->hasOne(Chat::class);
    }

    /**
     * Dashboards this widget is placed on (by reference). Pivot carries the
     * grid layout, sort order and per-placement visibility.
     */
    public function dashboards(): BelongsToMany
    {
        return $this->belongsToMany(Dashboard::class, 'dashboard_widget')
            ->withPivot(['x', 'y', 'w', 'h', 'sort', 'visible'])
            ->withTimestamps();
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
