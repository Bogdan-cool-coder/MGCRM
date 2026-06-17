<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        return [
            'code' => 'tmpl_'.Str::random(8),
            'kind' => 'yaml',
            'title' => $this->faker->sentence(3),
            'content' => "name: \"Test Template\"\nversion: 1\n",
            'version' => 1,
            'current_version_id' => null,
            'category' => null,
            'product_codes' => [],
            'country_codes' => [],
            'client_category_codes' => [],
            'department_ids' => [],
            'updated_by_user_id' => null,
        ];
    }

    public function docx(): static
    {
        return $this->state([
            'kind' => 'docx',
            'content' => '',
        ]);
    }

    public function withCategory(string $category): static
    {
        return $this->state(['category' => $category]);
    }
}
