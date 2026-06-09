<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * Dashboard — a composition of widgets (by reference) with server-side layout.
 * Visibility mirrors reports: system (company-wide) + personal + published.
 * The dashboard itself is assembled by the user; it is never AI-generated.
 */
class Dashboard extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'company_id',
        'user_id',
        'name',
        'is_system',
        'is_published',
    ];

    /** @var array<int, string> */
    public $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_published' => 'boolean',
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
     * Alias of user() to expose the dashboard owner under an explicit `author`
     * key in API responses (mirrors Report::author()).
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Widgets placed on this dashboard (by reference). Pivot carries the grid
     * layout, sort order and per-placement visibility.
     */
    public function widgets(): BelongsToMany
    {
        return $this->belongsToMany(Widget::class, 'dashboard_widget')
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
