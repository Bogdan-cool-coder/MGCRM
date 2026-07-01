<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Enums\DocumentKind;
use App\Domain\Contracts\Enums\TemplateVariableType;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\LicensorEntity;
use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Contracts\Services\Helpers\MoneyFormatter;
use App\Domain\Contracts\Services\Helpers\NumberToWordsHelper;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Services\CompanyRequisiteService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ContractContextBuilder — assembles the flat key→value substitution map for
 * PHPWord TemplateProcessor::setValue().
 *
 * Source layers (in priority order):
 *   1. product_*.yaml   → product.*
 *   2. country_*.yaml   → country.*, licensor.*
 *   3. LicensorEntity   → licensor.* (overrides YAML)
 *   4. Document         → contract.*, pricing.*, license.*, payments.*
 *   5. Company          → sublicensee.*
 *   6. Document.context → custom.*  (manager-filled variables)
 *
 * All keys use dot notation: «licensor.name», «contract.number», etc.
 * PHPWord TemplateProcessor accepts dotted keys as-is (${licensor.name}).
 *
 * Returns a flat array<string, string> suitable for setValue().
 * cloneRow items (document items, payments) are NOT in this map —
 * they are handled separately in ContractGenerationService.
 *
 * @throws ValidationException when required TemplateVariables are missing values
 */
class ContractContextBuilder
{
    public function __construct(
        private readonly YamlTemplateParser $yamlParser,
        private readonly CompanyRequisiteService $requisiteService,
        private readonly LicensorService $licensorService,
    ) {}

    /**
     * Build the flat substitution context for a Document.
     *
     * @return array<string, string>
     *
     * @throws ValidationException when required custom variables are not filled
     */
    public function build(Document $doc): array
    {
        // 1. YAML layers
        $yamlCtx = $this->yamlParser->buildContext(
            $doc->product_code,
            $doc->country_code,
            (array) ($doc->context['custom'] ?? []),
        );

        $product = (array) ($yamlCtx['product'] ?? []);
        $country = (array) ($yamlCtx['country'] ?? []);
        // Start with YAML licensor as base, then override with DB entity.
        $licensor = (array) ($yamlCtx['licensor'] ?? []);

        // Resolve licensor via LicensorService (supports override_id from context).
        $overrideId = isset($doc->context['licensor_override_id'])
            ? (int) $doc->context['licensor_override_id']
            : null;
        $licensorEntity = $this->licensorService->forCountry($doc->country_code, $overrideId ?: null);

        if ($licensorEntity !== null) {
            // Merge entity scalar fields into licensor context array.
            $entityData = $licensorEntity->toArray();
            // Remove nested relations before flattening.
            unset($entityData['bank_accounts']);
            $licensor = array_merge($licensor, $entityData);

            // Per-currency bank account (primary for contract currency).
            $currency = $doc->currency ?? 'RUB';
            $bankAccount = $this->licensorService->primaryAccountForCurrency($licensorEntity, $currency);
            if ($bankAccount !== null) {
                // Override bank fields with per-currency account data.
                $licensor['bank'] = $bankAccount->bank ?? ($licensor['bank'] ?? '');
                $licensor['bank_code_label'] = $bankAccount->bank_code_label ?? ($licensor['bank_code_label'] ?? '');
                $licensor['bank_code'] = $bankAccount->bank_code ?? ($licensor['bank_code'] ?? '');
                $licensor['account'] = $bankAccount->account ?? ($licensor['account'] ?? '');
                $licensor['swift'] = $bankAccount->swift ?? ($licensor['swift'] ?? '');
            }
        }

        $flat = [];

        // 2. product.*
        $flat += $this->flattenSection('product', $product);

        // 3. country.*
        $flat += $this->flattenSection('country', $country);

        // 4. licensor.*
        $flat += $this->flattenSection('licensor', $licensor);

        // 5. sublicensee.*
        $flat += $this->buildSublicensee($doc);

        // 6. contract.*
        $flat += $this->buildContractSection($doc);

        // 7. license.*
        $docContext = (array) ($doc->context ?? []);
        $flat += $this->buildLicenseSection($doc, $docContext);

        // 8. pricing.*
        $flat += $this->buildPricingSection($doc);

        // 9. total_in_words (top-level key)
        $flat['total_in_words'] = NumberToWordsHelper::toWords($doc->total, $doc->currency ?? 'RUB');

        // 10. custom.* — validate required variables, then typecast values
        $flat += $this->buildCustomSection($doc);

        return $flat;
    }

