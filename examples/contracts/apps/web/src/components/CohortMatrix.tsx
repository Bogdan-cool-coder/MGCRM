"use client";

import React, { useMemo } from "react";
import { Tooltip } from "@/components/ui/Tooltip";
import { useTheme } from "@/contexts/ThemeContext";
import type { CohortData } from "@/lib/types";

// Reads effective dark state from the html element (set by ThemeProvider).
// useTheme() subscription ensures re-render on theme change.
function useIsDark(): boolean {
  useTheme(); // subscribe to theme changes so the component re-renders
  if (typeof document === "undefined") return false;
  return document.documentElement.classList.contains("dark");
}

export interface CohortMatrixProps {
  cohorts: CohortData[];
  retentionPct: Record<string, Record<number, number>>;
  matrix: Record<string, Record<number, number>>;
  maxOffset: number;
  onCohortClick?: (cohortMonth: string) => void;
}

/** Палитра тепловой карты: светлая и тёмная версии */
interface HeatTone {
  bgLight: string;
  bgDark: string;
  textLight: string;
  textDark: string;
}

function getHeatTone(pct: number | undefined): HeatTone {
  if (pct === undefined) {
    return {
      bgLight: "transparent",
      bgDark: "transparent",
      textLight: "#9ca3af",
      textDark: "#4b5563",
    };
  }
  if (pct >= 90) return { bgLight: "#bbf7d0", bgDark: "rgba(74,222,128,0.20)", textLight: "#14532d", textDark: "#86efac" };
  if (pct >= 75) return { bgLight: "#d1fae5", bgDark: "rgba(74,222,128,0.14)", textLight: "#166534", textDark: "#6ee7b7" };
  if (pct >= 60) return { bgLight: "#fef9c3", bgDark: "rgba(250,204,21,0.18)", textLight: "#713f12", textDark: "#fde047" };
  if (pct >= 45) return { bgLight: "#fef3c7", bgDark: "rgba(251,146,60,0.18)", textLight: "#78350f", textDark: "#fb923c" };
  if (pct >= 30) return { bgLight: "#fed7aa", bgDark: "rgba(251,146,60,0.26)", textLight: "#7c2d12", textDark: "#fb923c" };
  if (pct >= 15) return { bgLight: "#fecaca", bgDark: "rgba(248,113,113,0.22)", textLight: "#7f1d1d", textDark: "#f87171" };
  return { bgLight: "#fca5a5", bgDark: "rgba(239,68,68,0.32)", textLight: "#ffffff", textDark: "#fca5a5" };
}

/** Форматирование месяца когорты для отображения */
function fmtCohortMonth(month: string): string {
  try {
    const [year, m] = month.split("-");
    const d = new Date(Number(year), Number(m) - 1, 1);
    return d.toLocaleDateString("ru-RU", { year: "numeric", month: "short" });
  } catch {
    return month;
  }
}

/** Tooltip-контент для ячейки */
function cellTooltip(
  pct: number | undefined,
  absCount: number | undefined,
  initial: number,
  offset: number,
): React.ReactNode {
  if (pct === undefined || absCount === undefined) {
    return <span>Нет данных (+{offset} мес)</span>;
  }
  return (
    <span>
      <strong>{absCount}</strong> из {initial} клиентов
      <br />
      Retention {pct.toFixed(1)}% (+{offset} мес)
    </span>
  );
}

