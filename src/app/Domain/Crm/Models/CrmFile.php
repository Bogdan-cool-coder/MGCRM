<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * File stored under a CRM folder.
 * S1.1 scope: schema + model only; upload logic deferred to a later sub-step.
 */
class CrmFile extends Model
{
    protected $table = 'crm_files';

    protected $fillable = [
        'folder_id',
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

    public function folder(): BelongsTo
    {
        return $this->belongsTo(CrmFolder::class, 'folder_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
