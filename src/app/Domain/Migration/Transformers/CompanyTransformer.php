<?php

declare(strict_types=1);

namespace App\Domain\Migration\Transformers;

use App\Domain\Crm\Enums\CompanySpecialization;
use App\Domain\Migration\Support\AmoFieldReader;
use App\Domain\Migration\Support\AmoFields;
use App\Domain\Migration\Support\AmoReferenceResolver;

/**
 * CompanyTransformer — pure AMO company (or lead custom fields) → MGCRM Company
 * + current CompanyRequisite attribute arrays. Temporary migration bounded-
 * context (dropped at M12).
 *
 * No DB writes: the loader consumes the returned arrays and resolves the few
 * FKs that are passed through pre-resolved by the resolver (channel id only —
 * country/spec/label are pure map lookups done here). country_map collapses all
 * RF regions to 'ru' and stashes the original region label in
 * extra_fields.amo_region; an unmapped "Иное государство" / blank country yields
 * a null country_code.
 *
 * The geo / tax fields (country, tax id) live on the AMO LEAD, not the company
 * (build plan §11), so transformCompany() takes the lead reader for those.
 *
 * The CONTACT fields (phone / email / website / address) live on the AMO COMPANY
 * object itself (confirmed on live data, CF ids 2709 / 2711 / 2713 / 2717), NOT on
 * the lead. Phone/email are AMO multitext (multi-value): we denormalise the PRIMARY
 * value (first; a WORK-coded value wins if present) onto the company row and stash
 * any extra values under extra_fields.amo_company_phones / amo_company_emails
 * (DEC-E: the migration does not create company_channels rows). website/address are
 * single-value fields read straight through.
 */
final class CompanyTransformer
{
    public function __construct(
        private readonly AmoReferenceResolver $resolver,
    ) {}

    /**
     * Build company + requisite attrs from an AMO company object plus the owning
     * lead's custom fields (country / tax id live on the lead).
     *
     * @param  array<string, mixed>  $amoCompany  Raw AMO company (or lead _embedded company stub).
     * @param  array<string, mixed>  $amoLead  The owning lead (for country / tax id).
     * @return array{
     *     amo_id: int,
     *     company: array<string, mixed>,
     *     requisite: array<string, mixed>,
     *     created_by_amo_id: ?int
     * }
     */
    public function transform(array $amoCompany, array $amoLead): array
    {
        $companyFields = AmoFieldReader::for($amoCompany);
        $leadFields = AmoFieldReader::for($amoLead);

        $name = $this->resolveName($amoCompany, $companyFields);
        $legalName = $companyFields->string(AmoFields::COMPANY_LEGAL_NAME);

        [$countryCode, $regionLabel] = $this->resolveCountry($leadFields);
        $taxId = $this->cleanTaxId($leadFields->string(AmoFields::LEAD_TAX_ID));
        $taxIdLabel = $this->resolver->taxIdLabel($countryCode);

        $specialization = $this->resolveSpecialization($companyFields);
        $channelEnum = $companyFields->enumId(AmoFields::COMPANY_CHANNEL);
        $channelId = $this->resolver->channelIdForEnum($channelEnum);

        // Contact fields — read off the COMPANY object (CF 2709/2711/2713/2717).
        [$phone, $extraPhones] = $this->resolveMultiContactField($companyFields, AmoFields::COMPANY_PHONE);
        [$email, $extraEmails] = $this->resolveMultiContactField($companyFields, AmoFields::COMPANY_EMAIL);
        $website = $companyFields->string(AmoFields::COMPANY_WEBSITE);
        $address = $companyFields->string(AmoFields::COMPANY_ADDRESS);

        $extraFields = [];
        if ($regionLabel !== null) {
            $extraFields['amo_region'] = $regionLabel;
        }
        // DEC-E: no company_channels — extra phone/email values are stashed here.
        if ($extraPhones !== []) {
            $extraFields['amo_company_phones'] = $extraPhones;
        }
        if ($extraEmails !== []) {
            $extraFields['amo_company_emails'] = $extraEmails;
        }

        $company = [
            'name' => $name,
            'legal_name' => $legalName,
            'tax_id' => $taxId,
            'tax_id_label' => $taxIdLabel,
            'country_code' => $countryCode,
            'specialization' => $specialization?->value,
            'acquisition_channel_id' => $channelId,
            'phone' => $phone,
            'email' => $email,
            'website' => $website,
            'address' => $address,
            // extra_fields is a NOT NULL json column (default '{}') — always an
            // array, never null.
            'extra_fields' => $extraFields,
        ];

        $requisite = [
            'legal_name' => $legalName,
            'tax_id' => $taxId,
            'tax_id_label' => $taxIdLabel,
            'country_code' => $countryCode,
            'is_current' => true,
        ];

        return [
            'amo_id' => (int) ($amoCompany['id'] ?? 0),
            'company' => $company,
            'requisite' => $requisite,
            'created_by_amo_id' => isset($amoCompany['created_by']) ? (int) $amoCompany['created_by'] : null,
        ];
    }

