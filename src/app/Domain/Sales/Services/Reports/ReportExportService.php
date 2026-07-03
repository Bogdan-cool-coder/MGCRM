<?php

declare(strict_types=1);

namespace App\Domain\Sales\Services\Reports;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Data\BestManagerFilters;
use App\Domain\Sales\Data\ConversionReportFilters;
use App\Domain\Sales\Data\PlanMatrixQuery;
use App\Domain\Sales\Data\ProductIncomeFilters;
use App\Domain\Sales\Data\ReportFilters;
use App\Domain\Sales\Data\TaskMatrixFilters;
use App\Domain\Sales\Services\Planning\PlanTargetService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * ReportExportService — Ф5 Excel export for every Sales Analytics report
 * (contract §Excel export: "SpaceCRM only exports PNG for these reports —
 * our козырь") PLUS the P-1 planning matrix itself (`plans/matrix/export`,
 * frontend-requested alongside the report exports — same shape, same reuse).
 * Each `export*` method re-runs the SAME aggregator `build()`/`buildMatrix()`
 * call the JSON endpoint uses (reuse-gate — never a second query path, so
 * the .xlsx numbers can never drift from what the FE just displayed) and
 * hands the resulting array to `ReportXlsxExporter`'s shape-specific sheet
 * builders. Visibility scope is therefore identical to the JSON response
 * (the aggregator itself scopes rows, contract §8.2) — an export can never
 * leak a row the viewer couldn't already see on screen.
 */
class ReportExportService
{
    public function __construct(
        private readonly ExpectedIncomeRegistryService $registryService,
        private readonly IncomeScheduleService $incomeScheduleService,
        private readonly BestManagerService $bestManagerService,
        private readonly TaskMatrixService $taskMatrixService,
        private readonly ConversionReportService $conversionReportService,
        private readonly ProductIncomeService $productIncomeService,
        private readonly PlanTargetService $planTargetService,
        private readonly ReportXlsxExporter $exporter,
    ) {}

    /**
     * P-1 planning matrix export (`GET /api/plans/matrix/export`) — same
     * query shape as the JSON matrix; value fields switch on `value_kind`
     * (money → plan_kopecks/fact_kopecks/pct, count → plan_count/fact_count/pct)
     * exactly like the JSON payload does (contract §6.1).
     */
    public function exportPlanMatrix(PlanMatrixQuery $query, User $viewer): string
    {
        $data = $this->planTargetService->buildMatrix($query, $viewer);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Планы')->setCreator('MACRO Global CRM');

        $isMoney = $data['meta']['value_kind'] === 'money';
        $valueFields = $isMoney ? ['plan_kopecks', 'fact_kopecks', 'pct'] : ['plan_count', 'fact_count', 'pct'];
        $valueLabels = $isMoney
            ? ['plan_kopecks' => 'План', 'fact_kopecks' => 'Факт', 'pct' => '%']
            : ['plan_count' => 'План', 'fact_count' => 'Факт', 'pct' => '%'];

        $this->exporter->buildMatrixSheet(
            $spreadsheet,
            'Планы — '.$query->metric->value,
            $data['meta'],
            $data['columns'],
            $data['rows'],
            $data['totals'],
            $valueFields,
            $valueLabels,
        );

        return $this->exporter->toBytes($spreadsheet);
    }

    public function exportRegistry(ReportFilters $filters, User $viewer): string
    {
        $data = $this->registryService->build($filters, $viewer);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Реестр + Дожим')->setCreator('MACRO Global CRM');

        $this->exporter->buildListSheet(
            $spreadsheet,
            'Ожидаемые',
            $data['meta'],
            $data['expected']['rows'],
            $this->registryColumnMap(),
        );

        $this->exporter->buildListSheet(
            $spreadsheet,
            'Дожим',
            $data['meta'] + ['no_date_count' => $data['squeeze']['no_date_count']],
            $data['squeeze']['rows'],
            $this->registryColumnMap(),
            isFirstSheet: false,
        );

        $this->exporter->buildListSheet(
            $spreadsheet,
            'Без даты оплаты',
            $data['meta'],
            $data['squeeze']['no_date_rows'],
            $this->registryColumnMap(),
            isFirstSheet: false,
        );

        return $this->exporter->toBytes($spreadsheet);
    }

    public function exportIncomeSchedule(ReportFilters $filters, User $viewer): string
    {
        $data = $this->incomeScheduleService->build($filters, $viewer);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('График НП')->setCreator('MACRO Global CRM');

        $rows = array_map(
            static fn (array $day): array => ['scope' => ['label' => 'День '.$day['day']], 'cells' => ['1' => $day]],
            $data['days'],
        );

        $this->exporter->buildMatrixSheet(
            $spreadsheet,
            'График НП',
            $data['meta'] + ['plan_total_base_kopecks' => $data['plan_total_base_kopecks']],
            [['key' => '1', 'label' => 'Значение', 'period_month' => null]],
            $rows,
            [],
            ['fact_base_kopecks', 'expected_base_kopecks', 'squeeze_base_kopecks', 'cumulative_plan_base_kopecks', 'cumulative_fact_base_kopecks'],
            [
                'fact_base_kopecks' => 'Факт',
                'expected_base_kopecks' => 'Ожидается',
                'squeeze_base_kopecks' => 'Дожим',
                'cumulative_plan_base_kopecks' => 'План (накопительно)',
                'cumulative_fact_base_kopecks' => 'Факт (накопительно)',
            ],
        );

        return $this->exporter->toBytes($spreadsheet);
    }

