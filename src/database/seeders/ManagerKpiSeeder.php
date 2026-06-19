<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Models\Document;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\CommissionRule;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use App\Domain\Sales\Models\SalaryPlan;
use App\Domain\Sales\Models\TeamTarget;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent demo seeder for S1.8 KPI cabinet.
 * Creates:
 *  - Sales department
 *  - 3 demo managers + 1 director (keyed by email — update-or-create)
 *  - CommissionRule x1, TeamTarget x1, SalaryPlan x3 (one per manager, current month)
 *  - Won deals for this month so score_pct is interesting (not all zeros)
 *
 * Depends on: PipelineSeeder (won stage must exist).
 */
class ManagerKpiSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Department ----
        $dept = Department::firstOrCreate(
            ['name' => 'Отдел продаж'],
            ['parent_id' => null, 'manager_id' => null]
        );

        // ---- CommissionRule ----
        $rule = CommissionRule::firstOrCreate(
            ['name' => 'Стандартная (10%)'],
            [
                'rate_pct_times_100' => 1000,
                'base_currency' => 'RUB',
                'scope' => 'personal_deals',
                'applies_to_first_payment_only' => true,
                'requires_signed_contract' => true,
                'payment_trigger' => 'immediate',
                'is_active' => true,
            ]
        );

        // ---- TeamTarget (current month) ----
        $now = now();

        $teamTarget = TeamTarget::updateOrCreate(
            [
                'department_id' => $dept->id,
                'period_year' => $now->year,
                'period_month' => $now->month,
            ],
            [
                'target_amount_kopecks' => 90_000_000, // 900 000 RUB
                'target_currency' => 'RUB',
            ]
        );

        // ---- Director ----
        $director = User::updateOrCreate(
            ['email' => 'director@mgcrm.test'],
            [
                'full_name' => 'Директор Петров П.П.',
                'job_title' => 'Директор по продажам',
                'password' => Hash::make('password'),
                'role' => Role::Director,
                'department_id' => $dept->id,
                'is_active' => true,
                'locale' => 'ru',
                'totp_enabled' => false,
            ]
        );
        $director->syncRoles([Role::Director->value]);

        // Update dept manager if not set
        if ($dept->manager_id === null) {
            $dept->update(['manager_id' => $director->id]);
        }

        // ---- 3 Demo managers ----
        $managerDefs = [
            [
                'email' => 'manager1@mgcrm.test',
                'full_name' => 'Иванов Алексей Сергеевич',
                'job_title' => 'Менеджер по продажам',
                'income_plan' => 30_000_000, // 300 000 RUB
                'ftm_plan' => 5,
                'won_deals_amount' => 24_700_000, // ~82%
            ],
            [
                'email' => 'manager2@mgcrm.test',
                'full_name' => 'Петрова Мария Сергеевна',
                'job_title' => 'Старший менеджер',
                'income_plan' => 30_000_000,
                'ftm_plan' => 5,
                'won_deals_amount' => 27_300_000, // ~91%
            ],
            [
                'email' => 'manager3@mgcrm.test',
                'full_name' => 'Сидоров Антон Константинович',
                'job_title' => 'Менеджер по работе с ключевыми клиентами',
                'income_plan' => 30_000_000,
                'ftm_plan' => 3,
                'won_deals_amount' => 19_200_000, // 64%
            ],
        ];

        // Find the won stage of the default funnel (MACRO Global — the active
        // primary pipeline since the SalesPulse cutover; "Продажи" is archived).
        $pipeline = Pipeline::query()
            ->where('name', 'MACRO Global')
            ->with(['stages' => fn ($q) => $q->where('is_won', true)])
            ->first();

        /** @var PipelineStage|null $wonStage */
        $wonStage = $pipeline?->stages?->first();

        foreach ($managerDefs as $def) {
            $manager = User::updateOrCreate(
                ['email' => $def['email']],
                [
                    'full_name' => $def['full_name'],
                    'job_title' => $def['job_title'],
                    'password' => Hash::make('password'),
                    'role' => Role::Manager,
                    'department_id' => $dept->id,
                    'manager_id' => $director->id,
                    'is_active' => true,
                    'locale' => 'ru',
                    'totp_enabled' => false,
                ]
            );
            $manager->syncRoles([Role::Manager->value]);

            // Salary plan for current month (idempotent)
            SalaryPlan::updateOrCreate(
                [
                    'user_id' => $manager->id,
                    'period_year' => $now->year,
                    'period_month' => $now->month,
                ],
                [
                    'personal_income_plan_kopecks' => $def['income_plan'],
                    'personal_income_plan_currency' => 'RUB',
                    'personal_ftm_plan' => $def['ftm_plan'],
                    'team_target_id' => $teamTarget->id,
                    'commission_rule_id' => $rule->id,
                    'status' => 'draft',
                ]
            );

            // Demo won deal for current month (create only if no won deals exist for this manager this month)
            if ($wonStage !== null) {
                $alreadyHasWonDeal = Deal::query()
                    ->where('owner_user_id', $manager->id)
                    ->where('stage_id', $wonStage->id)
                    ->where('stage_changed_at', '>=', $now->copy()->startOfMonth())
                    ->exists();

                if (! $alreadyHasWonDeal) {
                    // company_id is NOT NULL — reuse any existing company or create a stub
                    $company = Company::first() ?? Company::factory()->create([
                        'name' => 'Demo Company',
                    ]);

                    $deal = Deal::create([
                        'pipeline_id' => $wonStage->pipeline_id,
                        'stage_id' => $wonStage->id,
                        'company_id' => $company->id,
                        'title' => "Demo won deal — {$def['full_name']}",
                        'amount' => $def['won_deals_amount'],
                        'currency' => 'RUB',
                        'owner_user_id' => $manager->id,
                        'department_id' => $dept->id,
                        'stage_changed_at' => $now->copy()->startOfMonth()->addDays(5),
                    ]);

                    $this->ensureContractForWonDeal($deal, $wonStage, $manager);
                }
            }
        }
    }

    /**
     * S2.8 hard won-gate: a deal in a contract-gated won stage needs a live
     * contract. Seed an approved demo Document so the KPI demo data is consistent
     * with the gate. Idempotent: keyed by source_deal_id. Stages without the gate
     * (won_gate / won_gate_contract_required off) are skipped.
     */
    private function ensureContractForWonDeal(Deal $deal, PipelineStage $wonStage, User $owner): void
    {
        if (! ($wonStage->won_gate && $wonStage->won_gate_contract_required)) {
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
            'currency' => 'RUB',
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
}