    /**
     * Synthesize a "physical person" company from a lead's primary contact when
     * the lead has no company at all (DEC-B). name = «<ФИО> (физлицо)».
     *
     * @param  array<string, mixed>  $amoLead
     * @param  array<string, mixed>|null  $primaryContact  Raw AMO contact, or null.
     * @return array{amo_id: int, company: array<string, mixed>, requisite: array<string, mixed>, created_by_amo_id: ?int}
     */
    public function transformFromContact(array $amoLead, ?array $primaryContact): array
    {
        $leadFields = AmoFieldReader::for($amoLead);
        [$countryCode, $regionLabel] = $this->resolveCountry($leadFields);
        $taxId = $this->cleanTaxId($leadFields->string(AmoFields::LEAD_TAX_ID));
        $taxIdLabel = $this->resolver->taxIdLabel($countryCode);

        $personName = $primaryContact !== null
            ? trim((string) ($primaryContact['name'] ?? ''))
            : '';

        $name = $personName !== ''
            ? $personName.' (физлицо)'
            : 'Без контрагента (импорт)';

        $extraFields = ['amo_synthetic_company' => true];
        if ($regionLabel !== null) {
            $extraFields['amo_region'] = $regionLabel;
        }

        return [
            'amo_id' => 0, // synthetic — keyed by the lead in the loader (no AMO company id)
            'company' => [
                'name' => $name,
                'legal_name' => null,
                'tax_id' => $taxId,
                'tax_id_label' => $taxIdLabel,
                'country_code' => $countryCode,
                'specialization' => null,
                'acquisition_channel_id' => null,
                'phone' => null,
                'email' => null,
                'website' => null,
                'address' => null,
                'extra_fields' => $extraFields,
            ],
            'requisite' => [
                'legal_name' => null,
                'tax_id' => $taxId,
                'tax_id_label' => $taxIdLabel,
                'country_code' => $countryCode,
                'is_current' => true,
            ],
            'created_by_amo_id' => isset($amoLead['created_by']) ? (int) $amoLead['created_by'] : null,
        ];
    }

    /**
     * Read an AMO multitext field (phone / email) off the company. Returns the
     * PRIMARY value plus any extras. The primary is the first WORK-coded value if
     * one is present, else the first value overall; the remaining values (in source
     * order, deduped) are the extras stashed in extra_fields.
     *
     * @return array{0: ?string, 1: list<string>} [primary, extras]
     */
    private function resolveMultiContactField(AmoFieldReader $fields, int $fieldId): array
    {
        $values = [];

        foreach ($fields->values($fieldId) as $row) {
            $raw = trim((string) ($row['value'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $code = isset($row['enum_code']) ? mb_strtoupper((string) $row['enum_code']) : null;
            $values[] = ['value' => $raw, 'is_work' => $code === 'WORK'];
        }

        if ($values === []) {
            return [null, []];
        }

        // Prefer a WORK-coded value as primary; else the first value.
        $primaryIndex = 0;
        foreach ($values as $i => $row) {
            if ($row['is_work']) {
                $primaryIndex = $i;
                break;
            }
        }

        $primary = $values[$primaryIndex]['value'];

        $extras = [];
        foreach ($values as $i => $row) {
            if ($i === $primaryIndex) {
                continue;
            }
            $extras[] = $row['value'];
        }

        return [$primary, array_values(array_unique($extras))];
    }

    /**
     * @param  array<string, mixed>  $amoCompany
     */
    private function resolveName(array $amoCompany, AmoFieldReader $fields): string
    {
        $embedded = trim((string) ($amoCompany['name'] ?? ''));

        if ($embedded !== '') {
            return $embedded;
        }

        $cf = $fields->string(AmoFields::COMPANY_NAME);

        return $cf ?? 'Компания (импорт)';
    }

    /**
     * @return array{0: ?string, 1: ?string} [iso_country_code, original_region_label]
     */
    private function resolveCountry(AmoFieldReader $leadFields): array
    {
        $enumId = $leadFields->enumId(AmoFields::LEAD_COUNTRY);

        if ($enumId === null) {
            return [null, null];
        }

        $label = $leadFields->string(AmoFields::LEAD_COUNTRY);
        $code = config('amo_migration.country_map.'.$enumId);
        $iso = is_string($code) ? $code : null;

        // Keep the original RF-region label so nothing is lost when all regions
        // collapse to 'ru'. Foreign countries keep their label too (harmless).
        $regionLabel = $iso === 'ru' ? $label : null;

        return [$iso, $regionLabel];
    }

    private function resolveSpecialization(AmoFieldReader $fields): ?CompanySpecialization
    {
        // Multiselect → take the FIRST mappable option (config maps unmappable
        // options to null, which we skip until we hit a real target).
        foreach ($fields->enumIds(AmoFields::COMPANY_SPECIALIZATION) as $enumId) {
            $target = config('amo_migration.specialization_map.'.$enumId);

            if (is_string($target) && ($case = CompanySpecialization::tryFrom($target)) !== null) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Drop the well-known AMO tax-id noise (single chars, dashes, all-zero).
     */
    private function cleanTaxId(?string $taxId): ?string
    {
        if ($taxId === null) {
            return null;
        }

        $taxId = trim($taxId);

        if ($taxId === '' || in_array($taxId, ['0', '1', '-', '—', 'нет', '0000000000'], true)) {
            return null;
        }

        return $taxId;
    }
}
