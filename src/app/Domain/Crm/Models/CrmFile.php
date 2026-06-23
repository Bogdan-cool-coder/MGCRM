<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File stored under a CRM folder.
 *
 * Columns:
 *   - disk: storage disk name (default 'crm_files'; swap to 's3' without DB migration)
 *   - file_path: relative path within the disk
 *   - original_name: display name as uploaded
 *   - file_size: bytes (uint)
 *   - mime_type: nullable MIME string
 *   - uploaded_by_user_id: FK users (null-on-delete)
 *
 * The "Сканы договоров" virtual folder never holds CrmFile rows.
 * Its "files" listing is provided by CrmFileService::listDocumentsForScansFolder().
 */
class CrmFile extends Model
{
    protected $table = 'crm_files';

    protected $fillable = [
        'folder_id',
        'disk',
        'owner_entity_type',
        'owner_entity_id',
        'file_path',
        'original_name',
        'file_size',
        'mime_type',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    // ---- Relations ----

    public function folder(): BelongsTo
    {
        return $this->belongsTo(CrmFolder::class, 'folder_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
