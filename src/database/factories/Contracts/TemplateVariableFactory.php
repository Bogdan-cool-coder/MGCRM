<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Enums\TemplateVariableType;
use App\Domain\Contracts\Models\TemplateVariable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TemplateVariable>
 */
class TemplateVariableFactory extends Factory
{
    protected $model = TemplateVariable::class;

    public function definition(): array
    {
        return [
            'key' => Str::snake($this->faker->unique()->words(2, true)),
            'label' => $this->faker->sentence(3),
            'help_text' => $this->faker->optional()->sentence(),
            'var_type' => TemplateVariableType::Text,
            'options' => [],
            'default_value' => null,
            'required' => false,
            'group' => null,
            'sort_order' => 0,
            'product_codes' => [],
            'country_codes' => [],
            'is_active' => true,
        ];
    }

    public function select(): static
    {
        return $this->state([
            'var_type' => TemplateVariableType::Select,
            'options' => ['Option A', 'Option B', 'Option C'],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function forProduct(string $code): static
    {
        return $this->state(['product_codes' => [$code]]);
    }

    public function forCountry(string $code): static
    {
        return $this->state(['country_codes' => [$code]]);
    }
}
