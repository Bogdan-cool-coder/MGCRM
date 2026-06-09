<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GeneratedDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    protected $model = GeneratedDocument::class;

    public function definition(): array
    {
        return [
            'title'     => 'КП по объекту',
            'params'    => ['estate_sell_id' => 123],
            'status'    => GeneratedDocument::STATUS_PENDING,
            'pdf_path'  => null,
            'docx_path' => null,
            'error'     => null,
            // document_template_id / company_id / user_id supplied by the caller.
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => [
            'status'   => GeneratedDocument::STATUS_DONE,
            'pdf_path' => 'documents/1/document.pdf',
        ]);
    }

    public function error(): static
    {
        return $this->state(fn () => [
            'status' => GeneratedDocument::STATUS_ERROR,
            'error'  => 'render failed',
        ]);
    }
}
