<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Onboarding\Models\CertificateNumberSequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CertificateNumberSequence>
 */
class CertificateNumberSequenceFactory extends Factory
{
    protected $model = CertificateNumberSequence::class;

    public function definition(): array
    {
        return [
            'year' => (int) date('Y'),
            'current_number' => 0,
        ];
    }
}
