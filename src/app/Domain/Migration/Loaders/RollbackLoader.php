<?php

declare(strict_types=1);

namespace App\Domain\Migration\Loaders;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Log\Enums\LogSubjectType;
use App\Domain\Migration\Models\ExternalRef;
use App\Domain\Sales\Models\Deal;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * RollbackLoader — undo a bad AMO load via external_refs. Temporary migration
 * bounded-context (dropped at M12).
 *
 * Deletes everything the load wrote, in REVERSE FK order (deals → contacts →
 * companies), keyed strictly off external_refs (source='amocrm'): only entities
 * the import created are touched — a hand-created MGCRM deal/contact/company with
 * no external_ref is never affected. The whole rollback runs inside ONE
 * transaction so a failure leaves the database untouched (all-or-nothing — unlike
 * the load, which is per-deal so a bad row never aborts the batch).
 *
 * We delete child rows EXPLICITLY rather than leaning on DB ON DELETE CASCADE:
 * mass query-builder deletes do not fire model events, and FK cascade behaviour
 * differs between SQLite (tests) and Postgres (prod). Deleting each layer by hand
 * keeps the rollback deterministic on both:
 *   - Deal children: entity_logs (subject_*) + activities (target_*) — FK-less
 *     polymorphs — then deal_contacts / deal_stage_history / deal_audits /
 *     deal_products, then the deal.
 *   - Contact: contact_channels / deal_contacts / contact_company_links, then the
 *     contact.
 *   - Company: company_requisites / company_channels / contact_company_links,
 *     then the company.
 *
 * --dry-run counts what WOULD be deleted and writes nothing (the whole pass runs
 * inside a transaction that is always rolled back).
 */
final class RollbackLoader
{
    /**
     * @param  array{dry_run?: bool, progress?: callable(string): void}  $options
     * @return array{deals: int, contacts: int, companies: int, activities: int, entity_logs: int, external_refs: int, dry_run: bool}
     */
    public function rollback(array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $progress = $options['progress'] ?? static function (string $_): void {};

        $counts = [
            'deals' => 0,
            'contacts' => 0,
            'companies' => 0,
            'activities' => 0,
            'entity_logs' => 0,
            'external_refs' => 0,
            'dry_run' => $dryRun,
        ];

        DB::beginTransaction();

        try {
            // 1) Deals first (reverse FK order). Delete every child layer by hand
            //    (FK-less timeline + the deal_id-keyed children) then the deal.
            $dealIds = $this->localIds(['deal']);
            if ($dealIds !== []) {
                $counts['entity_logs'] += DB::table('entity_logs')
                    ->where('subject_type', LogSubjectType::Deal->value)
                    ->whereIn('subject_id', $dealIds)
                    ->delete();
                $counts['activities'] += DB::table('activities')
                    ->where('target_type', 'deal')
                    ->whereIn('target_id', $dealIds)
                    ->delete();

                DB::table('deal_products')->whereIn('deal_id', $dealIds)->delete();
                DB::table('deal_audits')->whereIn('deal_id', $dealIds)->delete();
                DB::table('deal_stage_history')->whereIn('deal_id', $dealIds)->delete();
                DB::table('deal_contacts')->whereIn('deal_id', $dealIds)->delete();
            }
            $counts['deals'] += Deal::query()->whereIn('id', $dealIds)->delete();
            $progress("deals removed: {$counts['deals']}");

            // 2) Contacts. Delete channels / links / pivots first, then the contact.
            $contactIds = $this->localIds(['contact']);
            if ($contactIds !== []) {
                DB::table('contact_channels')->whereIn('contact_id', $contactIds)->delete();
                DB::table('crm_contact_company_links')->whereIn('contact_id', $contactIds)->delete();
                DB::table('deal_contacts')->whereIn('contact_id', $contactIds)->delete();
            }
            $counts['contacts'] += Contact::query()->whereIn('id', $contactIds)->delete();
            $progress("contacts removed: {$counts['contacts']}");

            // 3) Companies — real + synthetic (lead-company). Requisites / channels
            //    / links first, then the company.
            $companyIds = $this->localIds(['company', 'lead-company']);
            if ($companyIds !== []) {
                DB::table('company_requisites')->whereIn('company_id', $companyIds)->delete();
                DB::table('company_channels')->whereIn('company_id', $companyIds)->delete();
                DB::table('crm_contact_company_links')->whereIn('company_id', $companyIds)->delete();
            }
            $counts['companies'] += Company::query()->whereIn('id', $companyIds)->delete();
            $progress("companies removed: {$counts['companies']}");

            // 4) Finally, drop the provenance rows themselves (deal / contact /
            //    company / lead-company / activity).
            $counts['external_refs'] += ExternalRef::query()
                ->where('source', 'amocrm')
                ->whereIn('entity_type', ['deal', 'contact', 'company', 'lead-company', 'activity'])
                ->delete();
            $progress("external_refs removed: {$counts['external_refs']}");

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $counts;
    }

    /**
     * Local entity ids imported from AMO for the given external_ref entity types.
     *
     * @param  list<string>  $entityTypes
     * @return list<int>
     */
    private function localIds(array $entityTypes): array
    {
        return ExternalRef::query()
            ->where('source', 'amocrm')
            ->whereIn('entity_type', $entityTypes)
            ->pluck('entity_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
