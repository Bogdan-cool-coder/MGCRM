"use client";

import React, { useMemo } from "react";

export interface RetentionLineChartProps {
  retentionPct: Record<string, Record<number, number>>;
  maxOffset: number;
  width?: number;
  height?: number;
}

const COHORT_COLORS = [
  "#172747",
  "#2B4987",
  "#3b82f6",
  "#10b981",
  "#f59e0b",
  "#ef4444",
  "#8b5cf6",
  "#06b6d4",
  "#84cc16",
  "#f97316",
  "#ec4899",
  "#64748b",
];

const PAD_LEFT = 50;
const PAD_BOTTOM = 30;
const PAD_TOP = 20;
const PAD_RIGHT = 20;

export function RetentionLineChart({
  retentionPct,
  maxOffset,
  width = 600,
  height = 300,
}: RetentionLineChartProps) {
  const innerW = width - PAD_LEFT - PAD_RIGHT;
  const innerH = height - PAD_TOP - PAD_BOTTOM;

  // Сортируем когорты по убыванию даты, максимум 12
  const cohortKeys = useMemo(() => {
    return Object.keys(retentionPct)
      .sort((a, b) => b.localeCompare(a))
      .slice(0, 12);
  }, [retentionPct]);

  // Рассчитываем среднюю линию по всем когортам
  const averageLine = useMemo(() => {
    if (cohortKeys.length === 0) return [];
    const offsets = Array.from({ length: maxOffset + 1 }, (_, i) => i);
    return offsets.map((offset) => {
      const values = cohortKeys
        .map((k) => retentionPct[k]?.[offset])
        .filter((v): v is number => v !== undefined);
      if (values.length === 0) return null;
      return values.reduce((a, b) => a + b, 0) / values.length;
    });
  }, [cohortKeys, retentionPct, maxOffset]);

  const toX = (offset: number) =>
    PAD_LEFT + (maxOffset === 0 ? innerW / 2 : (offset / maxOffset) * innerW);
  const toY = (pct: number) => PAD_TOP + innerH - (pct / 100) * innerH;

  // Y-метки: 0, 25, 50, 75, 100
  const yLabels = [0, 25, 50, 75, 100];

  // X-метки: зависят от maxOffset
  const xLabels = useMemo(() => {
    if (maxOffset <= 6) return Array.from({ length: maxOffset + 1 }, (_, i) => i);
    return [0, 3, 6, 9, 12, 18, 24].filter((v) => v <= maxOffset);
  }, [maxOffset]);

  if (cohortKeys.length === 0) {
    return (
      <div className="card p-6 text-center text-gray-400 dark:text-gray-500">
        Нет данных для построения графика
      </div>
    );
  }

  function buildPolylinePoints(cohortKey: string): string | null {
    const points: string[] = [];
    for (let offset = 0; offset <= maxOffset; offset++) {
      const pct = retentionPct[cohortKey]?.[offset];
      if (pct === undefined) break;
      points.push(`${toX(offset).toFixed(1)},${toY(pct).toFixed(1)}`);
    }
    return points.length >= 2 ? points.join(" ") : null;
  }

  function buildAveragePoints(): string | null {
    const points: string[] = [];
    for (let offset = 0; offset <= maxOffset; offset++) {
      const avg = averageLine[offset];
      if (avg === null || avg === undefined) break;
      points.push(`${toX(offset).toFixed(1)},${toY(avg).toFixed(1)}`);
    }
    return points.length >= 2 ? points.join(" ") : null;
  }

  const avgPoints = buildAveragePoints();

  return (
    <div className="card p-4">
      <div className="flex gap-4 items-start">
        {/* SVG график */}
        <div className="flex-1 min-w-0 overflow-x-auto">
          <svg
            viewBox={`0 0 ${width} ${height}`}
            className="w-full"
            style={{ minWidth: 320 }}
            aria-label="График retention по когортам"
          >
            {/* Grid lines горизонтальные */}
            {yLabels.map((y) => (
              <g key={y}>
                <line
                  x1={PAD_LEFT}
                  y1={toY(y)}
                  x2={PAD_LEFT + innerW}
                  y2={toY(y)}
                  stroke="#e5e7eb"
                  strokeWidth="1"
                  strokeDasharray="4,4"
                />
                <text
                  x={PAD_LEFT - 6}
                  y={toY(y) + 4}
                  textAnchor="end"
                  fontSize="10"
                  fill="#9ca3af"
                >
                  {y}%
                </text>
              </g>
            ))}

            {/* X-метки */}
            {xLabels.map((offset) => (
              <text
                key={offset}
                x={toX(offset)}
                y={height - 6}
                textAnchor="middle"
                fontSize="10"
                fill="#9ca3af"
              >
                {offset}
              </text>
            ))}

            {/* Рамка осей */}
            <line
              x1={PAD_LEFT}
              y1={PAD_TOP}
              x2={PAD_LEFT}
              y2={PAD_TOP + innerH}
              stroke="#d1d5db"
              strokeWidth="1"
            />
            <line
              x1={PAD_LEFT}
              y1={PAD_TOP + innerH}
              x2={PAD_LEFT + innerW}
              y2={PAD_TOP + innerH}
              stroke="#d1d5db"
              strokeWidth="1"
            />

            {/* Линии когорт (полупрозрачные) */}
            {cohortKeys.map((key, idx) => {
              const pts = buildPolylinePoints(key);
              if (!pts) return null;
              return (
                <polyline
                  key={key}
                  points={pts}
                  fill="none"
                  stroke={COHORT_COLORS[idx % COHORT_COLORS.length]}
                  strokeWidth="1.5"
                  strokeOpacity="0.6"
                  strokeLinejoin="round"
                />
              );
            })}

            {/* Средняя линия (жирная) */}
            {avgPoints && (
              <polyline
                points={avgPoints}
                fill="none"
                stroke="#172747"
                strokeWidth="2.5"
                strokeLinejoin="round"
              />
            )}
          </svg>
        </div>

        {/* Легенда */}
        <div className="shrink-0 space-y-1 pt-1">
          {cohortKeys.map((key, idx) => (
            <div key={key} className="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
              <span
                className="inline-block w-4 h-0.5 shrink-0"
                style={{ backgroundColor: COHORT_COLORS[idx % COHORT_COLORS.length] }}
              />
              {key}
            </div>
          ))}
          {avgPoints && (
            <div className="flex items-center gap-2 text-xs font-semibold text-primary dark:text-primary-light mt-2 pt-2 border-t border-gray-200 dark:border-gray-700">
              <span
                className="inline-block w-4 shrink-0 border-t-2 border-primary"
              />
              Среднее
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
