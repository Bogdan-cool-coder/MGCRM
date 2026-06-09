<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DocumentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentTemplate>
 */
class DocumentTemplateFactory extends Factory
{
    protected $model = DocumentTemplate::class;

    public function definition(): array
    {
        return [
            'name'         => ['ru' => 'Шаблон', 'en' => 'Template'],
            'description'  => null,
            'type'         => 'html',
            'config'       => [
                'html' => '<h1>{{title}}</h1><p>ЖК: {{complex_name}}</p>',
            ],
            'source_path'  => null,
            'is_system'    => false,
            'is_published' => false,
            'sort_order'   => null,
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

    public function docx(): static
    {
        return $this->state(fn () => [
            'type'        => 'docx',
            'source_path' => 'documents/templates/sample.docx',
            'config'      => ['placeholders' => []],
        ]);
    }
}
