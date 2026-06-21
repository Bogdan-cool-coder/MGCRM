<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

/**
 * LeadExtractor — pulls every lead from both source pipelines into leads.jsonl.
 *
 * Temporary migration bounded-context (dropped at M12). Reads:
 *   /leads?filter[pipeline_id][]=6149857&filter[pipeline_id][]=10915373
 *          &with=contacts,companies,catalog_elements
 * across ALL statuses (including 142 won / 143 lost — full archive). Each lead
 * is written verbatim (raw AMO object) as one JSONL line.
 *
 * Side effect: collects the unique contact_id / company_id referenced from each
 * lead's _embedded and persists them to contacts.ids.json / companies.ids.json
 * sidecars, which Contact/CompanyExtractor consume to fetch in id batches.
 *
 * Checkpoint granularity is the page number, so a crash resumes from the next
 * unread page rather than re-walking the whole archive.
 */
class LeadExtractor extends AbstractExtractor
{
    public function entityName(): string
    {
        return 'leads';
    }

    public function run(): int
    {
        $checkpoint = $this->makeCheckpoint();

        if ($checkpoint->isDone() && $this->resume) {
            $this->log('leads: already complete (checkpoint done) — skipping');

            return 0;
        }

        $writer = $this->makeWriter();

        $pipelineIds = (array) config('amo_migration.api.pipeline_ids', []);
        $startPage = $this->resume ? max(1, $checkpoint->page() + 1) : 1;

        $query = [
            'filter' => ['pipeline_id' => array_values($pipelineIds)],
            'with' => 'contacts,companies,catalog_elements',
            'limit' => 250,
            'page' => $startPage,
        ];

        $contactIds = $this->readSidecarIds('contacts');
        $companyIds = $this->readSidecarIds('companies');
        $leadIds = $this->readSidecarIds('leads');

        $count = 0;
        $page = $startPage - 1;

        foreach ($this->client->getPaginated('/leads', $query) as $body) {
            $page++;
            $leads = $body['_embedded']['leads'] ?? [];

            foreach ($leads as $lead) {
                $writer->write($lead);
                $count++;

                if (isset($lead['id'])) {
                    $leadIds[] = (int) $lead['id'];
                }

                $this->collectLinkedIds($lead, $contactIds, $companyIds);

                if ($this->limit > 0 && $count >= $this->limit) {
                    break;
                }
            }

            $checkpoint->setPage($page);
            $this->log("leads: page {$page} done, {$count} leads written");

            if ($this->limit > 0 && $count >= $this->limit) {
                break;
            }
        }

        $writer->close();

        $this->writeSidecarIds('contacts', $contactIds);
        $this->writeSidecarIds('companies', $companyIds);
        $this->writeSidecarIds('leads', $leadIds);

        if ($this->limit === 0) {
            $checkpoint->markDone();
        }

        $this->log(sprintf(
            'leads: %d written; %d unique contacts, %d unique companies collected',
            $count,
            count(array_unique($contactIds)),
            count(array_unique($companyIds)),
        ));

        return $count;
    }

    /**
     * Append the contact/company ids linked from a lead's _embedded to the
     * running dedupe lists.
     *
     * @param  array<string, mixed>  $lead
     * @param  list<int>  $contactIds
     * @param  list<int>  $companyIds
     */
    private function collectLinkedIds(array $lead, array &$contactIds, array &$companyIds): void
    {
        $embedded = $lead['_embedded'] ?? [];

        foreach ($embedded['contacts'] ?? [] as $contact) {
            if (isset($contact['id'])) {
                $contactIds[] = (int) $contact['id'];
            }
        }

        foreach ($embedded['companies'] ?? [] as $company) {
            if (isset($company['id'])) {
                $companyIds[] = (int) $company['id'];
            }
        }
    }
}
