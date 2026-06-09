<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * CompanyBranding — one-to-one brand profile for a company. Drives the look of
 * HTML commercial proposals (logo, palette, fonts, header/footer, requisites).
 *
 * M1: model + table only. Controller / logo upload / application in
 * HtmlDocumentService land in M2.
 */
class CompanyBranding extends Model
{
    use HasFactory;
    use HasTranslations;

    /**
     * Default palette used when a company has no branding row (or leaves a
     * colour unset). Single source of truth shared by CompanyBrandingController
     * (GET defaults) and HtmlDocumentService (CSS variables). Keys mirror the
     * jsonb `colors` shape: {primary, secondary, accent, text, bg}.
     *
     * @var array<string, string>
     */
    public const DEFAULT_COLORS = [
        'primary'   => '#1d4ed8',
        'secondary' => '#64748b',
        'accent'    => '#f59e0b',
        'text'      => '#111827',
        'bg'        => '#ffffff',
    ];

    /**
     * Default font stack when none is configured.
     *
     * @var array<string, string>
     */
    public const DEFAULT_FONTS = [
        'heading' => "'DejaVu Sans', Arial, sans-serif",
        'body'    => "'DejaVu Sans', Arial, sans-serif",
    ];

    protected $fillable = [
        'company_id',
        'logo_path',
        'colors',
        'fonts',
        'header',
        'footer',
        'requisites',
        'updated_by',
    ];

    /** @var array<int, string> */
    public $translatable = ['header', 'footer'];

    protected function casts(): array
    {
        return [
            'colors'     => 'array',
            'fonts'      => 'array',
            'requisites' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
