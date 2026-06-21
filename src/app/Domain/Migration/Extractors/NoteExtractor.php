<?php

declare(strict_types=1);

namespace App\Domain\Migration\Extractors;

/**
 * NoteExtractor — fetches lead notes (calls / comments / attachments-meta) into
 * notes.jsonl, one lead at a time.
 *
 * Temporary migration bounded-context (dropped at M12). Reads:
 *   /leads/{id}/notes   (paginated per lead)
 * over the lead ids from LeadExtractor (~5–6k calls). Each lead id is marked
 * processed individually so --resume continues from the next un-fetched lead.
 *
 * The lead_id is stamped onto every row so the transform phase can re-attach
 * notes to their deal without re-deriving the parent (the /leads/{id}/notes
 * endpoint body does not always echo the parent id at top level).
 */
class NoteExtractor extends AbstractExtractor
{
    public function entityName(): string
    {
        return 'notes';
    }

    public function run(): int
    {
        $checkpoint = $this->makeCheckpoint();

        if ($checkpoint->isDone() && $this->resume) {
            $this->log('notes: already complete — skipping');

            return 0;
        }

        $leadIds = $this->readSidecarIds('leads');

        if ($leadIds === []) {
            $this->log('notes: no lead ids (run leads first) — nothing to do');

            return 0;
        }

        if ($this->limit > 0) {
            $leadIds = array_slice($leadIds, 0, $this->limit);
        }

        $leadIds = array_values(array_unique($leadIds));
        $writer = $this->makeWriter();

        $count = 0;
        $processed = 0;
        $total = count($leadIds);

        foreach ($leadIds as $leadId) {
            if ($this->resume && $checkpoint->isProcessed($leadId)) {
                continue;
            }

            $query = ['limit' => 250];

            foreach ($this->client->getPaginated("/leads/{$leadId}/notes", $query) as $body) {
                foreach ($body['_embedded']['notes'] ?? [] as $note) {
                    // Stamp the parent lead id for deterministic re-attachment.
                    $note['_lead_id'] = $leadId;
                    $writer->write($note);
                    $count++;
                }
            }

            $checkpoint->markProcessed($leadId);
            $processed++;

            if ($processed % 100 === 0) {
                $this->log(sprintf('notes: %d/%d leads processed, %d notes written', $processed, $total, $count));
            }
        }

        $writer->close();
        $checkpoint->markDone();

        $this->log("notes: {$count} written across {$processed} leads");

        return $count;
    }
}
