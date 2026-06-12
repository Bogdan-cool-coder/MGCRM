<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentRevision;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRevision>
 */
class DocumentRevisionFactory extends Factory
{
    protected $model = DocumentRevision::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version_number' => 1,
            'attempt' => 1,
            'context_snapshot' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [],
            ],
            'template_version' => null,
            'docx_path' => null,
            'pdf_path' => null,
            'note' => 'Submitted for approval',
            'created_by_user_id' => User::factory(),
            'created_at' => now(),
        ];
    }
}
