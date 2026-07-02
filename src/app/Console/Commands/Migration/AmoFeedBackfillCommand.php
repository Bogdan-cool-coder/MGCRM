<?php

declare(strict_types=1);

namespace App\Console\Commands\Migration;

use App\Domain\Migration\Support\AmoEnumLabelResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan migration:amo-feed-backfill {--dry-run} {--limit=} {--deal=}
 *
 * One-off repair for AMO-imported timeline rows whose value/body still holds the
 * raw AMO `custom_field_value_changed` JSON (the pre-fix EventTransformer dumped
 * `[{"custom_field_value": {…}}]` into deal_audits / activities instead of the
 * enum label). Re-parses that JSON, resolves the readable label via
 * AmoEnumLabelResolver, and rewrites the stored text — WITHOUT re-running the
 * whole import. Domain/Migration, dropped at M12.
 *
 * Idempotent: it only selects rows that still match the raw-JSON signature. Once
 * a row is rewritten to human text it no longer matches, so re-running is a
 * no-op. --dry-run reports what WOULD change and writes nothing.
 *
 *   docker compose exec app php artisan migration:amo-feed-backfill --dry-run
 *   docker compose exec app php artisan migration:amo-feed-backfill
 *   docker compose exec app php artisan migration:amo-feed-backfill --deal=70 --dry-run
 */
class AmoFeedBackfillCommand extends Command
{
    protected $signature = 'migration:amo-feed-backfill
        {--dry-run : Report what would change; write nothing}
        {--limit= : Cap the number of rows scanned per table}
        {--deal= : Restrict to a single deal id (deal_audits.deal_id / activities.target_id)}';

    protected $description = 'Rewrite AMO-imported feed rows that still contain raw custom_field_value JSON into readable RU text';

    /**
     * Substrings that mark a value still holding raw AMO field-change JSON. A
     * readable label never contains these, so matching is a safe idempotency gate.
     */
    private const RAW_MARKERS = ['custom_field_value', 'enum_id', 'field_type', '"field_id"'];

    public function handle(AmoEnumLabelResolver $labels): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $dealId = $this->option('deal') !== null ? (int) $this->option('deal') : null;

        $this->info(sprintf(
            'AMO feed backfill%s%s%s',
            $dryRun ? ' (dry-run — writing nothing)' : '',
            $dealId !== null ? " — deal {$dealId}" : '',
            $limit !== null ? " — limit {$limit}/table" : '',
        ));
        $this->newLine();

        $audits = $this->backfillDealAudits($labels, $dryRun, $limit, $dealId);
        $activities = $this->backfillActivities($labels, $dryRun, $limit, $dealId);

        $this->newLine();
        $verb = $dryRun ? 'WOULD rewrite' : 'Rewrote';
        $this->info(sprintf(
            '%s %d deal_audit value(s) and %d activity body(-ies). Scanned %d + %d candidate row(s).',
            $verb,
            $audits['changed'],
            $activities['changed'],
            $audits['scanned'],
            $activities['scanned'],
        ));

        if ($audits['samples'] !== [] || $activities['samples'] !== []) {
            $this->newLine();
            $this->line('<options=bold>Samples (before → after):</>');
            foreach (array_slice(array_merge($audits['samples'], $activities['samples']), 0, 10) as $s) {
                $this->line('  '.$s['src'].' #'.$s['id']);
                $this->line('    - '.$s['before']);
                $this->line('    + '.$s['after']);
            }
        }

