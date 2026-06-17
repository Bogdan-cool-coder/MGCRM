<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Data\ImportResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ImportResult */
class ImportResultResource extends JsonResource
{
    public function __construct(ImportResult $resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'dry_run' => $this->resource->dryRun,
            'inserted' => $this->resource->inserted,
            'updated' => $this->resource->updated,
            'skipped' => $this->resource->skipped,
            'errors' => $this->resource->errors,
        ];
    }
}
