<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Contracts\TemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template — contract generation template (master_skeleton, product_*, country_*).
 *
 * kind = 'docx': binary content loaded via TemplateVersion.docx_path.
 * kind = 'yaml': YAML overlay stored in Template.content directly.
 * kind = 'text': plain-text template (reserved for S2.7).
 *
 * Wildcard: empty product_codes/country_codes/client_category_codes/department_ids
 * arrays mean "applies to everything" (all products / all countries).
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected static function newFactory(): TemplateFactory
    {
        return TemplateFactory::new();
    }

    protected $table = 'templates';

    protected $fillable = [
        'code',
        'kind',
        'title',
        'content',
        'version',
        'current_version_id',
        'category',
        'product_codes',
        'country_codes',
        'client_category_codes',
        'department_ids',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'product_codes' => 'array',
            'country_codes' => 'array',
            'client_category_codes' => 'array',
            'department_ids' => 'array',
        ];
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'current_version_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TemplateVersion::class, 'template_id');
    }

    /**
     * Filter templates that match a given product code.
     * Empty product_codes array = wildcard (matches any).
     *
     * @param  Builder<Template>  $query
     */
    public function scopeForProduct(Builder $query, string $code): Builder
    {
        return $query->where(function (Builder $q) use ($code): void {
            $q->whereJsonLength('product_codes', 0)
                ->orWhereJsonContains('product_codes', $code);
        });
    }

    /**
     * Filter templates that match a given country code.
     * Empty country_codes array = wildcard (matches any).
     *
     * @param  Builder<Template>  $query
     */
    public function scopeForCountry(Builder $query, string $code): Builder
    {
        return $query->where(function (Builder $q) use ($code): void {
            $q->whereJsonLength('country_codes', 0)
                ->orWhereJsonContains('country_codes', $code);
        });
    }

    /**
     * @param  Builder<Template>  $query
     */
    public function scopeDocxKind(Builder $query): Builder
    {
        return $query->where('kind', 'docx');
    }
}