    /**
     * Build the items array for cloneRow (NOT part of the flat map).
     * Returns array of row maps.
     *
     * @return list<array<string, string>>
     */
    public function buildItemRows(Document $doc): array
    {
        $items = $doc->items()->orderBy('sort_order')->get();
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'item_name' => (string) $item->name_snapshot,
                'item_qty' => rtrim(rtrim(number_format((float) $item->qty, 3, '.', ''), '0'), '.'),
                'item_price' => MoneyFormatter::format($item->unit_price, $item->currency ?? $doc->currency ?? 'RUB'),
                'item_total' => MoneyFormatter::format($item->line_total, $item->currency ?? $doc->currency ?? 'RUB'),
            ];
        }

        return $rows;
    }

    // ---- Private helpers ----

    /**
     * Recursively flatten a nested array into dot-prefixed keys.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function flattenSection(string $prefix, array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $fullKey = "{$prefix}.{$key}";
            if (is_array($value)) {
                if (empty($value)) {
                    $result[$fullKey] = '';

                    continue;
                }

                // For simple array values, join with comma; nested sub-arrays get further flattened.
                $hasStringKeys = array_keys($value) !== range(0, count($value) - 1);
                if ($hasStringKeys) {
                    $result += $this->flattenSection($fullKey, $value);
                } else {
                    // Sequential array — check if elements are scalars or sub-arrays.
                    $allScalar = array_reduce($value, static fn (bool $c, mixed $v) => $c && ! is_array($v), true);
                    if ($allScalar) {
                        $result[$fullKey] = implode(', ', array_map('strval', $value));
                    } else {
                        // Nested array of objects (e.g. bank_accounts) — skip for flat context.
                        $result[$fullKey] = '';
                    }
                }
            } else {
                $result[$fullKey] = (string) ($value ?? '');
            }
        }

        return $result;
    }

    /**
     * Build sublicensee.* keys.
     *
     * Priority (highest → lowest):
     *  1. Document.company_requisite_id pin  — the snapshot requisite explicitly
     *     chosen at document creation time. This is the correct approach for
     *     multiple-requisite companies and fixes the legacy extra_fields bug
     *     where fields like director_genitive lived outside Company columns.
     *  2. Company current requisite          — fallback when pin is absent but
     *     source_company_id is present (auto-resolve current set).
     *  3. context['sublicensee']             — legacy / manual override stored
     *     in the JSONB context (free-standing documents without a company link).
     *
     * @return array<string, string>
     */
    private function buildSublicensee(Document $doc): array
    {
        // 1. Pinned requisite (preferred)
        if ($doc->company_requisite_id !== null) {
            $requisite = CompanyRequisite::query()->find($doc->company_requisite_id);
            if ($requisite !== null) {
                return $this->buildSublicenseeFromRequisite($requisite, $doc);
            }
        }

        // 2. Company current requisite (auto-resolve)
        if ($doc->source_company_id !== null) {
            $company = Company::query()->find($doc->source_company_id);
            if ($company !== null) {
                $current = $this->requisiteService->current($company);
                if ($current !== null) {
                    return $this->buildSublicenseeFromRequisite($current, $doc);
                }

                // Fallback to Company columns (legacy denorm mirror)
                return $this->buildSublicenseeFromCompany($company);
            }
        }

        // 3. Manual context override
        $sublicensee = (array) ($doc->context['sublicensee'] ?? []);

        return $this->flattenSection('sublicensee', $sublicensee);
    }

    /**
     * Build sublicensee section from a CompanyRequisite (the canonical source).
     *
     * @return array<string, string>
     */
    private function buildSublicenseeFromRequisite(CompanyRequisite $requisite, Document $doc): array
    {
        $company = $requisite->company ?? Company::query()->find($requisite->company_id);

        $bankDetails = (array) ($requisite->bank_details ?? []);

        return $this->flattenSection('sublicensee', [
            'name' => $company?->name ?? '',
            'legal_name' => (string) ($requisite->legal_name ?? ''),
            'full_legal_form' => (string) ($requisite->full_legal_form ?? ''),
            'legal_form' => (string) ($requisite->legal_form ?? ''),
            'director_genitive' => (string) ($requisite->director_genitive ?? ''),
            'director_short' => (string) ($requisite->director_short ?? ''),
            'director_position' => (string) ($requisite->director_position ?? ''),
            'acts_basis' => (string) ($requisite->acts_basis ?? ''),
            'tax_id_label' => (string) ($requisite->tax_id_label ?? ''),
            'tax_id' => (string) ($requisite->tax_id ?? ''),
            'address' => (string) ($requisite->address ?? ''),
            'bank' => (string) ($bankDetails['bank'] ?? ''),
            'bank_code' => (string) ($bankDetails['bank_code'] ?? ''),
            'account' => (string) ($bankDetails['account'] ?? ''),
            'phone' => (string) ($company?->phone ?? ''),
            'email' => (string) ($company?->email ?? ''),
        ]);
    }

    /**
     * Build sublicensee section from Company columns (legacy denorm mirror fallback).
     *
     * @return array<string, string>
     */
    private function buildSublicenseeFromCompany(Company $company): array
    {
        return $this->flattenSection('sublicensee', [
            'name' => (string) ($company->name ?? ''),
            'legal_name' => (string) ($company->legal_name ?? ''),
            'full_legal_form' => (string) ($company->full_legal_form ?? ''),
            'legal_form' => (string) ($company->legal_form ?? ''),
            'director_genitive' => (string) ($company->director_genitive ?? ''),
            'director_short' => (string) ($company->director_short ?? ''),
            'director_position' => (string) ($company->director_position ?? ''),
            'acts_basis' => (string) ($company->acts_basis ?? ''),
            'tax_id_label' => (string) ($company->tax_id_label ?? ''),
            'tax_id' => (string) ($company->tax_id ?? ''),
            'address' => (string) ($company->address ?? ''),
            'bank' => (string) ($company->bank ?? ''),
            'bank_code' => (string) ($company->bank_code ?? ''),
            'account' => (string) ($company->account ?? ''),
            'phone' => (string) ($company->phone ?? ''),
            'email' => (string) ($company->email ?? ''),
        ]);
    }

    /**
     * Build contract.* keys (number, date parts, city).
     *
     * @return array<string, string>
     */
    private function buildContractSection(Document $doc): array
    {
        $date = now();

        return [
            'contract.number' => (string) ($doc->number ?? ''),
            'contract.date_day' => $date->format('d'),
            'contract.date_month' => $this->monthName((int) $date->format('n')),
            'contract.date_year' => $date->format('Y'),
            'contract.city' => (string) ($doc->city ?? ''),
            'contract.city_code' => (string) ($doc->city_code ?? ''),
            'contract.currency' => (string) ($doc->currency ?? ''),
            'contract.product_code' => (string) ($doc->product_code ?? ''),
            'contract.country_code' => strtoupper((string) ($doc->country_code ?? '')),
        ];
    }

    /**
     * Build license.* keys from Document.context['license'].
     *
     * @param  array<string, mixed>  $docContext
     * @return array<string, string>
     */
    private function buildLicenseSection(Document $doc, array $docContext): array
    {
        $license = (array) ($docContext['license'] ?? []);
        $currency = $doc->currency ?? 'RUB';

        return [
            'license.start_date' => MoneyFormatter::formatDateRu($license['start_date'] ?? null),
            'license.end_date' => MoneyFormatter::formatDateRu($license['end_date'] ?? null),
            'license.duration_months' => (string) ($license['duration_months'] ?? ''),
            'license.price_amount_text' => MoneyFormatter::format($doc->total, $currency),
            'license.price_amount_words' => NumberToWordsHelper::toWords($doc->total, $currency),
            'license.territory' => (string) ($license['territory'] ?? ''),
            'license.rights_type' => (string) ($license['rights_type'] ?? ''),
        ];
    }

    /**
     * Build pricing.* keys.
     *
     * @return array<string, string>
     */
    private function buildPricingSection(Document $doc): array
    {
        $currency = $doc->currency ?? 'RUB';

        return [
            'pricing.subtotal' => MoneyFormatter::format($doc->subtotal, $currency),
            'pricing.discount_pct' => ((string) $doc->discount_pct).'%',
            'pricing.discount_amount' => MoneyFormatter::format($doc->discount_amount, $currency),
            'pricing.total' => MoneyFormatter::format($doc->total, $currency),
            'pricing.currency' => $currency,
        ];
    }

    /**
     * Build custom.* keys from TemplateVariable catalogue + Document.context['custom'].
     * Validates required variables and typecasts values.
     *
     * @return array<string, string>
     *
     * @throws ValidationException when required variables are missing
     */
    private function buildCustomSection(Document $doc): array
    {
        $variables = TemplateVariable::active()
            ->forContext($doc->product_code, $doc->country_code)
            ->orderBy('sort_order')
            ->get();

        $customValues = (array) ($doc->context['custom'] ?? []);
        $missing = [];
        $result = [];

        // Termination-agreement variables are seeded required with empty
        // product/country wildcards, so forContext() returns them for EVERY
        // document. Their required-ness must be scoped to the termination flow,
        // otherwise generating a normal contract would 422 demanding fields that
        // only make sense for a дополнительное соглашение о расторжении. Values
        // still pass through when present; only the *required* gate is kind-scoped.
        $terminationKeys = DocumentKind::terminationVariableKeys();
        $isTermination = $doc->kind === DocumentKind::TerminationAgreement;

        foreach ($variables as $var) {
            $rawValue = $customValues[$var->key] ?? null;
            $hasDefault = $var->default_value !== null && trim($var->default_value) !== '';
            $hasValue = $rawValue !== null && trim((string) $rawValue) !== '';

            // Required is enforced unless this is a termination-scoped variable on
            // a non-termination document.
            $required = $var->required
                && ! (in_array($var->key, $terminationKeys, true) && ! $isTermination);

            // Validate required
            if ($required && $var->var_type !== TemplateVariableType::Checkbox) {
                if (! $hasValue && ! $hasDefault) {
                    $missing[] = $var->label;

                    continue;
                }
            }

            // Resolve final value
            $value = $hasValue ? $rawValue : ($var->default_value ?? '');

            // Typecast for document text
            $result["custom.{$var->key}"] = $this->castValue($var->var_type, $value);
        }

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'custom' => 'Заполните обязательные поля: '.implode(', ', $missing),
            ])->status(422);
        }

        // Also include any custom values that are not in the catalogue (pass-through)
        foreach ($customValues as $key => $value) {
            $catalogueKey = "custom.{$key}";
            if (! array_key_exists($catalogueKey, $result)) {
                $result[$catalogueKey] = (string) ($value ?? '');
            }
        }

        return $result;
    }

    /**
     * Cast a raw custom variable value to document-ready string.
     */
    private function castValue(TemplateVariableType $type, mixed $value): string
    {
        return match ($type) {
            TemplateVariableType::Checkbox => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Да' : 'Нет',
            TemplateVariableType::Date => $this->formatDate($value),
            TemplateVariableType::Number => is_numeric($value) ? number_format((float) $value, 2, ',', "\u{00A0}") : (string) $value,
            default => (string) ($value ?? ''),
        };
    }

    /**
     * Format a date value to DD.MM.YYYY (document standard).
     */
    private function formatDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $dt = Carbon::parse((string) $value);

            return $dt->format('d.m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * Russian month name in genitive case (for «13 июня 2026»).
     */
    private function monthName(int $month): string
    {
        return match ($month) {
            1 => 'января', 2 => 'февраля', 3 => 'марта',
            4 => 'апреля', 5 => 'мая', 6 => 'июня',
            7 => 'июля', 8 => 'августа', 9 => 'сентября',
            10 => 'октября', 11 => 'ноября', 12 => 'декабря',
            default => '',
        };
    }
}
