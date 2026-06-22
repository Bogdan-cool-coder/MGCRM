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
 * When --status is given (withStatuses), the pull is narrowed to those statuses
 * via AMO's filter[statuses] — an array of {pipeline_id, status_id} objects. We
 * expand the requested status ids across BOTH source pipelines, e.g. --status=142
 * yields filter[statuses][0]={6149857,142} + filter[statuses][1]={10915373,142},
 * pulling only won deals from each funnel. (filter[pipeline_id] is dropped when
 * statuses are set — the statuses array already pins the pipelines.)
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

        $pipelineIds = array_values((array) config('amo_migration.api.pipeline_ids', []));
        $startPage = $this->resume ? max(1, $checkpoint->page() + 1) : 1;

        // No --status → filter only by pipeline (full archive, every status).
        // --status given → expand each status across both pipelines into AMO's
        // filter[statuses] = [{pipeline_id, status_id}, ...]; the statuses array
        // already pins the pipelines, so filter[pipeline_id] is omitted.
        $filter = $this->statuses === []
            ? ['pipeline_id' => $pipelineIds]
            : ['statuses' => $this->buildStatusFilter($pipelineIds, $this->statuses)];

        $query = [
            'filter' => $filter,
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
     * Build AMO's filter[statuses] — the cartesian product of every source
     * pipeline with every requested status id. Each element is an object with
     * pipeline_id + status_id (both required), serialised by the HTTP client to
     * filter[statuses][N][pipeline_id]=..&filter[statuses][N][status_id]=..
     *
     * @param  list<int>  $pipelineIds
     * @param  list<int>  $statuses
     * @return list<array{pipeline_id: int, status_id: int}>
     */
    private function buildStatusFilter(array $pipelineIds, array $statuses): array
    {
        $pairs = [];

        foreach ($pipelineIds as $pipelineId) {
            foreach ($statuses as $statusId) {
                $pairs[] = [
                    'pipeline_id' => (int) $pipelineId,
                    'status_id' => (int) $statusId,
                ];
            }
        }

        return $pairs;
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