export function CohortMatrix({
  cohorts,
  retentionPct,
  matrix,
  maxOffset,
  onCohortClick,
}: CohortMatrixProps) {
  const isDark = useIsDark();

  const sortedCohorts = useMemo(
    () => [...cohorts].sort((a, b) => b.cohort_month.localeCompare(a.cohort_month)),
    [cohorts],
  );

  const offsets = useMemo(
    () => Array.from({ length: maxOffset + 1 }, (_, i) => i),
    [maxOffset],
  );

  if (sortedCohorts.length === 0) {
    return (
      <div className="card rounded-2xl p-6 text-center text-gray-400 dark:text-gray-500">
        Нет данных для отображения матрицы
      </div>
    );
  }

  return (
    <div className="card rounded-2xl overflow-hidden shadow-elev-1">
      {/* Горизонтальный скролл */}
      <div className="overflow-x-auto">
        <table
          className="text-xs border-collapse w-full"
          aria-label="Матрица retention по когортам"
        >
          <thead>
            <tr>
              {/* Sticky: Когорта */}
              <th
                scope="col"
                className="sticky left-0 z-20 bg-gray-50 dark:bg-gray-900 px-3 py-2.5 text-left font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-r border-gray-200 dark:border-gray-700 whitespace-nowrap"
                style={{ minWidth: "10rem" }}
              >
                Когорта
              </th>
              {/* Sticky: Размер */}
              <th
                scope="col"
                className="sticky z-20 bg-gray-50 dark:bg-gray-900 px-3 py-2.5 text-center font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-r border-gray-200 dark:border-gray-700 whitespace-nowrap w-16"
                style={{ left: "10rem" }}
              >
                Размер
              </th>
              {/* Offset заголовки */}
              {offsets.map((offset) => (
                <th
                  key={offset}
                  scope="col"
                  className="px-2 py-2.5 text-center font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700 whitespace-nowrap w-14 bg-gray-50 dark:bg-gray-900"
                >
                  +{offset}м
                </th>
              ))}
            </tr>
          </thead>

          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {sortedCohorts.map((cohort) => {
              const cohortPct = retentionPct[cohort.cohort_month] ?? {};
              const cohortMatrix = matrix[cohort.cohort_month] ?? {};
              return (
                <tr
                  key={cohort.cohort_month}
                  className="group cursor-pointer"
                  onClick={() => onCohortClick?.(cohort.cohort_month)}
                >
                  {/* Sticky: Когорта */}
                  <td
                    className="sticky left-0 z-10 bg-white dark:bg-gray-900 group-hover:bg-primary/[0.03] dark:group-hover:bg-primary/[0.07] px-3 py-2.5 font-semibold whitespace-nowrap text-primary dark:text-primary-light border-r border-gray-100 dark:border-gray-800 transition-colors duration-100"
                    style={{ minWidth: "10rem" }}
                  >
                    <span className="inline-flex items-center gap-1.5">
                      {fmtCohortMonth(cohort.cohort_month)}
                      <i className="bi bi-chevron-right text-[9px] opacity-0 group-hover:opacity-50 transition-opacity" />
                    </span>
                  </td>

                  {/* Sticky: Размер */}
                  <td
                    className="sticky z-10 bg-white dark:bg-gray-900 group-hover:bg-primary/[0.03] dark:group-hover:bg-primary/[0.07] px-3 py-2.5 text-center font-medium tabular-nums text-gray-600 dark:text-gray-300 border-r border-gray-100 dark:border-gray-800 transition-colors duration-100"
                    style={{ left: "10rem" }}
                  >
                    {cohort.initial_count}
                  </td>

                  {/* Тепловые ячейки */}
                  {offsets.map((offset) => {
                    const pct = cohortPct[offset];
                    const absCount = cohortMatrix[offset];
                    const tone = getHeatTone(pct);
                    const bg = isDark ? tone.bgDark : tone.bgLight;
                    const color = isDark ? tone.textDark : tone.textLight;

                    return (
                      <Tooltip
                        key={offset}
                        content={cellTooltip(pct, absCount, cohort.initial_count, offset)}
                        side="top"
                        delayDuration={250}
                      >
                        <td
                          className="px-2 py-2 text-center tabular-nums w-14 transition-opacity duration-100"
                        >
                          {pct !== undefined ? (
                            <span
                              className="inline-block w-full rounded px-1 py-0.5 text-[11px] font-semibold leading-snug"
                              style={{ backgroundColor: bg, color }}
                            >
                              {Math.round(pct)}%
                            </span>
                          ) : (
                            <span className="text-gray-300 dark:text-gray-700">—</span>
                          )}
                        </td>
                      </Tooltip>
                    );
                  })}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Легенда цветов */}
      <div className="flex flex-wrap items-center gap-1.5 px-4 py-2.5 border-t border-gray-100 dark:border-gray-800 bg-gray-50/60 dark:bg-gray-900/60">
        <span className="text-[11px] text-gray-400 dark:text-gray-500 mr-0.5">Retention:</span>
        {(
          [
            { label: "≥90%", bgL: "#bbf7d0", textL: "#14532d", bgD: "rgba(74,222,128,0.20)", textD: "#86efac" },
            { label: "75–89%", bgL: "#d1fae5", textL: "#166534", bgD: "rgba(74,222,128,0.14)", textD: "#6ee7b7" },
            { label: "60–74%", bgL: "#fef9c3", textL: "#713f12", bgD: "rgba(250,204,21,0.18)", textD: "#fde047" },
            { label: "45–59%", bgL: "#fef3c7", textL: "#78350f", bgD: "rgba(251,146,60,0.18)", textD: "#fb923c" },
            { label: "30–44%", bgL: "#fed7aa", textL: "#7c2d12", bgD: "rgba(251,146,60,0.26)", textD: "#fb923c" },
            { label: "<30%", bgL: "#fecaca", textL: "#7f1d1d", bgD: "rgba(248,113,113,0.22)", textD: "#f87171" },
          ] as const
        ).map(({ label, bgL, textL, bgD, textD }) => (
          <span
            key={label}
            className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold"
            style={{
              backgroundColor: isDark ? bgD : bgL,
              color: isDark ? textD : textL,
            }}
          >
            {label}
          </span>
        ))}
      </div>
    </div>
  );
}
