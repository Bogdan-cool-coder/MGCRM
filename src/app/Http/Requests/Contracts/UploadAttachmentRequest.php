<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use App\Domain\Contracts\Enums\AttachmentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * UploadAttachmentRequest — POST /api/documents/{document}/attachments (multipart/form-data)
 *
 * MIME and size validation is also enforced in AttachmentService::upload() using
 * config/contracts.php. The FormRequest provides early 422 feedback to the client.
 */
class UploadAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check is done in the controller via authorize().
    }

    public function rules(): array
    {
        $allowedMimes = implode(',', config('contracts.attachments.allowed_extensions', ['pdf', 'jpg', 'jpeg', 'png', 'webp']));
        $maxKb = (int) (config('contracts.attachments.max_size_bytes', 15 * 1024 * 1024) / 1024);

        return [
            'file' => ['required', 'file', "mimes:{$allowedMimes}", "max:{$maxKb}"],
            'kind' => ['required', new Enum(AttachmentKind::class)],
        ];
    }
}
