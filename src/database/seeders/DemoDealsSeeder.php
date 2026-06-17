<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealProduct;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING demo deals spread across stages so the Kanban board is alive on
 * a fresh dev DB. Idempotent: deals are keyed by a stable title; re-running does
 * not duplicate. Depends on PipelineSeeder (stages), AdminSeeder (owner) and
 * ProductSeeder (catalog prices for the snapshot). Tests do NOT run this seeder.
 */
class DemoDealsSeeder extends Seeder
{
    public function __construct(
        private readonly ProductService $products,
    ) {}

    /**
     * Demo deals: [stage code, title, currency, [[product code, plan code|null, qty], ...]].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: list<array{0: string, 1: ?string, 2: float}>}>
     */
    private const DEALS = [
        ['new', 'ООО «Ромашка» — внедрение CRM', 'RUB', [['macro_crm', null, 1]]],
        ['new', 'Acme Corp — AI assistant pilot', 'USD', [['macro_ai_assistant', 'per_min', 1000]]],
        ['qualify', 'ТехноПарк — MACRO AI Core', 'RUB', [['macro_ai_core', 'start_annual', 1]]],
        ['schedule_meeting', 'Глобус Логистик — интеграции', 'RUB', [['macro_integration_kit', 'basic_pkg', 1]]],
        ['meeting', 'СтройИнвест — комплекс', 'RUB', [['macro_crm', null, 1], ['implementation_standard', null, 1]]],
        ['warm', 'Альфа Финанс — MACRO AI Core Business', 'RUB', [['macro_ai_core', 'business_annual', 1]]],
        ['hot', 'Берёзка Ритейл — Enterprise', 'RUB', [['macro_ai_core', 'enterprise_annual', 1]]],
        ['won', 'Восток Трейд — закрытая', 'RUB', [['macro_crm', null, 2]]],
        ['await_payment', 'Север Холдинг — ожидаем оплату', 'RUB', [['macro_integration_kit', 'pro_pkg', 1]]],
    ];

    public function run(): void
    {
        $owner = User::where('role', Role::Admin->value)->orderBy('id')->first();
        if ($owner === null) {
            return;
        }

        $pipeline = Pipeline::where('kind', PipelineKind::Sales->value)
            ->where('name', 'Продажи')
            ->with('stages')
            ->first();
        if ($pipeline === null) {
            return;
        }

        $stagesByCode = $pipeline->stages->keyBy('code');

        foreach (self::DEALS as [$stageCode, $title, $currency, $items]) {
            /** @var PipelineStage|null $stage */
            $stage = $stagesByCode->get($stageCode);
            if ($stage === null) {
                continue;
            }

            $company = Company::firstOrCreate(
                ['name' => $this->companyNameFor($title)],
                [
                    'country_code' => 'kz',
                    'source' => 'own_contact',
                    'owner_user_id' => $owner->id,
                    'department_id' => $owner->department_id,
                    'tags' => [],
                    'extra_fields' => [],
                ],
            );

            $deal = Deal::firstOrCreate(
                ['title' => $title],
                [
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $stage->id,
                    'company_id' => $company->id,
                    'amount' => 0,
                    'currency' => $currency,
                    'owner_user_id' => $owner->id,
                    'department_id' => $owner->department_id,
                    'tags' => [],
                    'extra_fields' => [],
                    'stage_changed_at' => now(),
                    'closed_at' => ($stage->is_won || $stage->is_lost) ? now() : null,
                ],
            );

            $this->seedLineItems($deal, $currency, $items);

            // S2.8 hard won-gate: a deal sitting in a contract-gated won stage must
            // have a live contract, otherwise moving it there would 409. Seed an
            // approved demo contract so the data stays consistent with the gate.
            $this->ensureContractForGatedWonDeal($deal, $stage, $owner);
        }
    }

    /**
     * Attach an approved demo Document to a deal that lives in a contract-gated
     * won stage (won_gate + won_gate_contract_required). Idempotent: keyed by
     * source_deal_id. Stages without the gate (e.g. await_payment) are skipped.
     */
    private function ensureContractForGatedWonDeal(Deal $deal, PipelineStage $stage, User $owner): void
    {
        if (! ($stage->is_won && $stage->won_gate && $stage->won_gate_contract_required)) {
            return;
        }

        if (Document::query()->where('source_deal_id', $deal->id)->exists()) {
            return;
        }

        Document::create([
            'kind' => 'contract',
            'title' => "[DEMO] Договор — {$deal->title}",
            'product_code' => 'macrocrm',
            'country_code' => 'kz',
            'status' => ContractStatus::Approved->value,
            'source_deal_id' => $deal->id,
            'source_company_id' => $deal->company_id,
            'author_user_id' => $owner->id,
            'currency' => $deal->currency,
            'context' => [
                'sublicensee' => [],
                'license' => [],
                'contract' => [],
                'payments' => [],
                'acts' => [],
                'custom' => [],
            ],
            'subtotal' => 0,
            'discount_pct' => 0,
            'discount_amount' => 0,
            'total' => 0,
            'extra_fields' => [],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: ?string, 2: float}>  $items
     */
    private function seedLineItems(Deal $deal, string $currency, array $items): void
    {
        $sum = 0;

        foreach ($items as $sortOrder => [$productCode, $planCode, $quantity]) {
            $product = Product::where('code', $productCode)->with('plans')->first();
            if ($product === null) {
                continue;
            }

            $planId = null;
            if ($planCode !== null) {
                $planId = $product->plans->firstWhere('code', $planCode)?->id;
            }

            $unitPrice = $this->products->getPriceSnapshot($product->id, $planId, $currency);
            if ($unitPrice === null) {
                continue;
            }

            $amount = (int) round($quantity * $unitPrice);
            $sum += $amount;

            DealProduct::firstOrCreate(
                ['deal_id' => $deal->id, 'product_id' => $product->id, 'plan_id' => $planId],
                [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'currency' => $currency,
                    'amount' => $amount,
                    'sort_order' => $sortOrder,
                ],
            );
        }

        // Derive Deal.amount from the seeded line items.
        $deal->update(['amount' => (int) DealProduct::where('deal_id', $deal->id)->sum('amount')]);
    }

    private function companyNameFor(string $title): string
    {
        // Stable company name from the deal title prefix before the em dash.
        $parts = explode(' — ', $title);

        return trim($parts[0]);
    }
}
