<?php

declare(strict_types=1);

namespace App\Http\Requests\Contracts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GenerateDocumentRequest — validates the generate endpoint body.
 *
 * POST /api/documents/{document}/generate   → empty body, auth checked via Policy
 * POST /api/deals/{deal}/documents/generate → optional document_id
 * POST /api/companies/{company}/documents/generate → optional document_id,
 *   required product_code + country_code when no document_id
 *
 * The generate action itself is idempotent (re-generates existing document).
 * Authorization is delegated to DocumentPolicy::generate() in the controller.
 */
class GenerateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled by Policy in controller.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // For deal/company entry points — optionally pass an existing document_id.
            'document_id' => ['nullable', 'integer', 'exists:documents,id'],

            // Required when creating a document inline from company/deal context.
            'product_code' => ['nullable', 'string', 'max:50'],
            'country_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:100'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