        return self::SUCCESS;
    }

    /**
     * deal_audits: field + old_value + new_value. old/new hold the raw JSON; field
     * often holds the opaque `extra_fields.amo_cf_<id>` key. We rewrite both value
     * columns to labels and, when field is the amo_cf key, replace it with the
     * human field name.
     *
     * @return array{scanned: int, changed: int, samples: list<array<string, string>>}
     */
    private function backfillDealAudits(AmoEnumLabelResolver $labels, bool $dryRun, ?int $limit, ?int $dealId): array
    {
        $query = DB::table('deal_audits')
            ->where(function ($q): void {
                foreach (self::RAW_MARKERS as $marker) {
                    $q->orWhere('old_value', 'like', '%'.$marker.'%')
                        ->orWhere('new_value', 'like', '%'.$marker.'%');
                }
            })
            ->orderBy('id');

        if ($dealId !== null) {
            $query->where('deal_id', $dealId);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $scanned = 0;
        $changed = 0;
        $samples = [];

        foreach ($query->get(['id', 'field', 'old_value', 'new_value']) as $row) {
            $scanned++;

            $newOld = $this->rewriteValue($labels, $row->old_value);
            $newNew = $this->rewriteValue($labels, $row->new_value);
            $newField = $this->rewriteField($labels, (string) $row->field, $row->old_value, $row->new_value);

            $touched = $newOld !== $row->old_value || $newNew !== $row->new_value || $newField !== $row->field;
            if (! $touched) {
                continue;
            }

            $changed++;

            if (count($samples) < 5) {
                $samples[] = [
                    'src' => 'deal_audit',
                    'id' => (string) $row->id,
                    'before' => $this->clip((string) $row->field.': '.(string) $row->old_value.' → '.(string) $row->new_value),
                    'after' => $this->clip($newField.': '.(string) $newOld.' → '.(string) $newNew),
                ];
            }

            if (! $dryRun) {
                DB::table('deal_audits')->where('id', $row->id)->update([
                    'field' => mb_substr($newField, 0, 100),
                    'old_value' => $newOld,
                    'new_value' => $newNew,
                ]);
            }
        }

        return ['scanned' => $scanned, 'changed' => $changed, 'samples' => $samples];
    }

    /**
     * activities.body: a note/field-change body that still holds raw JSON.
     *
     * @return array{scanned: int, changed: int, samples: list<array<string, string>>}
     */
    private function backfillActivities(AmoEnumLabelResolver $labels, bool $dryRun, ?int $limit, ?int $dealId): array
    {
        $query = DB::table('activities')
            ->where(function ($q): void {
                foreach (self::RAW_MARKERS as $marker) {
                    $q->orWhere('body', 'like', '%'.$marker.'%');
                }
            })
            ->orderBy('id');

        if ($dealId !== null) {
            $query->where('target_type', 'deal')->where('target_id', $dealId);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $scanned = 0;
        $changed = 0;
        $samples = [];

        foreach ($query->get(['id', 'body']) as $row) {
            $scanned++;

            $newBody = $this->rewriteValue($labels, $row->body);

            if ($newBody === $row->body || $newBody === null) {
                continue;
            }

            $changed++;

            if (count($samples) < 5) {
                $samples[] = [
                    'src' => 'activity',
                    'id' => (string) $row->id,
                    'before' => $this->clip((string) $row->body),
                    'after' => $this->clip($newBody),
                ];
            }

            if (! $dryRun) {
                DB::table('activities')->where('id', $row->id)->update([
                    'body' => mb_substr($newBody, 0, 5000),
                ]);
            }
        }

        return ['scanned' => $scanned, 'changed' => $changed, 'samples' => $samples];
    }

    /**
     * Turn a stored value that still holds raw AMO field-change JSON into a
     * readable label. Non-JSON / already-readable values are returned unchanged
     * (idempotency). Unparseable-but-JSON-looking values collapse to a short
     * human placeholder rather than staying as JSON.
     */
    private function rewriteValue(AmoEnumLabelResolver $labels, ?string $stored): ?string
    {
        if ($stored === null || ! $this->looksRaw($stored)) {
            return $stored;
        }

        $decoded = json_decode($stored, true);

        if (is_array($decoded)) {
            $label = $labels->value($decoded);
            if ($label !== null) {
                return $label;
            }
        }

        // JSON-shaped but nothing readable inside → short placeholder, never JSON.
        return 'значение изменено';
    }

    /**
     * Replace an opaque `extra_fields.amo_cf_<id>` audit field with the human
     * field name (from field_name_map, or the generic fallback). Other field
     * names are left intact.
     */
    private function rewriteField(AmoEnumLabelResolver $labels, string $field, ?string $old, ?string $new): string
    {
        if (preg_match('/amo_cf_?(\d+)/', $field, $m) === 1) {
            return $labels->fieldName((int) $m[1]);
        }

        // Field carries no id but the values were raw JSON with an embedded
        // field_id — recover the name from there.
        if ($field === '' || str_contains($field, 'amo_cf')) {
            $fieldId = $labels->fieldId($this->decodeArray($new)) ?? $labels->fieldId($this->decodeArray($old));
            if ($fieldId !== null) {
                return $labels->fieldName($fieldId);
            }
        }

        return $field;
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeArray(?string $stored): ?array
    {
        if ($stored === null) {
            return null;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function looksRaw(string $value): bool
    {
        foreach (self::RAW_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function clip(string $s): string
    {
        return mb_substr($s, 0, 160);
    }
}
