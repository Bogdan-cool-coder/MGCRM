<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Enums\AttachmentKind;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\DocumentAttachment;
use App\Domain\Iam\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * AttachmentService — upload, download, delete document attachments.
 *
 * Files are stored on disk 'documents' (config/filesystems.php).
 * Path convention: attachments/{document_id}/{kind}_{uuid8}.{ext}
 * Max size and allowed MIME types come from config/contracts.php.
 */
class AttachmentService
{
    /**
     * Upload a file and persist a DocumentAttachment record.
     *
     * @throws ValidationException on invalid MIME, oversized file, or archived document
     */
    public function upload(Document $doc, UploadedFile $file, AttachmentKind $kind, User $user): DocumentAttachment
    {
        if ($doc->archived_at !== null) {
            throw ValidationException::withMessages([
                'file' => 'Cannot upload to an archived document.',
            ])->status(422);
        }

        $allowedMimes = config('contracts.attachments.allowed_mimes', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);

        $maxBytes = (int) config('contracts.attachments.max_size_bytes', 15 * 1024 * 1024);

        $mime = $file->getMimeType() ?? '';

        if (! in_array($mime, $allowedMimes, strict: true)) {
            throw ValidationException::withMessages([
                'file' => "File type '{$mime}' is not allowed. Allowed: ".implode(', ', $allowedMimes),
            ])->status(422);
        }

        if ($file->getSize() > $maxBytes) {
            $maxMb = round($maxBytes / 1024 / 1024, 1);
            throw ValidationException::withMessages([
                'file' => "File size exceeds the maximum allowed size of {$maxMb} MB.",
            ])->status(422);
        }

        // Derive extension from MIME map or fall back to the file's original extension.
        $extMap = config('contracts.attachments.extensions', [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ]);
        $ext = $extMap[$mime] ?? $file->getClientOriginalExtension();

        $uuid8 = substr((string) Str::uuid(), 0, 8);
        $path = "attachments/{$doc->id}/{$kind->value}_{$uuid8}.{$ext}";

        Storage::disk('documents')->put($path, $file->getContent());

        return DocumentAttachment::create([
            'document_id' => $doc->id,
            'kind' => $kind->value,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'content_type' => $mime,
            'uploaded_by_user_id' => $user->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Stream a file to the response.
     *
     * @throws NotFoundHttpException when the file does not exist on disk
     */
    public function download(DocumentAttachment $attachment): StreamedResponse
    {
        if (! Storage::disk('documents')->exists($attachment->path)) {
            throw new NotFoundHttpException('Attachment file not found.');
        }

        return Storage::disk('documents')->download(
            $attachment->path,
            $attachment->original_name,
        );
    }

    /**
     * Delete a file from disk and remove the DB record.
     *
     * @throws ValidationException when the document is in Signed status
     */
    public function delete(Document $doc, DocumentAttachment $attachment): void
    {
        if ($doc->status === ContractStatus::Signed) {
            throw ValidationException::withMessages([
                'attachments' => 'Cannot delete an attachment of a signed document.',
            ])->status(422);
        }

        Storage::disk('documents')->delete($attachment->path);
        $attachment->delete();
    }

    /**
     * List all attachments for a document, newest first.
     *
     * @return Collection<int, DocumentAttachment>
     */
    public function listForDocument(Document $doc): Collection
    {
        return $doc->attachments()->orderByDesc('created_at')->get();
    }

    /**
     * Check whether a document has at least one attachment of kind=signed_scan.
     */
    public function hasSignedScan(Document $doc): bool
    {
        return $doc->attachments()
            ->where('kind', AttachmentKind::SignedScan->value)
            ->exists();
    }
}
