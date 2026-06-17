<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRemark;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRemark>
 */
class DocumentRemarkFactory extends Factory
{
    protected $model = DocumentRemark::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'attempt' => 1,
            'stage_order' => 0,
            'author_user_id' => User::factory(),
            'text' => $this->faker->sentence(),
            'is_resolved' => false,
            'resolved_at' => null,
            'resolved_by_user_id' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(function (): array {
            return [
                'is_resolved' => true,
                'resolved_at' => now(),
                'resolved_by_user_id' => User::factory(),
            ];
        });
    }
}
