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
        $isDryRun = $this->resource->dryRun;

        return [
            'dry_run' => $isDryRun,
            // Projected counts (dry-run only; zero on real run so the FE can
            // distinguish between "nothing happened" and "not applicable").
            'would_insert' => $isDryRun ? $this->resource->inserted : 0,
            'would_update' => $isDryRun ? $this->resource->updated : 0,
            // Actual counts (real run only; zero on dry-run).
            'inserted' => $isDryRun ? 0 : $this->resource->inserted,
            'updated' => $isDryRun ? 0 : $this->resource->updated,
            'skipped' => $this->resource->skipped,
            'errors' => $this->resource->errors,
        ];
    }
}
