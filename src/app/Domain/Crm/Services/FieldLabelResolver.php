<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Enums\CustomFieldScope;
use App\Domain\Crm\Models\CustomFieldDef;
use Illuminate\Support\Str;

/**
 * FieldLabelResolver — turns a raw audit/log field name into a human-readable,
 * localized (RU) label for the activity feed.
 *
 * The feed's field_change rows previously rendered the raw DB column name
 * (e.g. «изменил discount_percent → 10»); this resolver maps the column to its
 * proper label («изменил Скидка → 10»). Stage changes already read nicely
 * because they carry the localized stage name — this brings the rest of the
 * field-change track up to the same bar.
 *
 * Shared across the Sales deal feed (DealFeedService) and the CRM company/contact
 * feed (CrmFeedService). It lives in the Crm domain because Crm owns custom-field
 * metadata (custom_field_defs); Sales already depends on Crm services, so the
 * placement follows the established cross-domain direction.
 *
 * Resolution order for a given field:
 *   1. extra_fields.{code} → look the code up in custom_field_defs (label),
 *      falling back to a humanized version of the code (amo_cf_709732 → «709732»,
 *      foo_bar → «Foo bar») when the def is unknown. Never crashes on a miss.
 *   2. a scope-specific static map (deal / company / contact core columns).
 *   3. a shared core map (owner_user_id, source, … common to several entities).
 *   4. a humanized version of the raw column as a last resort (never the raw
 *      snake_case string).
 *
 * Custom-field def labels are memoised per scope so a feed page with many
 * extra_fields.* rows issues at most one query per scope.
 */
class FieldLabelResolver
{
    private const EXTRA_PREFIX = 'extra_fields.';

    /**
     * AMO-migrated custom-field code prefix. When the def is missing we drop this
     * prefix before humanizing so the fallback reads «709732», not «Amo cf 709732».
     */
    private const AMO_CODE_PREFIX = 'amo_cf_';

    /**
     * Deal core columns → RU labels. Keys are the exact strings stored in
     * deal_audits.field.
     *
     * @var array<string, string>
     */
    private const DEAL_LABELS = [
        'title' => 'Название',
        'amount' => 'Сумма',
        'discount_percent' => 'Скидка',
        'currency' => 'Валюта',
        'owner_user_id' => 'Ответственный',
        'company_id' => 'Компания',
        'stage_id' => 'Стадия',
        'pipeline_id' => 'Воронка',
        'expected_close_date' => 'Планируемая дата закрытия',
        'expected_sign_date' => 'Планируемая дата подписания',
        'expected_payment_date' => 'Планируемая дата оплаты',
        'signed_at' => 'Дата подписания',
        'paid_at' => 'Дата оплаты',
        'tags' => 'Теги',
        'perpetual_license' => 'Бессрочная лицензия',
        'lost_reason' => 'Причина отказа',
        'lost_reason_id' => 'Причина отказа',
        'contract_id' => 'Договор',
        'department_id' => 'Отдел',
        'amount_locked' => 'Сумма зафиксирована',
    ];

    /**
     * Company core columns → RU labels (mirrors CompanyService::LOGGED_FIELDS).
     *
     * @var array<string, string>
     */
    private const COMPANY_LABELS = [
        'name' => 'Название',
        'legal_name' => 'Юридическое название',
        'legal_form' => 'Организационно-правовая форма',
        'tax_id' => 'ИНН',
        'email' => 'Email',
        'phone' => 'Телефон',
        'website' => 'Сайт',
        'source' => 'Источник',
        'country_code' => 'Страна',
        'category_code' => 'Категория',
        'company_type_id' => 'Тип компании',
        'responsible_user_id' => 'Ответственный',
        'owner_user_id' => 'Владелец',
    ];

