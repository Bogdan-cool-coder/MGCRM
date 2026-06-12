<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ArchiveDocumentRequest — POST /api/documents/{document}/archive|unarchive
 *
 * No body required. Guard (in_review check) lives in DocumentService::archive().
 */
class ArchiveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy check is done in the controller via authorize().
    }

    public function rules(): array
    {
        return [];
    }
}
