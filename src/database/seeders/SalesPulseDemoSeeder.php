<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Activity\Enums\ActivityPriority;
use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use App\Domain\Sales\Models\Deal;
use App\Domain\Sales\Models\DealStageHistory;
use App\Domain\Sales\Models\LostReason;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * SAMPLE seeder (NOT baseline — reset-clean never runs it). Builds a live,
 * "today"-anchored dataset in the two AMO funnels (MACRO Global / MACRO AI Global)
 * for the three demo managers so the SalesPulse bot commands
 * (/startday /finishday /progress /dayresults) immediately show real numbers once
 * the bot is up. Mirrors how the AMO bot's collect_day reads our DB:
 *   - task-like deal activities (call/meeting/task/follow_up) with due_at TODAY →
 *     PLAN; the subset done+completed_at TODAY → FACT.
 *   - FTM meetings (is_first_time_meeting) completed today → announcer / MeetingDone.
 *   - notes created today → the "есть заметка" signal (missed-task suppression).
 *   - DealStageHistory rows today → status-update / downgrade / lost / won signals.
 *
 * "Today" is the calendar day in the SalesPulse timezone (Asia/Dubai), matching
 * DayWindowResolver — so every due_at / completed_at / created_at below lands in
 * the day window the bot collects.
 *
 * Idempotency: deals keyed by stable title, activities by (title, target, manager),
 * stage-history by (deal, to_stage, day). Re-running does not duplicate. Depends on
 * AmoPipelineSeeder (funnels + stages). Managers are reused from ManagerKpiSeeder
 * by email, or created here if that seeder has not run.
 *
 * @SuppressWarnings(PHPMD)
 */
class SalesPulseDemoSeeder extends Seeder
{
    /**
     * Demo manager roster — same emails as ManagerKpiSeeder so the two seeders
     * share the accounts (update-or-create by email).
     *
     * @var list<array{email: string, full_name: string}>
     */
    private const MANAGERS = [
        ['email' => 'manager1@mgcrm.test', 'full_name' => 'Иванов Алексей Сергеевич'],
        ['email' => 'manager2@mgcrm.test', 'full_name' => 'Петрова Мария Сергеевна'],
        ['email' => 'manager3@mgcrm.test', 'full_name' => 'Сидоров Антон Константинович'],
    ];

