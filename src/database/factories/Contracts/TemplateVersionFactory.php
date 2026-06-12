<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemplateVersion>
 */
class TemplateVersionFactory extends Factory
{
    protected $model = TemplateVersion::class;

    public function definition(): array
    {
        return [
            'template_id' => Template::factory(),
            'version_number' => 1,
            'docx_path' => null,
            'ai_remarks' => null,
            'ai_overridden' => false,
            'created_by_user_id' => null,
            'created_at' => now(),
        ];
    }

    public function withDocxPath(string $path): static
    {
        return $this->state(['docx_path' => $path]);
    }
}