    /**
     * Contact core columns → RU labels (mirrors ContactService::LOGGED_FIELDS).
     *
     * @var array<string, string>
     */
    private const CONTACT_LABELS = [
        'full_name' => 'ФИО',
        'name' => 'Имя',
        'email' => 'Email',
        'phone' => 'Телефон',
        'position' => 'Должность',
        'status' => 'Статус',
        'source' => 'Источник',
        'owner_id' => 'Владелец',
        'owner_user_id' => 'Владелец',
    ];

    /**
     * Cross-entity fallbacks — common columns that may appear on more than one
     * scope. Consulted after the scope-specific map.
     *
     * @var array<string, string>
     */
    private const SHARED_LABELS = [
        'owner_user_id' => 'Ответственный',
        'owner_id' => 'Ответственный',
        'source' => 'Источник',
        'email' => 'Email',
        'phone' => 'Телефон',
        'tags' => 'Теги',
    ];

    /**
     * Memoised code→label maps per scope, so a feed page with N extra_fields.*
     * rows resolves them all from a single query per scope.
     *
     * @var array<string, array<string, string>>
     */
    private array $customFieldCache = [];

    /**
     * Resolve a raw deal-audit field name to a RU label.
     */
    public function forDeal(string $field): string
    {
        return $this->resolve($field, CustomFieldScope::Deal, self::DEAL_LABELS);
    }

    /**
     * Resolve a raw company field name to a RU label.
     */
    public function forCompany(string $field): string
    {
        return $this->resolve($field, CustomFieldScope::Company, self::COMPANY_LABELS);
    }

    /**
     * Resolve a raw contact field name to a RU label.
     */
    public function forContact(string $field): string
    {
        return $this->resolve($field, CustomFieldScope::Contact, self::CONTACT_LABELS);
    }

    /**
     * Core resolution: custom field → scope map → shared map → humanized fallback.
     *
     * @param  array<string, string>  $scopeLabels
     */
    private function resolve(string $field, CustomFieldScope $scope, array $scopeLabels): string
    {
        if (str_starts_with($field, self::EXTRA_PREFIX)) {
            return $this->resolveCustomField($field, $scope);
        }

        return $scopeLabels[$field]
            ?? self::SHARED_LABELS[$field]
            ?? $this->humanize($field);
    }

    /**
     * Resolve an extra_fields.{code} field via custom_field_defs; on miss, fall
     * back to a humanized version of the code. Never crashes on an unknown field.
     */
    private function resolveCustomField(string $field, CustomFieldScope $scope): string
    {
        $code = substr($field, strlen(self::EXTRA_PREFIX));

        if ($code === '') {
            return $this->humanize($field);
        }

        $label = $this->customFieldLabels($scope)[$code] ?? null;

        if ($label !== null && $label !== '') {
            return $label;
        }

        // Unknown def: drop the amo_cf_ prefix so an AMO-migrated code reads as
        // its friendly identifier rather than «Amo cf 709732».
        $friendly = str_starts_with($code, self::AMO_CODE_PREFIX)
            ? substr($code, strlen(self::AMO_CODE_PREFIX))
            : $code;

        return $this->humanize($friendly);
    }

    /**
     * The code→label map for a scope's active custom-field defs, memoised.
     *
     * @return array<string, string>
     */
    private function customFieldLabels(CustomFieldScope $scope): array
    {
        return $this->customFieldCache[$scope->value] ??= CustomFieldDef::query()
            ->where('entity_scope', $scope->value)
            ->pluck('label', 'code')
            ->map(static fn (mixed $label): string => (string) $label)
            ->all();
    }

    /**
     * Turn a raw identifier into a readable label: split on underscores, trim a
     * numeric-only remainder to itself, and upper-case the first letter.
     * discount_percent → «Discount percent», 709732 → «709732».
     */
    private function humanize(string $value): string
    {
        $spaced = str_replace('_', ' ', trim($value));
        $spaced = trim($spaced);

        if ($spaced === '') {
            return $value;
        }

        return Str::ucfirst($spaced);
    }
}
