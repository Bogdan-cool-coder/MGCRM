<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Services;

use App\Domain\Contracts\Enums\TemplateVariableType;
use App\Domain\Contracts\Models\Document;
use App\Domain\Contracts\Models\TemplateVariable;
use App\Domain\Contracts\Services\Helpers\MoneyFormatter;
use App\Domain\Contracts\Services\Helpers\NumberToWordsHelper;
use App\Domain\Crm\Models\Company;
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
        $licensor = (array) ($yamlCtx['licensor'] ?? []);

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
     * Build sublicensee.* keys from Company relation or context['sublicensee'].
     *
     * @return array<string, string>
     */
    private function buildSublicensee(Document $doc): array
    {
        // Priority: source_company_id relation > context['sublicensee']
        if ($doc->source_company_id !== null) {
            $company = Company::query()->find($doc->source_company_id);
            if ($company !== null) {
                return $this->flattenSection('sublicensee', [
                    'name' => $company->name ?? '',
                    'director_genitive' => $company->extra_fields['director_genitive'] ?? '',
                    'director_short' => $company->extra_fields['director_short'] ?? '',
                    'tax_id' => $company->extra_fields['tax_id'] ?? '',
                    'address' => $company->extra_fields['address'] ?? $company->city ?? '',
                    'bank' => $company->extra_fields['bank'] ?? '',
                    'account' => $company->extra_fields['account'] ?? '',
                    'phone' => $company->phone ?? '',
                    'email' => $company->email ?? '',
                ]);
            }
        }

        $sublicensee = (array) ($doc->context['sublicensee'] ?? []);

        return $this->flattenSection('sublicensee', $sublicensee);
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

        foreach ($variables as $var) {
            $rawValue = $customValues[$var->key] ?? null;
            $hasDefault = $var->default_value !== null && trim($var->default_value) !== '';
            $hasValue = $rawValue !== null && trim((string) $rawValue) !== '';

            // Validate required
            if ($var->required && $var->var_type !== TemplateVariableType::Checkbox) {
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
