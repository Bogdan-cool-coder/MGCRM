<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\CrmFile;
use App\Domain\Crm\Models\CrmFolder;
use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CrmFileService — folders and files on CRM entity cards (contacts + companies).
 *
 * Key invariants:
 *   1. System folders are seeded lazily on first GET /folders call.
 *   2. "Сканы договоров" (company-only) is a virtual read-only folder:
 *      - never stores CrmFile rows
 *      - listFilesInFolder() for it returns deal Documents as file-like DTOs
 *      - upload/rename/delete → 422
 *   3. System folder names are IMMUTABLE (reject rename via updateFolder).
 *   4. System folders cannot be deleted.
 *   5. Files are stored on the 'crm_files' disk (local by default; swap to S3 via config).
 */
class CrmFileService
{
    private const DISK = 'crm_files';

    // ---- Entity helpers ----

    private function entityType(Company|Contact $entity): string
    {
        return $entity instanceof Company ? 'company' : 'contact';
    }

    /**
     * @return list<string>
     */
    private function systemFolderNames(Company|Contact $entity): array
    {
        return $entity instanceof Company
            ? CrmFolder::systemFolderNamesForCompany()
            : CrmFolder::systemFolderNamesForContact();
    }

    // ---- Folders ----

    /**
     * Lazily seed system folders, then return all folders for the entity.
     *
     * @return Collection<int, CrmFolder>
     */
    public function listFolders(Company|Contact $entity): Collection
    {
        $this->ensureSystemFolders($entity);

        return CrmFolder::query()
            ->where('owner_entity_type', $this->entityType($entity))
            ->where('owner_entity_id', $entity->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Create a user-defined (non-system) folder.
     *
     * @param  array<string, mixed>  $data  validated: name (string)
     */
    public function createFolder(Company|Contact $entity, array $data): CrmFolder
    {
        $this->ensureSystemFolders($entity);

        $maxOrder = CrmFolder::query()
            ->where('owner_entity_type', $this->entityType($entity))
            ->where('owner_entity_id', $entity->id)
            ->max('sort_order') ?? 0;

        return CrmFolder::create([
            'owner_entity_type' => $this->entityType($entity),
            'owner_entity_id' => $entity->id,
            'name' => $data['name'],
            'is_system' => false,
            'sort_order' => (int) $maxOrder + 1,
        ]);
    }

    /**
     * Delete a user-defined folder (and all its files from storage).
     * System folders cannot be deleted.
     *
     * @throws ValidationException
     */
    public function deleteFolder(CrmFolder $folder): void
    {
        if ($folder->is_system) {
            throw ValidationException::withMessages([
                'folder' => 'System folders cannot be deleted.',
            ])->status(422);
        }

        // Remove all files from storage first.
        foreach ($folder->files as $file) {
            $this->deleteFileFromStorage($file);
        }

        $folder->delete();
    }

    // ---- Files ----

    /**
     * List files in a folder.
     * For "Сканы договоров" returns deal Documents cast to a file-like shape.
     * For all other folders returns CrmFile records.
     *
     * Returns either Collection<int, CrmFile> OR array of document DTOs.
     *
     * @return Collection<int, CrmFile>|array<int, array<string, mixed>>
     */
    public function listFilesInFolder(CrmFolder $folder, Company|Contact $entity): Collection|array
    {
        if ($folder->isScansFolder()) {
            return $this->listDocumentsForScansFolder($entity);
        }

        return $folder->files()->with('uploadedBy:id,full_name')->orderByDesc('created_at')->get();
    }

    /**
     * Upload a file into a folder.
     * Rejected for the "Сканы договоров" virtual folder.
     *
     * @throws ValidationException
     */
    public function uploadFile(CrmFolder $folder, UploadedFile $file, User $uploader): CrmFile
    {
        if ($folder->isScansFolder()) {
            throw ValidationException::withMessages([
                'folder' => 'Cannot upload to "Сканы договоров" — it is a read-only auto-view of deal documents.',
            ])->status(422);
        }

        $ext = $file->getClientOriginalExtension();
        $uuid = Str::uuid()->toString();
        $path = "crm/{$folder->owner_entity_type}/{$folder->owner_entity_id}/{$folder->id}/{$uuid}.{$ext}";

        Storage::disk(self::DISK)->put($path, $file->get());

        return CrmFile::create([
            'folder_id' => $folder->id,
            'disk' => self::DISK,
            'owner_entity_type' => $folder->owner_entity_type,
            'owner_entity_id' => $folder->owner_entity_id,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by_user_id' => $uploader->id,
        ]);
    }

    /**
     * Stream a file for download.
     * Uses the disk stored on the CrmFile record for future S3 compatibility.
     *
     * @throws NotFoundHttpException
     */
    public function download(CrmFile $file): StreamedResponse
    {
        $disk = $file->disk ?: self::DISK;

        abort_unless(Storage::disk($disk)->exists($file->file_path), 404, 'File not found on storage.');

        return Storage::disk($disk)->download($file->file_path, $file->original_name);
    }

    /**
     * Delete a user-uploaded file.
     *
     * @throws ValidationException
     */
    public function deleteFile(CrmFile $file): void
    {
        $this->deleteFileFromStorage($file);
        $file->delete();
    }

    // ---- Private ----

    /**
     * Lazily create missing system folders for the entity.
     */
    private function ensureSystemFolders(Company|Contact $entity): void
    {
        $type = $this->entityType($entity);
        $names = $this->systemFolderNames($entity);

        $existing = CrmFolder::query()
            ->where('owner_entity_type', $type)
            ->where('owner_entity_id', $entity->id)
            ->where('is_system', true)
            ->pluck('name')
            ->all();

        foreach ($names as $order => $name) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            CrmFolder::create([
                'owner_entity_type' => $type,
                'owner_entity_id' => $entity->id,
                'name' => $name,
                'is_system' => true,
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * Returns deal Documents for the company cast to file-like DTOs.
     * "Сканы договоров" auto-view sources: Documents with source_company_id = company.id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listDocumentsForScansFolder(Company|Contact $entity): array
    {
        if (! ($entity instanceof Company)) {
            return [];
        }

        $documents = Document::query()
            ->where('source_company_id', $entity->id)
            ->whereNull('archived_at')
            ->with('author:id,full_name')
            ->orderByDesc('created_at')
            ->get();

        return $documents->map(function (Document $doc): array {
            // Prefer PDF path if available, else docx path.
            $hasPdf = (bool) $doc->pdf_path;
            $hasDocx = (bool) $doc->docx_path;
            $path = $doc->pdf_path ?: $doc->docx_path;
            $mimeType = $hasPdf ? 'application/pdf' : ($hasDocx ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : null);
            $name = ($doc->number ? "[{$doc->number}] " : '')
                       .($doc->title ?: 'Document #'.$doc->id);

            // Attempt to get file size from documents disk.
            $size = null;
            if ($path && Storage::disk('documents')->exists($path)) {
                $size = Storage::disk('documents')->size($path);
            }

            $downloadUrl = $hasPdf || $hasDocx
                ? route('documents.show', $doc->id)   // existing endpoint; front downloads from there
                : null;

            return [
                'id' => 'doc_'.$doc->id,   // prefixed to distinguish from CrmFile IDs
                'source' => 'document',
                'document_id' => $doc->id,
                'original_name' => $name.($hasPdf ? '.pdf' : ($hasDocx ? '.docx' : '')),
                'mime_type' => $mimeType,
                'file_size' => $size,
                'status' => $doc->status?->value,
                'uploaded_by' => $doc->author ? ['id' => $doc->author->id, 'name' => $doc->author->full_name] : null,
                'created_at' => $doc->created_at?->toIso8601String(),
                'download_url' => $downloadUrl,
            ];
        })->values()->all();
    }

    private function deleteFileFromStorage(CrmFile $file): void
    {
        $disk = $file->disk ?: self::DISK;

        if (Storage::disk($disk)->exists($file->file_path)) {
            Storage::disk($disk)->delete($file->file_path);
        }
    }
}