    public function exportBestManager(BestManagerFilters $filters, User $viewer): string
    {
        $data = $this->bestManagerService->build($filters, $viewer);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Лучший менеджер')->setCreator('MACRO Global CRM');

        $rows = array_map(static function (array $row): array {
            $row['full_name'] = $row['user']['full_name'] ?? null;

            return $row;
        }, $data['rows']);

        $this->exporter->buildListSheet(
            $spreadsheet,
            'Рейтинг',
            $data['meta'],
            $rows,
            [
                'rank' => 'Место',
                'full_name' => 'Менеджер',
                'division' => 'Отдел',
                'won_deals' => 'Выиграно сделок',
                'supports' => 'Сопровождения',
                'new_income_base_kopecks' => 'НП (база)',
                'avg_check_base_kopecks' => 'Средний чек (база)',
                'income_points' => 'Очки за доход',
                'total_points' => 'Всего очков',
                'in_standings' => 'В зачёте',
            ],
        );

        return $this->exporter->toBytes($spreadsheet);
    }

    public function exportTaskMatrix(TaskMatrixFilters $filters, User $viewer): string
    {
        $data = $this->taskMatrixService->build($filters, $viewer);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Закрытие задач')->setCreator('MACRO Global CRM');

        foreach ($data['groups'] as $i => $group) {
            $this->exporter->buildMatrixSheet(
                $spreadsheet,
                $group['label'],
                $data['meta'] + ['kind' => $group['kind']],
                $group['columns'],
                $group['rows'],
                $group['totals'],
                ['plan_count', 'fact_count', 'pct'],
                ['plan_count' => 'План', 'fact_count' => 'Факт', 'pct' => '%'],
                isFirstSheet: $i === 0,
            );
        }

        return $this->exporter->toBytes($spreadsheet);
    }

    public function exportConversions(ConversionReportFilters $filters, User $viewer): string
    {
        $data = $this->conversionReportService->build($filters, $viewer);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Конверсии')->setCreator('MACRO Global CRM');

        $isFirst = true;

        foreach ($data['custom'] as $pair) {
            $this->exporter->buildMatrixSheet(
                $spreadsheet,
                $pair['name'],
                $data['meta'],
                $pair['columns'],
                [['scope' => ['label' => $pair['name']], 'cells' => $pair['cells']]],
                [],
                ['plan_pct', 'fact_pct', 'num_count', 'den_count', 'pct'],
                ['plan_pct' => 'План %', 'fact_pct' => 'Факт %', 'num_count' => 'Числитель', 'den_count' => 'Знаменатель', 'pct' => '% от плана'],
                isFirstSheet: $isFirst,
            );
            $isFirst = false;
        }

        // Honest stage-conversion chain — flat period-aggregate rows (contract
        // §6.8 as-built), no per-month cells; rendered as a simple list sheet.
        $this->exporter->buildListSheet(
            $spreadsheet,
            'Конверсии по этапам',
            $data['meta'],
            $data['stage'],
            ['name' => 'Переход', 'den_count' => 'Вошло', 'num_count' => 'Перешло', 'fact_pct' => 'Конверсия %'],
            isFirstSheet: $isFirst,
        );

        return $this->exporter->toBytes($spreadsheet);
    }

    public function exportProductIncome(ProductIncomeFilters $filters, User $viewer): string
    {
        $data = $this->productIncomeService->build($filters, $viewer);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setTitle('Поступления по линейкам')->setCreator('MACRO Global CRM');

        $this->exporter->buildMatrixSheet(
            $spreadsheet,
            'Поступления по линейкам',
            $data['meta'],
            $data['columns'],
            $data['rows'],
            $data['totals'],
            ['plan_kopecks', 'expected_kopecks', 'fact_kopecks', 'total_kopecks', 'total_pct'],
            [
                'plan_kopecks' => 'План',
                'expected_kopecks' => 'Ожидается',
                'fact_kopecks' => 'Факт',
                'total_kopecks' => 'Всего',
                'total_pct' => '% от плана',
            ],
        );

        return $this->exporter->toBytes($spreadsheet);
    }

    /**
     * @return array<string, string>
     */
    private function registryColumnMap(): array
    {
        return [
            'deal_id' => 'ID сделки',
            'title' => 'Название',
            'company_name' => 'Компания',
            'deal_type' => 'Тип',
            'amount_kopecks' => 'Сумма',
            'currency' => 'Валюта',
            'amount_base_kopecks' => 'Сумма (база)',
            'fact_kopecks' => 'Оплачено',
            'expected_payment_date' => 'Ожидаемая дата оплаты',
            'signed_at' => 'Дата подписания',
        ];
    }
}
