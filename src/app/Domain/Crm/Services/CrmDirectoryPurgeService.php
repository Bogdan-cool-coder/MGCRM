<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use Illuminate\Support\Facades\DB;

/**
 * CrmDirectoryPurgeService — purgeAll() seam for the loose Crm dictionary
 * tables that have no dedicated CRUD Service of their own (their admin
 * controllers — SourceController / AcquisitionChannelController /
 * DisconnectReasonController — talk to the Eloquent models directly).
 *
 * Used ONLY by the system-reset `directories` category
 * (`docs/contracts/system-reset-api-contract.md` §1 row 9,  §7 boundary
 * decision — "a `DirectoriesPurge` seam across Catalog/Crm dictionary
 * services"). Called by `App\Support\System\SystemResetService` — never
 * invoked directly from a controller. No authorization check here: the
 * caller is the single admin-gated entry point (`can:system-reset`).
 *
 * Tables owned here: `crm_sources`, `acquisition_channel_history`,
 * `acquisition_channels`, `disconnect_reasons`.
 *
 * Explicitly NOT owned here (allow-list — contract §2/§10):
 *   - `crm_contact_positions`, `crm_company_types` — baseline seeded
 *     dictionaries, omitted from the contract's §1 row 9 table list, so they
 *     are never touched by any reset category.
 *   - `lost_reasons` — owned by Domain/Sales (LostReasonService::purgeAll()).
 *   - `message_templates` / `message_template_bindings` — owned by
 *     Domain/Contracts (MessageTemplateService::purgeAll()).
 *   - `catalog_products` family + `catalog_exchange_rates` — owned by
 *     Domain/Catalog (ProductService::purgeAll()); exchange rates are
 *     excluded from the wipe entirely per contract P5.
 *
 * `crm_companies.acquisition_channel_id` / `crm_contacts.acquisition_channel_id`
 * / `crm_companies.disconnect_reason_id` are all `nullOnDelete()` — purging
 * these dictionaries never hits an FK RESTRICT even if companies/contacts
 * still exist, so no cross-category prerequisite guard is needed for this
 * method itself (SystemResetService still enforces the contract's §3
 * recommended co-selection at the orchestration layer).
 */
class CrmDirectoryPurgeService
{
    /**
     * Must run inside the caller's own transaction (contract §5: per-category
     * transaction owned by SystemResetService) — this method does NOT open
     * one of its own.
     *
     * @return array<string, int> counts keyed by table name
     */
    public function purgeAll(): array
    {
        $sources = DB::table('crm_sources')->count();
        DB::table('crm_sources')->delete();

        // Children before parent: acquisition_channel_history references
        // acquisition_channels via old_channel_id/new_channel_id (nullOnDelete),
        // but is deleted explicitly first to match the contract's deterministic
        // child-before-parent ordering on both pgsql and the sqlite test suite.
        $channelHistory = DB::table('acquisition_channel_history')->count();
        DB::table('acquisition_channel_history')->delete();

        $channels = DB::table('acquisition_channels')->count();
        DB::table('acquisition_channels')->delete();

        $disconnectReasons = DB::table('disconnect_reasons')->count();
        DB::table('disconnect_reasons')->delete();

        return [
            'crm_sources' => $sources,
            'acquisition_channel_history' => $channelHistory,
            'acquisition_channels' => $channels,
            'disconnect_reasons' => $disconnectReasons,
        ];
    }
}
