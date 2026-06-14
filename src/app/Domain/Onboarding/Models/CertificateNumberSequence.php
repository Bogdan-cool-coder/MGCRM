<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use Database\Factories\Onboarding\CertificateNumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CertificateNumberSequence extends Model
{
    /** @use HasFactory<CertificateNumberSequenceFactory> */
    use HasFactory;

    protected static function newFactory(): CertificateNumberSequenceFactory
    {
        return CertificateNumberSequenceFactory::new();
    }

    protected $table = 'certificate_number_sequences';

    protected $fillable = [
        'year',
        'current_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'current_number' => 'integer',
        ];
    }
}
