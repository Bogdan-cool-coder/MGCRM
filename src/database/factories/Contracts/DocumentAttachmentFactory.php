<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Enums\AttachmentKind;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAttachment>
 */
class DocumentAttachmentFactory extends Factory
{
    protected $model = DocumentAttachment::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'kind' => AttachmentKind::Other->value,
            'path' => 'attachments/1/other_abc12345.pdf',
            'original_name' => 'document.pdf',
            'content_type' => 'application/pdf',
            'uploaded_by_user_id' => User::factory(),
            'created_at' => now(),
        ];
    }

    public function signedScan(): static
    {
        return $this->state([
            'kind' => AttachmentKind::SignedScan->value,
            'path' => 'attachments/1/signed_scan_abc12345.pdf',
            'original_name' => 'signed_scan.pdf',
        ]);
    }
}