    /**
     * Per-manager deal plan: [funnel key, stage code, company name, currency, amount kopecks].
     * Funnel key: 'global' = MACRO Global, 'ai' = MACRO AI Global. Stage codes are
     * the AMO codes for the chosen funnel. One row per manager is rendered with the
     * manager index suffixed into the company name so titles stay unique & stable.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    private const DEALS = [
        // Open funnel spread across both funnels.
        ['global', 'qualification', 'ООО «Берег»', 'RUB', 1_200_000_00],
        ['global', 'schedule', 'ТД «Вектор»', 'RUB', 850_000_00],
        ['global', 'meeting', 'Логистик Плюс', 'RUB', 2_100_000_00],
        ['global', 'warm', 'Финтех Альянс', 'RUB', 3_400_000_00],
        ['ai', 'qualification', 'AI Retail Group', 'USD', 18_000_00],
        ['ai', 'hot', 'NeoData Systems', 'USD', 42_000_00],
        // Terminal / freeze states.
        ['global', 'success', 'СтройМонтаж Холдинг', 'RUB', 5_600_000_00],
        ['ai', 'success', 'CloudOps DMCC', 'USD', 65_000_00],
        ['global', 'cold', 'Северный Регион', 'RUB', 900_000_00],
        ['global', 'lost', 'Мега Маркет', 'RUB', 1_100_000_00],
    ];

    public function run(): void
    {
        $global = $this->funnel('MACRO Global');
        $ai = $this->funnel('MACRO AI Global');
        if ($global === null || $ai === null) {
            return; // AmoPipelineSeeder has not run — nothing to seed against.
        }

        $stages = [
            'global' => $this->stagesByCode($global),
            'ai' => $this->stagesByCode($ai),
        ];
        $pipelineIds = ['global' => $global->id, 'ai' => $ai->id];

        $dept = Department::firstOrCreate(['name' => 'Отдел продаж'], ['parent_id' => null, 'manager_id' => null]);
        $lostReason = LostReason::query()->where('is_active', true)->orderBy('sort_order')->first();

        $today = CarbonImmutable::now(config('salespulse.timezone', 'Asia/Dubai'));

        foreach (self::MANAGERS as $i => $def) {
            $manager = $this->manager($def, $dept);

            foreach (self::DEALS as $deal) {
                [$funnelKey, $stageCode, $companyName, $currency, $amount] = $deal;

                $stage = $stages[$funnelKey][$stageCode] ?? null;
                if ($stage === null) {
                    continue;
                }

                $company = $this->company("{$companyName} #".($i + 1), $manager, $dept);
                $isClosed = $stage->is_won || $stage->is_lost;

                $created = Deal::firstOrNew(['title' => $this->dealTitle($companyName, $stageCode, $i)]);
                if (! $created->exists) {
                    $created->fill([
                        'pipeline_id' => $pipelineIds[$funnelKey],
                        'stage_id' => $stage->id,
                        'company_id' => $company->id,
                        'amount' => $amount,
                        'currency' => $currency,
                        'owner_user_id' => $manager->id,
                        'department_id' => $dept->id,
                        'lost_reason_id' => $stage->is_lost ? $lostReason?->id : null,
                        'lost_reason' => $stage->is_lost ? $lostReason?->name : null,
                        'tags' => ['demo', 'salespulse'],
                        'extra_fields' => [],
                        'stage_changed_at' => $today->subHours(3),
                        'closed_at' => $isClosed ? $today->subHours(2) : null,
                    ]);
                    $created->save();
                }

                $this->seedActivities($manager, $created, $stage, $today);
                $this->seedStageHistory($created, $stage, $manager, $stages[$funnelKey], $today);
            }
        }
    }

    /**
     * Seed today's deal-bound activities for a manager:
     *   - one open task-like activity due TODAY  → PLAN (and "missed" candidate),
     *   - one done task-like activity completed TODAY → PLAN + FACT,
     *   - on a couple of deals, a completed FTM meeting today → announcer signal,
     *   - one note created today → "есть заметка" (suppresses the missed flag),
     *   - one extra (out-of-plan-style) completed call today on won/hot deals.
     */
    private function seedActivities(User $manager, Deal $deal, PipelineStage $stage, CarbonImmutable $today): void
    {
        // Open task due today (PLAN). Skipped on terminal stages (closed deals carry
        // no live next task).
        if (! ($stage->is_won || $stage->is_lost)) {
            $this->activity(
                $manager,
                $deal,
                ActivityType::Task,
                title: 'Подготовить КП и согласовать условия',
                dueAt: $today->setTime(15, 0),
                done: false,
            );
        }

        // Done call completed today (PLAN + FACT). Every deal gets one so each
        // manager has a non-empty fact.
        $this->activity(
            $manager,
            $deal,
            ActivityType::Call,
            title: 'Звонок: уточнить статус по сделке',
            dueAt: $today->setTime(11, 0),
            done: true,
            completedAt: $today->setTime(11, 30),
            resultText: 'Дозвонились до ЛПР, договорились о следующем шаге. Клиент заинтересован, ждёт КП.',
        );

        // FTM meeting completed today on the first two non-terminal deals per
        // manager → MeetingDone / announcer.
        if (! ($stage->is_won || $stage->is_lost) && in_array($stage->code, ['meeting', 'hot'], true)) {
            $this->activity(
                $manager,
                $deal,
                ActivityType::Meeting,
                title: 'Первая встреча с клиентом (демо продукта)',
                dueAt: $today->setTime(13, 0),
                done: true,
                completedAt: $today->setTime(14, 0),
                resultText: 'Провели первую встречу, показали демо. ЛПР присутствовал, реакция положительная.',
                isFtm: true,
            );
        }

        // Extra completed call on hot / won deals (out-of-plan flavour).
        if (in_array($stage->code, ['hot', 'success'], true)) {
            $this->activity(
                $manager,
                $deal,
                ActivityType::Call,
                title: 'Внеплановый звонок по оплате',
                dueAt: $today->setTime(16, 0),
                done: true,
                completedAt: $today->setTime(16, 20),
                resultText: 'Согласовали дату оплаты и реквизиты.',
            );
        }

        // Note created today → "есть заметка".
        $this->activity(
            $manager,
            $deal,
            ActivityType::Note,
            title: 'Заметка: клиент просил материалы на русском и английском',
            dueAt: null,
            done: false,
            createdAt: $today->setTime(10, 0),
        );
    }

