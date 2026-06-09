<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * PromotionController — CRUD for per-company discount campaigns (promotions).
 *
 * A promotion bounds the discount an analyst/viewer may apply to an HTML
 * commercial proposal ([discount_min, discount_max]); admins manage the
 * promotions themselves.
 *
 * Visibility / ACL (active company resolved by ResolveActiveCompany middleware,
 * never from a query-param — switch via POST /api/active-company/{id} first):
 *   - read (index / show): any role with access to the active company. Analyst
 *     and viewer need to see promotions to pick one and set a discount in range.
 *   - write (store / update / destroy): admin of the active company /
 *     superadmin (cross-company). analyst / viewer are read-only.
 *
 * Promotions are strictly company-scoped: a promotion of another company is
 * neither visible (index / show 403) nor writable.
 */
class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        $query = Promotion::query()->where('company_id', $companyId);

        // ?active=1 — only active promotions (the picker on the КП page).
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $query->orderByRaw('sort_order is null, sort_order asc')->orderBy('id');

        return response()->json(
            $query->get()->map(fn (Promotion $p) => $this->buildPromotionPayload($p))
        );
    }

    public function show(Request $request, Promotion $promotion): JsonResponse
    {
        $user = $request->user();
        $companyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        // Company-scoping: a promotion of another company is invisible. superadmin
        // is bound to the active company too — switch companies to view theirs.
        if ((int) $promotion->company_id !== $companyId) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        return response()->json($this->buildPromotionPayload($promotion));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = (int) $request->attributes->get('active_company_id', $user->company_id);

        if (!$this->canWrite($request)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $this->validatePromotion($request);

        $promotion = Promotion::create([
            'company_id'    => $companyId,
            'name'          => $data['name'],
            'description'   => $data['description'] ?? null,
            'discount_type' => $data['discount_type'],
            'discount_min'  => $data['discount_min'],
            'discount_max'  => $data['discount_max'],
            'is_active'     => $data['is_active'] ?? true,
            'sort_order'    => $data['sort_order'] ?? null,
            'created_by'    => $user->id,
        ]);

        return response()->json($this->buildPromotionPayload($promotion), 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $companyId = (int) $request->attributes->get('active_company_id', $request->user()->company_id);

        if ((int) $promotion->company_id !== $companyId || !$this->canWrite($request)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $data = $this->validatePromotion($request, $promotion);

        // Only assign keys that were actually supplied — keep this PUT partial-
        // friendly while still validating the resulting min<=max / percent<=100
        // invariants inside validatePromotion().
        $update = [];
        foreach (['name', 'description', 'discount_type', 'discount_min', 'discount_max', 'is_active', 'sort_order'] as $key) {
            if (array_key_exists($key, $data)) {
                $update[$key] = $data[$key];
            }
        }

        $promotion->update($update);

        return response()->json($this->buildPromotionPayload($promotion->fresh()));
    }

    public function destroy(Request $request, Promotion $promotion): JsonResponse
    {
        $companyId = (int) $request->attributes->get('active_company_id', $request->user()->company_id);

        if ((int) $promotion->company_id !== $companyId || !$this->canWrite($request)) {
            return response()->json(['message' => __('auth.forbidden')], 403);
        }

        $promotion->delete();

        return response()->json(['message' => __('promotions.deleted')]);
    }

    /**
     * Write-ACL (store / update / delete): admin of the active company OR
     * superadmin. analyst / viewer are read-only. Active company is the source
     * of truth; superadmin is admitted while scoped to the active company (the
     * company-match guard on update/destroy keeps cross-company writes out).
     */
    private function canWrite(Request $request): bool
    {
        return in_array($request->user()->role, ['superadmin', 'admin'], true);
    }

    /**
     * Validate a promotion payload (store: all required; update: partial via
     * `sometimes`). Enforces the cross-field invariants the DB cannot:
     *   - discount_min <= discount_max
     *   - percent type: discount_max <= 100
     *
     * On update the missing-half of the min/max pair is back-filled from the
     * persisted row so the comparison is always against the resulting state.
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function validatePromotion(Request $request, ?Promotion $existing = null): array
    {
        $required = $existing === null ? 'required' : 'sometimes';

        $data = $request->validate([
            'name'          => "{$required}|array",
            'name.ru'       => 'sometimes|nullable|string',
            'name.en'       => 'sometimes|nullable|string',
            'description'   => 'sometimes|nullable|array',
            'discount_type' => "{$required}|in:percent,absolute",
            'discount_min'  => "{$required}|numeric|min:0",
            'discount_max'  => "{$required}|numeric|min:0",
            'is_active'     => 'sometimes|boolean',
            'sort_order'    => 'sometimes|nullable|integer',
        ]);

        // Resolve the effective min / max / type after this request (for update,
        // unsupplied fields fall back to the stored value).
        $min  = array_key_exists('discount_min', $data) ? (float) $data['discount_min'] : (float) ($existing?->discount_min ?? 0);
        $max  = array_key_exists('discount_max', $data) ? (float) $data['discount_max'] : (float) ($existing?->discount_max ?? 0);
        $type = $data['discount_type'] ?? $existing?->discount_type ?? Promotion::TYPE_PERCENT;

        $errors = [];

        if ($min > $max) {
            $errors['discount_min'] = [__('promotions.min_gt_max')];
        }

        if ($type === Promotion::TYPE_PERCENT && $max > 100) {
            $errors['discount_max'] = [__('promotions.percent_over_100')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * Tight projection of a Promotion for API responses. Localized name /
     * description are returned as the raw {ru, en} maps (the frontend resolves
     * to the active locale), mirroring buildWidgetPayload / buildDocumentPayload.
     *
     * @return array<string, mixed>
     */
    private function buildPromotionPayload(Promotion $promotion): array
    {
        return [
            'id'            => $promotion->id,
            'company_id'    => $promotion->company_id,
            'name'          => json_decode($promotion->getRawOriginal('name'), true),
            'description'   => $promotion->getRawOriginal('description') !== null
                ? json_decode($promotion->getRawOriginal('description'), true)
                : null,
            'discount_type' => $promotion->discount_type,
            // decimal:2 cast yields a string; expose as float for the frontend.
            'discount_min'  => (float) $promotion->discount_min,
            'discount_max'  => (float) $promotion->discount_max,
            'is_active'     => (bool) $promotion->is_active,
            'sort_order'    => $promotion->sort_order,
            'created_by'    => $promotion->created_by,
            'created_at'    => optional($promotion->created_at)->toIso8601String(),
            'updated_at'    => optional($promotion->updated_at)->toIso8601String(),
        ];
    }
}
