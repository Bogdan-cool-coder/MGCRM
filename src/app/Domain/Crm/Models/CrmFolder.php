<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * File folder attached to a CRM entity (contact | company).
 * Polymorphic via (owner_entity_type, owner_entity_id).
 *
 * System folders (is_system = true):
 *   Company: "Папка менеджера сделки", "Сканы договоров", "Папка ОКС"
 *   Contact: "Папка менеджера сделки"
 *
 * "Сканы договоров" is a virtual read-only auto-view:
 *   - files() relation returns EMPTY (files are never stored here)
 *   - CrmFileService::listFilesInFolder() returns deal documents for company
 *   - upload/rename/delete on this folder are rejected (422)
 */
class CrmFolder extends Model
{
    protected $table = 'crm_folders';

    protected $fillable = [
        'owner_entity_type',
        'owner_entity_id',
        'name',
        'is_system',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ---- System folder name constants ----

    /** Present on both company and contact. */
    public const FOLDER_MANAGER = 'Папка менеджера сделки';

    /** Company-only virtual read-only auto-view of deal documents. */
    public const FOLDER_SCANS = 'Сканы договоров';

    /** Company-only. */
    public const FOLDER_OKS = 'Папка ОКС';

    /**
     * Ordered system folder names for a company entity.
     *
     * @return list<string>
     */
    public static function systemFolderNamesForCompany(): array
    {
        return [
            self::FOLDER_MANAGER,
            self::FOLDER_SCANS,
            self::FOLDER_OKS,
        ];
    }

    /**
     * Ordered system folder names for a contact entity.
     *
     * @return list<string>
     */
    public static function systemFolderNamesForContact(): array
    {
        return [
            self::FOLDER_MANAGER,
        ];
    }

    // ---- Helpers ----

    public function isScansFolder(): bool
    {
        return $this->is_system && $this->name === self::FOLDER_SCANS;
    }

    // ---- Relations ----

    public function files(): HasMany
    {
        return $this->hasMany(CrmFile::class, 'folder_id');
    }
}
