<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use Database\Factories\Onboarding\CertificateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    protected static function newFactory(): CertificateFactory
    {
        return CertificateFactory::new();
    }

    protected $fillable = [
        'assignment_id',
        'certificate_number',
        'issued_at',
        'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'assignment_id' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CourseAssignment::class, 'assignment_id');
    }
}
