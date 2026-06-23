<?php

declare(strict_types=1);

namespace App\Domain\Migration\Transformers;

use App\Domain\Crm\Enums\ChannelType;
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
 * the lead. Phone/email are AMO multitext (multi-value): we fan those out into
 * company_channels rows (mirroring ContactTransformer) and denormalise the PRIMARY
 * value (first; a WORK-coded value wins if present) onto company.phone / company.email
 * / company.website for list/dedup queries. address (2717) is a single textarea field
 * written to company.address. website (2713) produces a single Website channel row.
 */
final class CompanyTransformer
{
    public function __construct(
        private readonly AmoReferenceResolver $resolver,
    ) {}

    /**
     * Build company + requisite attrs + channel rows from an AMO company object
     * plus the owning lead's custom fields (country / tax id live on the lead).
     *
     * @param  array<string, mixed>  $amoCompany  Raw AMO company (or lead _embedded company stub).
     * @param  array<string, mixed>  $amoLead  The owning lead (for country / tax id).
     * @return array{
     *     amo_id: int,
     *     company: array<string, mixed>,
     *     requisite: array<string, mixed>,
     *     channels: list<array<string, mixed>>,
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
        $phoneRows = $this->resolveMultiContactFieldRows($companyFields, AmoFields::COMPANY_PHONE);
        $emailRows = $this->resolveMultiContactFieldRows($companyFields, AmoFields::COMPANY_EMAIL);
        $website = $companyFields->string(AmoFields::COMPANY_WEBSITE);
        $address = $companyFields->string(AmoFields::COMPANY_ADDRESS);

        // Denormalize primary values onto the company row (for list/dedup queries).
        $phone = $phoneRows[0]['value'] ?? null;
        $email = $emailRows[0]['value'] ?? null;

        // Build company_channels rows (mirrors ContactTransformer channel fan-out).
        $channels = [];
        foreach ($phoneRows as $row) {
            $channels[] = [
                'channel_type' => ChannelType::Phone->value,
                'value' => $row['value'],
                'label' => $row['label'],
                'is_primary_for_channel' => $row['is_first'],
            ];
        }
        foreach ($emailRows as $row) {
            $channels[] = [
                'channel_type' => ChannelType::Email->value,
                'value' => $row['value'],
                'label' => $row['label'],
                'is_primary_for_channel' => $row['is_first'],
            ];
        }
        if ($website !== null && $website !== '') {
            $channels[] = [
                'channel_type' => ChannelType::Website->value,
                'value' => $website,
                'label' => null,
                'is_primary_for_channel' => true,
            ];
        }

        $extraFields = [];
        if ($regionLabel !== null) {
            $extraFields['amo_region'] = $regionLabel;
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
            'channels' => $channels,
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
            'channels' => [], // synthetic company has no AMO contact fields
            'created_by_amo_id' => isset($amoLead['created_by']) ? (int) $amoLead['created_by'] : null,
        ];
    }

    /**
     * Read an AMO multitext field (phone / email) off the company and return a list
     * of channel rows, each with value / label / is_first. Mirrors ContactTransformer::channelValues().
     *
     * The first WORK-coded value wins as primary (is_first=true) if one is present,
     * else the first value overall is primary.
     *
     * @return list<array{value: string, label: ?string, is_first: bool}>
     */
    private function resolveMultiContactFieldRows(AmoFieldReader $fields, int $fieldId): array
    {
        $raw = [];

        foreach ($fields->values($fieldId) as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $label = isset($row['enum_code']) ? (string) $row['enum_code'] : null;
            $isWork = $label !== null && mb_strtoupper($label) === 'WORK';
            $raw[] = ['value' => $value, 'label' => $label, 'is_work' => $isWork];
        }

        if ($raw === []) {
            return [];
        }

        // Prefer a WORK-coded value as primary; else the first value.
        $primaryIndex = 0;
        foreach ($raw as $i => $row) {
            if ($row['is_work']) {
                $primaryIndex = $i;
                break;
            }
        }

        $out = [];
        $seen = [];
        // Put primary first.
        $primary = $raw[$primaryIndex];
        if (! in_array($primary['value'], $seen, true)) {
            $out[] = ['value' => $primary['value'], 'label' => $primary['label'], 'is_first' => true];
            $seen[] = $primary['value'];
        }
        foreach ($raw as $i => $row) {
            if ($i === $primaryIndex) {
                continue;
            }
            if (in_array($row['value'], $seen, true)) {
                continue;
            }
            $out[] = ['value' => $row['value'], 'label' => $row['label'], 'is_first' => false];
            $seen[] = $row['value'];
        }

        return $out;
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
