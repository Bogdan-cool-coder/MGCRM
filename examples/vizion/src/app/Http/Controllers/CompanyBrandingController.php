<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyBranding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CompanyBrandingController — per-company brand profile (logo, palette, fonts,
 * header/footer, requisites) that drives the look of HTML commercial proposals
 * (КП) in the Documents section.
 *
 * ACL:
 *   - Read (GET): any authenticated role with access to the company — viewers
 *     and analysts need it to render КП previews. Cross-company read is allowed
 *     only to superadmin (canAccessCompany already encodes this).
 *   - Write (PUT / POST logo): admin of the company OR superadmin (cross-company).
 *     analyst / viewer → 403.
 *
 * The company is taken from the explicit {id} URL segment (NOT the active
 * company), so access is verified via User::canAccessCompany($id).
 */
class CompanyBrandingController extends Controller
{
    /**
     * GET /api/companies/{id}/branding
     *
     * Returns the company's branding. When no row exists yet, returns the
     * default shape (empty logo + default palette/fonts) so the frontend always
     * has something to render against.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $company = Company::findOrFail($id);

        if (!$user->canAccessCompany($company->id)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $branding = $company->branding;

        return response()->json($this->buildPayload($company, $branding));
    }

    /**
     * PUT /api/companies/{id}/branding
     *
     * Upsert the branding (logo is handled by the separate POST endpoint). Write
     * ACL: admin of the company / superadmin.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $company = Company::findOrFail($id);

        if (!$this->canWrite($user, $company)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $validated = $request->validate([
            'colors'              => 'sometimes|nullable|array',
            'colors.primary'      => 'sometimes|nullable|string|max:32',
            'colors.secondary'    => 'sometimes|nullable|string|max:32',
            'colors.accent'       => 'sometimes|nullable|string|max:32',
            'colors.text'         => 'sometimes|nullable|string|max:32',
            'colors.bg'           => 'sometimes|nullable|string|max:32',
            'fonts'               => 'sometimes|nullable|array',
            'fonts.heading'       => 'sometimes|nullable|string|max:255',
            'fonts.body'          => 'sometimes|nullable|string|max:255',
            'header'              => 'sometimes|nullable|array',
            'header.ru'           => 'sometimes|nullable|string',
            'header.en'           => 'sometimes|nullable|string',
            'footer'              => 'sometimes|nullable|array',
            'footer.ru'           => 'sometimes|nullable|string',
            'footer.en'           => 'sometimes|nullable|string',
            'requisites'          => 'sometimes|nullable|array',
        ]);

        // Build the update payload from only the keys actually present in the
        // request, so a partial PUT (e.g. just colors) never wipes other fields.
        $attributes = ['company_id' => $company->id, 'updated_by' => $user->id];

        foreach (['colors', 'fonts', 'header', 'footer', 'requisites'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }

        $branding = CompanyBranding::updateOrCreate(
            ['company_id' => $company->id],
            $attributes,
        );

        return response()->json($this->buildPayload($company, $branding->fresh()));
    }

    /**
     * POST /api/companies/{id}/branding/logo
     *
     * Upload the company logo to disk `public` (served via APP_URL/storage).
     * Write ACL: admin of the company / superadmin. Old logo (if any) is
     * removed after the new one is stored.
     */
    public function uploadLogo(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $company = Company::findOrFail($id);

        if (!$this->canWrite($user, $company)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $request->validate([
            // image rule + an explicit mime whitelist (svg is not covered by the
            // bare `image` rule, so it is listed by extension as well).
            'logo' => [
                'required',
                'file',
                'mimes:png,jpg,jpeg,svg,webp',
                'max:2048', // KB → 2 MB
            ],
        ]);

        $file = $request->file('logo');

        $branding = CompanyBranding::firstOrNew(['company_id' => $company->id]);
        $oldPath = $branding->logo_path;

        // Stored under a per-company prefix so logos never collide across
        // companies. A random filename avoids cache-stale issues on re-upload.
        $path = $file->store("branding/{$company->id}", 'public');

        $branding->company_id = $company->id;
        $branding->logo_path = $path;
        $branding->updated_by = $user->id;
        $branding->save();

        // Best-effort cleanup of the previous logo (ignore if missing).
        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'logo_path' => $path,
            'logo_url'  => Storage::disk('public')->url($path),
        ]);
    }

    /**
     * Write-ACL helper: admin of THIS company OR superadmin (cross-company).
     * Mirrors the role checks used across WidgetController / ReportController,
     * but keyed off the explicit {id} company rather than the active company.
     */
    private function canWrite($user, Company $company): bool
    {
        if ($user->role === 'superadmin') {
            return true;
        }

        if ($user->role === 'admin' && $user->canAccessCompany($company->id)) {
            return true;
        }

        return false;
    }

    /**
     * Build the branding response. When $branding is null (company has no row
     * yet) returns the default shape so the frontend always has values.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Company $company, ?CompanyBranding $branding): array
    {
        $logoPath = $branding?->logo_path;

        return [
            'company_id' => $company->id,
            'logo_path'  => $logoPath,
            'logo_url'   => $logoPath !== null ? Storage::disk('public')->url($logoPath) : null,
            'colors'     => $branding?->colors ?? CompanyBranding::DEFAULT_COLORS,
            'fonts'      => $branding?->fonts ?? CompanyBranding::DEFAULT_FONTS,
            // Multilingual {ru,en} map — frontend resolves by locale. Empty map
            // ([]) is returned as null so the frontend can treat "unset".
            'header'     => $this->translatableOrNull($branding, 'header'),
            'footer'     => $this->translatableOrNull($branding, 'footer'),
            'requisites' => $branding?->requisites,
        ];
    }

    /**
     * Resolve a translatable branding field to its {locale => value} map, or
     * null when absent / empty. Uses getTranslations() so it reflects current
     * model state regardless of persistence.
     *
     * @return array<string, string>|null
     */
    private function translatableOrNull(?CompanyBranding $branding, string $field): ?array
    {
        if ($branding === null) {
            return null;
        }

        $map = $branding->getTranslations($field);

        return $map === [] ? null : $map;
    }
}
