<?php

declare(strict_types=1);

namespace App\Support\System;

/**
 * ResetCategory — the 9 selectable data-wipe categories of the selective system
 * reset (Settings → Система → «Сброс системы»). This backed enum IS the allow-list
 * (contract §2, §10.1): a category key that is not a case here cannot be requested,
 * and the SystemResetService only ever deletes what a case maps to. New domains
 * (Finance, CS) that add tables are NOT swept in implicitly — they only become
 * resettable when someone adds a case + a purger + a test.
 *
 * Prerequisite edges (requires()) and the FK-safe execution order (executionRank())
 * are declared here so both the preview endpoint and the executor read the same
 * single source of truth (contract §3).
 */
enum ResetCategory: string
{
    case Deals = 'deals';
    case Contacts = 'contacts';
    case Companies = 'companies';
    case Tasks = 'tasks';
    case Docs = 'docs';
    case Finance = 'finance';
    case Automations = 'automations';
    case Directories = 'directories';
    case Logs = 'logs';

    /**
     * Human label (RU) — mirrors the design checkboxes (system-section.jsx S_CATS).
     */
    public function label(): string
    {
        return match ($this) {
            self::Deals => 'Сделки',
            self::Contacts => 'Контакты',
            self::Companies => 'Компании',
            self::Tasks => 'Задачи и активности',
            self::Docs => 'Документы и файлы',
            self::Finance => 'Финансовые операции',
            self::Automations => 'Правила автоматизаций',
            self::Directories => 'Справочники',
            self::Logs => 'Журналы и история',
        };
    }

    /**
     * Prerequisite categories that MUST also be selected, else the request is
     * hard-blocked 422 (contract §3(b), P2 = hard-block). A selected category whose
     * parent rows are referenced by an UNselected category's rows would otherwise
     * hit an FK RESTRICT mid-run; validating up front keeps the common conflict a
     * clean 422 with nothing deleted.
     *
     * @return list<self>
     */
    public function requires(): array
    {
        return match ($this) {
            // deals.company_id + crm_contact_company_links.company_id reference companies.
            self::Companies => [self::Deals, self::Contacts],
            // deals.lost_reason_id/source_id, deal_products.product_id, tag pivots
            // reference dictionaries — wiping them under live business data = FK error.
            self::Directories => [self::Deals, self::Contacts, self::Companies],
            default => [],
        };
    }

    /**
     * FK-safe execution order when several categories run together (contract §3(c)):
     * deals → contacts → companies → tasks → docs → finance → automations →
     * directories → logs. `logs` is deliberately LAST so per-category counts can be
     * recorded before that table is itself cleared, and the mandatory audit row
     * (§4) is written after the wipe.
     */
    public function executionRank(): int
    {
        return match ($this) {
            self::Deals => 0,
            self::Contacts => 1,
            self::Companies => 2,
            self::Tasks => 3,
            self::Docs => 4,
            self::Finance => 5,
            self::Automations => 6,
            self::Directories => 7,
            self::Logs => 8,
        };
    }

    /**
     * The representative "parent" table whose row count is THE single number shown
     * for this category in preview + the reset result (contract §6.1: one count per
     * category = parent table; §6.2 `deleted: {deals: 1248}`). Domain purgers that
     * return a per-table `array` are reduced to this one key; a purger returning a
     * plain `int` is already the representative. `null` = no representative table
     * yet (finance greenfield → count 0, contract P7).
     */
    public function representativeTable(): ?string
    {
        return match ($this) {
            self::Deals => 'deals',
            self::Contacts => 'crm_contacts',
            self::Companies => 'crm_companies',
            self::Tasks => 'activities',
            self::Docs => 'documents',
            self::Finance => null,
            self::Automations => 'pipeline_automations',
            self::Directories => null,
            self::Logs => 'entity_logs',
        };
    }

    /**
     * The full set of tables a COMPOSED category (no single representative parent)
     * spans, used to compute an honest preview count = SUM of live rows across all
     * of them (contract §6.1). Single-parent categories return an empty list and are
     * previewed via representativeTable() instead. `directories` is the only composed
     * category today (six dictionary seams across Crm/Catalog/Sales/Contracts).
     * `catalog_exchange_rates` is intentionally absent — FX is excluded (P5).
     *
     * @return list<string>
     */
    public function previewTables(): array
    {
        return match ($this) {
            self::Directories => [
                'crm_sources',
                'acquisition_channels',
                'acquisition_channel_history',
                'disconnect_reasons',
                'crm_tags',
                'custom_field_defs',
                'catalog_products',
                'catalog_product_groups',
                'catalog_product_plans',
                'catalog_product_prices',
                'lost_reasons',
                'message_templates',
                'message_template_bindings',
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * @param  list<string>  $keys
     * @return list<self>
     */
    public static function fromKeys(array $keys): array
    {
        return array_map(static fn (string $k): self => self::from($k), $keys);
    }
}
