<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Contracts\ApprovalRouteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ApprovalRoute — configurable N-stage approval workflow matcher.
 *
 * Matched by (document_kind + template_id exact) or (document_kind + is_default=true).
 * Stages are stored as JSONB: [{order, name, user_ids[], min_required}].
 * Parsed in PHP only — no whereJsonContains (SQLite compat).
 */
class ApprovalRoute extends Model
{
    /** @use HasFactory<ApprovalRouteFactory> */
    use HasFactory;

    protected static function newFactory(): ApprovalRouteFactory
    {
        return ApprovalRouteFactory::new();
    }

    protected $table = 'approval_routes';

    protected $fillable = [
        'title',
        'document_kind',
        'template_id',
        'is_default',
        'stages',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'stages' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ---- Relations ----

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    // ---- Scopes ----

    /** @param Builder<ApprovalRoute> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
