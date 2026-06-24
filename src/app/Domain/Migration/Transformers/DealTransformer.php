<?php

declare(strict_types=1);

namespace App\Domain\Migration\Transformers;

use App\Domain\Migration\Support\AmoFieldReader;
use App\Domain\Migration\Support\AmoFields;
use App\Domain\Migration\Support\AmoReferenceResolver;

/**
 * DealTransformer — pure AMO lead → MGCRM Deal attribute array. Temporary
 * migration bounded-context (dropped at M12).
 *
 * Money: amount = lead.price × 100 (kopecks), amount_locked = true (the imported
 * budget is a fixed figure; DealService::recalcAmount must NOT re-derive it from
 * the non-existent line items). currency = the pipeline default (RUB, DEC-A).
 *
 * Stage / pipeline come from status_map via the resolver; the won terminal (142)
 * forces the success stage, lost (143) the lost stage, both keeping the deal's
 * own pipeline. A status with no map entry yields a null stage_id — the loader
 * hard-gates on that (refuses to load an un-mapped status).
 *
 * Dates follow the «План / Факт» split: a WON lead (status 142) carries ACTUAL
 * signed_at / paid_at; any other lead carries PLANNED expected_sign_date /
 * expected_payment_date — both from the same two AMO date custom fields.
 */
final class DealTransformer
{
    public function __construct(
        private readonly AmoReferenceResolver $resolver,
    ) {}

    /**
     * @param  array<string, mixed>  $amoLead  Raw AMO lead.
     * @return array{
     *     amo_id: int,
     *     amo_status_id: ?int,
     *     amo_pipeline_id: ?int,
     *     deal: array<string, mixed>,
     *     owner_amo_id: ?int,
     *     created_by_amo_id: ?int,
     *     is_won: bool,
     *     stage_code: ?string,
     *     unmapped_status: bool,
     *     created_at: ?int,
     *     product_enum_ids: list<int>
     * }
     */
    public function transform(array $amoLead): array
    {
        $fields = AmoFieldReader::for($amoLead);

        $amoStatusId = isset($amoLead['status_id']) ? (int) $amoLead['status_id'] : null;
        $amoPipelineId = isset($amoLead['pipeline_id']) ? (int) $amoLead['pipeline_id'] : null;
        $ownerAmoId = isset($amoLead['responsible_user_id']) ? (int) $amoLead['responsible_user_id'] : null;
        $createdByAmoId = isset($amoLead['created_by']) ? (int) $amoLead['created_by'] : null;

        $stage = $amoStatusId !== null
            ? $this->resolver->stageForStatus($amoStatusId, $amoPipelineId)
            : ['pipeline_id' => null, 'stage_id' => null, 'stage_code' => null];

        $isWon = $amoStatusId === AmoFields::STATUS_WON;

        // Money: price (RUB) → kopecks. price=0 ⇒ amount=0 (never null).
        $price = (float) ($amoLead['price'] ?? 0);
        $amount = (int) round($price * 100);

        $currency = $this->currencyForPipeline($stage['pipeline_id'], $amoPipelineId);
        $perpetual = $this->isPerpetual($fields);

        // Plan / fact date split.
        $signTs = $fields->timestamp(AmoFields::LEAD_SIGN_DATE);
        $payTs = $fields->timestamp(AmoFields::LEAD_PAYMENT_DATE);

        $deal = [
            'pipeline_id' => $stage['pipeline_id'],
            'stage_id' => $stage['stage_id'],
            'max_stage_id' => $stage['stage_id'],
            'title' => $this->resolveTitle($amoLead),
            'amount' => $amount,
            'amount_locked' => true,
            'perpetual_license' => $perpetual,
            'is_primary_deal' => false, // set by the loader for the first won deal of a company
            'currency' => $currency,
            // extra_fields is a NOT NULL json column (default '{}') — always an
            // array, never null.
            'extra_fields' => $this->extraFields($fields, $amoLead),
        ];

        if ($isWon) {
            $deal['signed_at'] = $this->resolver->toDate($signTs);
            $deal['paid_at'] = $this->resolver->toDate($payTs);
            $deal['closed_at'] = $this->resolver->toDateTime(
                isset($amoLead['closed_at']) ? (int) $amoLead['closed_at'] : null
            );
        } else {
            $deal['expected_sign_date'] = $this->resolver->toDate($signTs);
            $deal['expected_payment_date'] = $this->resolver->toDate($payTs);
            if ($amoStatusId === AmoFields::STATUS_LOST && isset($amoLead['closed_at'])) {
                $deal['closed_at'] = $this->resolver->toDateTime((int) $amoLead['closed_at']);
            }
        }

        return [
            'amo_id' => (int) ($amoLead['id'] ?? 0),
            'amo_status_id' => $amoStatusId,
            'amo_pipeline_id' => $amoPipelineId,
            'deal' => $deal,
            'owner_amo_id' => $ownerAmoId,
            'created_by_amo_id' => $createdByAmoId,
            'is_won' => $isWon,
            'stage_code' => $stage['stage_code'],
            'unmapped_status' => $stage['stage_id'] === null,
            'created_at' => isset($amoLead['created_at']) ? (int) $amoLead['created_at'] : null,
            // AMO "Продукт" multiselect enum ids — resolved to catalog deal_products
            // by the loader via amo_product_mappings (DEC Feature 5).
            'product_enum_ids' => $fields->enumIds(AmoFields::LEAD_PRODUCT),
        ];
    }

    /**
     * @param  array<string, mixed>  $amoLead
     */
    private function resolveTitle(array $amoLead): string
    {
        $title = trim((string) ($amoLead['name'] ?? ''));

        return $title !== '' ? $title : 'Сделка #'.($amoLead['id'] ?? '');
    }

    private function currencyForPipeline(?int $pipelineId, ?int $amoPipelineId): string
    {
        // Resolve the pipeline default_currency from config by amo id (DEC-A: RUB).
        foreach ((array) config('amo_migration.pipelines', []) as $entry) {
            if (is_array($entry) && (int) ($entry['amo_pipeline_id'] ?? 0) === (int) $amoPipelineId) {
                return (string) ($entry['default_currency'] ?? 'RUB');
            }
        }

        return 'RUB';
    }

    private function isPerpetual(AmoFieldReader $fields): bool
    {
        if (! $fields->has(AmoFields::LEAD_PERPETUAL_LICENSE)) {
            return false;
        }

        $value = $fields->string(AmoFields::LEAD_PERPETUAL_LICENSE);

        // AMO checkbox renders as "1"/"true"/"Да" when ticked.
        return in_array(mb_strtolower((string) $value), ['1', 'true', 'да', 'yes', 'on'], true);
    }

    /**
     * Custom fields kept verbatim on the deal: the category (raw S1/M2/… into
     * amo_category) and the source country label for reference.
     *
     * @param  array<string, mixed>  $amoLead
     * @return array<string, mixed>
     */
    private function extraFields(AmoFieldReader $fields, array $amoLead): array
    {
        $extra = [];

        $categoryEnum = $fields->enumId(AmoFields::LEAD_CATEGORY);
        if ($categoryEnum !== null) {
            // Raw value (e.g. "M 2") — category logic is curated separately.
            $extra['amo_category'] = $fields->string(AmoFields::LEAD_CATEGORY);
        }

        $countryLabel = $fields->string(AmoFields::LEAD_COUNTRY);
        if ($countryLabel !== null) {
            $extra['amo_country'] = $countryLabel;
        }

        return $extra;
    }
}