    /**
     * Today's stage transitions for the announcer / metrics: forward moves on open
     * deals, a downgrade into cold, a move into lost, a move into won. Keyed by
     * (deal, to_stage, today) so re-running does not duplicate.
     */
    private function seedStageHistory(Deal $deal, PipelineStage $stage, User $manager, array $stagesByCode, CarbonImmutable $today): void
    {
        // from_stage: a plausible prior stage so MetricsService can classify the
        // move. For won/lost/cold we synthesize a forward-ish prior; for open
        // stages we use a one-step-earlier stage when available.
        $from = $this->priorStage($stage, $stagesByCode);

        $exists = DealStageHistory::query()
            ->where('deal_id', $deal->id)
            ->where('to_stage_id', $stage->id)
            ->whereBetween('created_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
            ->exists();

        if ($exists) {
            return;
        }

        DealStageHistory::create([
            'deal_id' => $deal->id,
            'from_stage_id' => $from?->id,
            'to_stage_id' => $stage->id,
            'user_id' => $manager->id,
            'created_at' => $today->subHours(3),
        ]);
    }

    /**
     * A plausible prior stage for a transition into $stage. For cold/lost we pick a
     * mid-funnel stage (downgrade/lost), for won the last hot stage, for forward
     * open stages the immediately lower sort_order stage.
     *
     * @param  array<string, PipelineStage>  $stagesByCode
     */
    private function priorStage(PipelineStage $stage, array $stagesByCode): ?PipelineStage
    {
        if ($stage->is_lost || $stage->code === 'cold') {
            return $stagesByCode['warm'] ?? $stagesByCode['qualification'] ?? null;
        }

        if ($stage->is_won) {
            return $stagesByCode['hot'] ?? $stagesByCode['warm'] ?? null;
        }

        // Forward open move: the active stage with the greatest sort_order below
        // this one.
        $prior = null;
        foreach ($stagesByCode as $candidate) {
            if ($candidate->is_won || $candidate->is_lost || $candidate->hidden_by_default) {
                continue;
            }
            if ($candidate->sort_order < $stage->sort_order
                && ($prior === null || $candidate->sort_order > $prior->sort_order)) {
                $prior = $candidate;
            }
        }

        return $prior;
    }

    /**
     * Create/find one deal-bound activity (idempotent by title + target + manager).
     */
    private function activity(
        User $manager,
        Deal $deal,
        ActivityType $kind,
        string $title,
        ?CarbonImmutable $dueAt,
        bool $done,
        ?CarbonImmutable $completedAt = null,
        ?string $resultText = null,
        ?CarbonImmutable $createdAt = null,
        bool $isFtm = false,
    ): void {
        $attrs = [
            'kind' => $kind->value,
            'body' => null,
            'due_at' => $dueAt,
            'completed_at' => $done ? ($completedAt ?? $dueAt) : null,
            'completed_by_id' => $done ? $manager->id : null,
            'responsible_id' => $manager->id,
            'created_by_id' => $manager->id,
            'priority' => ActivityPriority::Normal->value,
            'status' => $done ? ActivityStatus::Done->value : ActivityStatus::New->value,
            'is_closed' => false,
            'progress_pct' => $done ? 100 : 0,
            'result_text' => $resultText,
            'is_first_time_meeting' => $isFtm,
            'ftm_decision_maker_attended' => $isFtm ? true : false,
            'ftm_presentation_shown' => $isFtm ? true : false,
            'department_id' => $deal->department_id,
        ];

        if ($createdAt !== null) {
            $attrs['created_at'] = $createdAt;
            $attrs['updated_at'] = $createdAt;
        }

        Activity::firstOrCreate(
            [
                'title' => $title,
                'target_type' => ActivityTargetType::Deal->value,
                'target_id' => $deal->id,
                'responsible_id' => $manager->id,
            ],
            $attrs,
        );
    }

    private function manager(array $def, Department $dept): User
    {
        $manager = User::updateOrCreate(
            ['email' => $def['email']],
            [
                'full_name' => $def['full_name'],
                'password' => Hash::make('password'),
                'role' => Role::Manager,
                'department_id' => $dept->id,
                'is_active' => true,
                'locale' => 'ru',
                'totp_enabled' => false,
            ],
        );
        $manager->syncRoles([Role::Manager->value]);

        return $manager;
    }

    private function company(string $name, User $owner, Department $dept): Company
    {
        return Company::firstOrCreate(
            ['name' => $name],
            [
                'country_code' => 'ae',
                'source' => 'own_contact',
                'owner_user_id' => $owner->id,
                'department_id' => $dept->id,
                'tags' => ['demo'],
                'extra_fields' => [],
            ],
        );
    }

    private function funnel(string $name): ?Pipeline
    {
        return Pipeline::query()->where('name', $name)->with('stages')->first();
    }

    /**
     * @return array<string, PipelineStage>
     */
    private function stagesByCode(Pipeline $pipeline): array
    {
        return $pipeline->stages->keyBy('code')->all();
    }

    private function dealTitle(string $companyName, string $stageCode, int $managerIndex): string
    {
        return "[SP] {$companyName} #".($managerIndex + 1)." — {$stageCode}";
    }
}
