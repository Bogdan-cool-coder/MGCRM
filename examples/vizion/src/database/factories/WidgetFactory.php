<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Widget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Widget>
 */
class WidgetFactory extends Factory
{
    protected $model = Widget::class;

    public function definition(): array
    {
        return [
            'name'         => ['ru' => 'Виджет', 'en' => 'Widget'],
            'config'       => [
                'primary_model' => 'Deal',
                'group_by'      => ['fields' => ['geo_complex_name']],
                'aggregates'    => [['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'value']],
                'chart'         => [
                    'type'        => 'bar',
                    'label_field' => 'geo_complex_name',
                    'value_field' => 'value',
                ],
            ],
            'is_system'    => false,
            'is_published' => false,
            // company_id / user_id are supplied by the caller (no orphan FK).
        ];
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
            'user_id'   => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => ['is_published' => true]);
    }
}
