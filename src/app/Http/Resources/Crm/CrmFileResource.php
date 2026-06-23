<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\CrmFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CrmFile */
class CrmFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'folder_id'     => $this->folder_id,
            'original_name' => $this->original_name,
            'mime_type'     => $this->mime_type,
            'file_size'     => $this->file_size,
            'disk'          => $this->disk,
            'uploaded_by'   => $this->whenLoaded('uploadedBy', fn () => [
                'id'   => $this->uploadedBy->id,
                'name' => $this->uploadedBy->full_name,
            ]),
            'created_at'    => $this->created_at?->toIso8601String(),
            'download_url'  => $this->downloadUrl($request),
        ];
    }

    private function downloadUrl(Request $request): string
    {
        // Route is mounted under the entity prefix; resolve from request path.
        // companies/{company}/files/{file}/download OR contacts/{contact}/files/{file}/download
        $path = $request->path();

        // Extract entity prefix: /api/companies/5/... or /api/contacts/3/...
        if (preg_match('#^api/(companies|contacts)/(\d+)#', $path, $m)) {
            $prefix = $m[1].'/'.$m[2];

            return url("api/{$prefix}/files/{$this->id}/download");
        }

        // Fallback: derive from stored entity type/id on the file.
        $entitySegment = $this->owner_entity_type === 'company' ? 'companies' : 'contacts';

        return url("api/{$entitySegment}/{$this->owner_entity_id}/files/{$this->id}/download");
    }
}
