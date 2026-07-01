<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\CrmFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CrmFolder */
class CrmFolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_system' => $this->is_system,
            /**
             * read_only: true means this folder does not accept uploads or renames/deletes.
             * Currently only "Сканы договоров" is read_only.
             */
            'read_only' => $this->isScansFolder(),
            'sort_order' => $this->sort_order,
            'owner_entity_type' => $this->owner_entity_type,
            'owner_entity_id' => $this->owner_entity_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
