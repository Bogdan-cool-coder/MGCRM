<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\TemplateVariable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * TemplateVariableService — CRUD and wildcard-scoped listing for template variables.
 *
 * Wildcard behaviour: empty product_codes / country_codes = applies to all contexts.
 *
 * Delete guard (S2.2 extension):
 *   In S2.1 physical delete is allowed (Contract table doesn't exist yet).
 *   In S2.2 add: whereJsonContains('context->custom', key) → 409 guard.
 */
class TemplateVariableService
{
    /**
     * @return Collection<int, TemplateVariable>
     */
    public function list(bool $activeOnly = true, ?string $group = null): Collection
    {
        return TemplateVariable::query()
            ->when($activeOnly, fn ($q) => $q->active())
            ->when($group !== null, fn ($q) => $q->where('group', $group))
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();
    }

    /**
     * Return variables applicable to the given product/country context.
     * Wildcard: empty arrays match any value.
     *
     * @return Collection<int, TemplateVariable>
     */
    public function forContext(string $productCode, string $countryCode): Collection
    {
        return TemplateVariable::query()
            ->active()
            ->forContext($productCode, $countryCode)
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TemplateVariable
    {
        return TemplateVariable::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TemplateVariable $variable, array $data): TemplateVariable
    {
        $variable->update($data);
        $variable->refresh();

        return $variable;
    }

    /**
     * Delete a template variable.
     *
     * Guard (S2.2): if any Document stores this variable key in context->custom,
     * deletion is refused with a 409 abort. This protects the data integrity of
     * existing contracts that reference the variable.
     *
     * whereJsonContains on 'context->custom' works on both SQLite (json1) and
     * PostgreSQL (jsonb). The key presence check uses the Laravel built-in helper.
     */
    public function delete(TemplateVariable $variable): void
    {
        if (Schema::hasTable('documents')) {
            // Check if any document has a value set for this variable key.
            // context->custom is a JSON object like {"key": "value", ...}.
            // whereJsonContains checks if the key exists with the given value.
            // We check for the key's existence by searching for any non-null value.
            $inUse = Document::query()
                ->whereNotNull("context->custom->{$variable->key}")
                ->exists();

            if ($inUse) {
                abort(409, "Variable '{$variable->key}' is used in one or more documents.");
            }
        }

        $variable->delete();
    }
}
