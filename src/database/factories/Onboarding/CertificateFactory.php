<?php

declare(strict_types=1);

namespace Database\Factories\Onboarding;

use App\Domain\Onboarding\Models\Certificate;
use App\Domain\Onboarding\Models\CourseAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'assignment_id' => CourseAssignment::factory(),
            'certificate_number' => sprintf('CERT-%d-%04d', (int) date('Y'), $counter),
            'issued_at' => now(),
            'pdf_path' => 'onboarding/certificates/1/certificate.pdf',
        ];
    }
}
