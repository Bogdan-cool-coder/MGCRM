<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Dashboard;
use App\Models\Widget;
use Illuminate\Database\Seeder;

class DashboardSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure widgets exist before building dashboards.
        $this->call(WidgetSeeder::class);

        $company = Company::where('name', 'Vizion')->where('is_system', true)->firstOrFail();

        // Helper: resolve widget id by RU name, warn if missing.
        $widgetId = function (string $nameRu) use ($company): ?int {
            $w = Widget::where('is_system', true)
                ->where('name->ru', $nameRu)
                ->first();
            if (!$w) {
                $this->command->warn("Widget \"{$nameRu}\" not found — placement skipped.");
                return null;
            }
            return $w->id;
        };

        // Helper: upsert a dashboard and sync widget placements.
        // $placements: [ [widget_name_ru, x, y, w, h, sort, visible], ... ]
        $upsertDashboard = function (
            string $nameRu,
            string $nameEn,
            array  $placements,
        ) use ($company, $widgetId): void {
            $dashboard = Dashboard::where('is_system', true)
                ->where('name->ru', $nameRu)
                ->first();

            if (!$dashboard) {
                $dashboard = Dashboard::create([
                    'name'         => ['ru' => $nameRu, 'en' => $nameEn],
                    'is_system'    => true,
                    'is_published' => true,
                    'company_id'   => $company->id,
                    'user_id'      => null,
                ]);
                $this->command->info("Dashboard \"{$nameRu}\" created.");
            } else {
                $dashboard->update([
                    'name'         => ['ru' => $nameRu, 'en' => $nameEn],
                    'is_system'    => true,
                    'is_published' => true,
                    'company_id'   => $company->id,
                    'user_id'      => null,
                ]);
                $this->command->info("Dashboard \"{$nameRu}\" updated.");
            }

            foreach ($placements as [$wNameRu, $x, $y, $w, $h, $sort, $visible]) {
                $id = $widgetId($wNameRu);
                if ($id === null) {
                    continue;
                }
                $dashboard->widgets()->syncWithoutDetaching([
                    $id => [
                        'x'       => $x,
                        'y'       => $y,
                        'w'       => $w,
                        'h'       => $h,
                        'sort'    => $sort,
                        'visible' => $visible,
                    ],
                ]);
            }

            $placedCount = $dashboard->widgets()->count();
            $this->command->info("  → {$placedCount} widget(s) placed.");
        };

        // ─── Dashboard 1: Обзорный (Overview) ────────────────────────────────
        // Grid 12 cols × 6 rows per widget. 2 per row.
        // Row  0: Динамика продаж (full width — line chart, «первое что видит клиент»)
        // Row  6: Сделки по статусам | Структура фонда
        // Row 12: Выручка по менеджерам | Лиды по каналам
        $upsertDashboard(
            'Обзорный дашборд',
            'Overview',
            [
                // widget_name_ru,                    x,  y,  w,  h, sort, visible
                ['Динамика продаж',                   0,  0, 12,  6,    1, true],
                ['Сделки по статусам',                0,  6,  6,  6,    2, true],
                ['Структура фонда',                   6,  6,  6,  6,    3, true],
                ['Выручка по менеджерам',             0, 12,  6,  6,    4, true],
                ['Лиды по каналам',                   6, 12,  6,  6,    5, true],
            ],
        );

        // ─── Dashboard 2: Продажи (Sales) ────────────────────────────────────
        // Row  0: Динамика продаж (full width)
        // Row  6: Сделки по менеджерам | Выручка по менеджерам
        // Row 12: Продажи по ЖК | Сделки по статусам
        // Row 18: Выручка по отделам (full width)
        $upsertDashboard(
            'Продажи',
            'Sales',
            [
                ['Динамика продаж',                   0,  0, 12,  6,    1, true],
                ['Сделки по менеджерам',              0,  6,  6,  6,    2, true],
                ['Выручка по менеджерам',             6,  6,  6,  6,    3, true],
                ['Продажи по ЖК',                     0, 12,  6,  6,    4, true],
                ['Сделки по статусам',                6, 12,  6,  6,    5, true],
                ['Выручка по отделам',                0, 18, 12,  6,    6, true],
            ],
        );

        // ─── Dashboard 3: Маркетинг (Marketing) ──────────────────────────────
        // Row 0: Динамика лидов (full width)
        // Row 6: Лиды по каналам | Лиды по менеджерам
        // Row 12: Лиды по статусам | Лиды по ЖК
        $upsertDashboard(
            'Маркетинг',
            'Marketing',
            [
                ['Динамика лидов',                    0,  0, 12,  6,    1, true],
                ['Лиды по каналам',                   0,  6,  6,  6,    2, true],
                ['Лиды по менеджерам',                6,  6,  6,  6,    3, true],
                ['Лиды по статусам',                  0, 12,  6,  6,    4, true],
                ['Лиды по ЖК',                        6, 12,  6,  6,    5, true],
            ],
        );

        // ─── Dashboard 4: Финансы (Finance) ──────────────────────────────────
        // Row 0: Динамика поступлений (full width)
        // Row 6: Поступления по типам | Выручка по менеджерам
        $upsertDashboard(
            'Финансы',
            'Finance',
            [
                ['Динамика поступлений',              0,  0, 12,  6,    1, true],
                ['Поступления по типам',              0,  6,  6,  6,    2, true],
                ['Выручка по менеджерам',             6,  6,  6,  6,    3, true],
            ],
        );
    }
}
