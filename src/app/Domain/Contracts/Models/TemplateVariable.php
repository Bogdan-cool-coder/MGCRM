<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Contracts\Enums\TemplateVariableType;
use Database\Factories\Contracts\TemplateVariableFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TemplateVariable — catalogue of custom substitution keys for {{ custom.<key> }}.
 *
 * Lawyers declare variables here; managers fill them when creating a contract.
 * Wildcard: empty product_codes or country_codes arrays match any context.
 */
class TemplateVariable extends Model
{
    /** @use HasFactory<TemplateVariableFactory> */
    use HasFactory;

    protected static function newFactory(): TemplateVariableFactory
    {
        return TemplateVariableFactory::new();
    }

    protected $table = 'template_variables';

    protected $fillable = [
        'key',
        'label',
        'help_text',
        'var_type',
        'options',
        'default_value',
        'required',
        'group',
        'sort_order',
        'product_codes',
        'country_codes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'var_type' => TemplateVariableType::class,
            'options' => 'array',
            'product_codes' => 'array',
            'country_codes' => 'array',
            'required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @param  Builder<TemplateVariable>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Wildcard matching: empty arrays mean "applies to all".
     * Returns variables that are relevant to the given product/country context.
     *
     * @param  Builder<TemplateVariable>  $query
     */
    public function scopeForContext(Builder $query, string $productCode, string $countryCode): Builder
    {
        return $query->where(function (Builder $q) use ($productCode): void {
            $q->whereJsonLength('product_codes', 0)
                ->orWhereJsonContains('product_codes', $productCode);
        })->where(function (Builder $q) use ($countryCode): void {
            $q->whereJsonLength('country_codes', 0)
                ->orWhereJsonContains('country_codes', $countryCode);
        });
    }
}
