<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Enums\AiCheckStatus;
use App\Domain\Contracts\Models\Template;
use App\Domain\Contracts\Models\TemplateVersion;
use App\Jobs\Contracts\CheckTemplateJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TemplateService — CRUD and versioning for contract templates.
 *
 * Docx upload flow (S2.3):
 *   1. createVersion() → new TemplateVersion with docx_path
 *   2. Template.current_version_id updated to new version
 *
 * In S2.1: TemplateVersion model and createVersion() are scaffolded.
 * Actual upload endpoint (POST /api/templates/{id}/upload) lands in S2.3.
 */
class TemplateService
{
    /**
     * @return Collection<int, Template>
     */
    public function list(
        ?string $kind = null,
        ?string $category = null,
        ?string $productCode = null,
        ?string $countryCode = null,
        ?string $search = null,
    ): Collection {
        return Template::query()
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($category !== null, fn ($q) => $q->where('category', $category))
            // BUG-DOC-2: use scope methods (whereJsonLength / whereJsonContains) rather
            // than scalar WHERE on JSON-array columns product_codes / country_codes.
            ->when($productCode !== null, fn ($q) => $q->forProduct($productCode))
            ->when($countryCode !== null, fn ($q) => $q->forCountry($countryCode))
            ->when(
                $search !== null && trim($search) !== '',
                fn ($q) => $q->where(function ($inner) use ($search) {
                    $pattern = '%'.mb_strtolower(trim($search)).'%';
                    $inner->whereRaw('LOWER(code) LIKE ?', [$pattern])
                          ->orWhereRaw('LOWER(title) LIKE ?', [$pattern]);
                }),
            )
            ->with('currentVersion')
            ->orderBy('code')
            ->get();
    }

    public function findByCode(string $code): ?Template
    {
        return Template::where('code', $code)->with('currentVersion')->first();
    }

    /**
     * Create a new template (code/kind/title/scopes).
     * The template is created without a docx version (version=0, current_version_id=null).
     * Upload a docx version separately via POST /api/templates/{id}/upload.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId = null): Template
    {
        $template = Template::create([
            'code' => $data['code'],
            'kind' => $data['kind'],
            'title' => $data['title'],
            'content' => '',
            'version' => 0,
            'current_version_id' => null,
            'category' => $data['category'] ?? null,
            'product_codes' => $data['product_codes'] ?? [],
            'country_codes' => $data['country_codes'] ?? [],
            'client_category_codes' => $data['client_category_codes'] ?? [],
            'department_ids' => $data['department_ids'] ?? [],
            'updated_by_user_id' => $userId,
        ]);

        return $template->load('currentVersion');
    }

    /**
     * Update template metadata (YAML content, title, category, scopes).
     * Increments version on every save. Sets updated_by_user_id.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Template $template, array $data, ?int $userId = null): Template
    {
        $data['version'] = $template->version + 1;

        if ($userId !== null) {
            $data['updated_by_user_id'] = $userId;
        }

        $template->update($data);
        $template->refresh();

        return $template->load('currentVersion');
    }

    public function latestVersion(Template $template): ?TemplateVersion
    {
        return $template->versions()
            ->orderByDesc('version_number')
            ->first();
    }

    /**
     * Create a new TemplateVersion and set it as the active version.
     * Called from S2.3 upload endpoint.
     */
    public function createVersion(Template $template, string $docxPath, int $userId): TemplateVersion
    {
        $version = DB::transaction(function () use ($template, $docxPath, $userId): TemplateVersion {
            $lastVersion = $this->latestVersion($template);
            $nextNumber = $lastVersion ? $lastVersion->version_number + 1 : 1;

            $version = TemplateVersion::create([
                'template_id' => $template->id,
                'version_number' => $nextNumber,
                'docx_path' => $docxPath,
                'ai_remarks' => null,
                'ai_overridden' => false,
                'ai_check_status' => AiCheckStatus::Pending,
                'ai_checked_at' => null,
                'pdf_ok' => null,
                'created_by_user_id' => $userId,
                'created_at' => now(),
            ]);

            $template->update(['current_version_id' => $version->id]);

            return $version;
        });

        // Dispatch AI check outside the transaction so the version row is
        // already committed when the job picks it up from the queue.
        CheckTemplateJob::dispatch($version->id);

        return $version;
    }

    /**
     * Get the docx file path for the template's current active version.
     * Returns null if no version has been uploaded yet.
     */
    public function getDocxPath(Template $template): ?string
    {
        $template->loadMissing('currentVersion');

        if ($template->currentVersion === null || $template->currentVersion->docx_path === null) {
            throw new RuntimeException(
                "TemplateService: no docx uploaded for {$template->code}. "
                .'Upload a .docx file via POST /api/templates/{id}/upload before generating.'
            );
        }

        return $template->currentVersion->docx_path;
    }
}
