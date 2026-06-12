<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Contracts\TemplateVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TemplateVersion — immutable snapshot of a docx upload.
 *
 * Each time a lawyer uploads a new .docx via POST /api/templates/{id}/upload (S2.3),
 * a new TemplateVersion is created. Template.current_version_id points to the active one.
 *
 * ai_remarks: JSON array set by AI review (S2.3), null until then.
 * ai_overridden: true when lawyer proceeds despite AI warnings.
 *
 * Immutable — no updated_at.
 */
class TemplateVersion extends Model
{
    /** @use HasFactory<TemplateVersionFactory> */
    use HasFactory;

    protected static function newFactory(): TemplateVersionFactory
    {
        return TemplateVersionFactory::new();
    }

    protected $table = 'template_versions';

    public $timestamps = false;

    /** @var list<string> */
    protected $dates = ['created_at'];

    protected $fillable = [
        'template_id',
        'version_number',
        'docx_path',
        'ai_remarks',
        'ai_overridden',
        'created_by_user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'ai_remarks' => 'array',
            'ai_overridden' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
