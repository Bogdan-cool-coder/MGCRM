"use client";

/** Виджет «Forecast выручки» (Эпик 6 MVP).
 *
 *  GET /api/analytics/forecast?pipeline_id=… → ожидаемая выручка
 *  (active_count × avg_won_value × probability_by_stage), таблица
 *  per-stage breakdown.
 *
 *  Только sales-воронки. Если sales нет — placeholder.
 */
import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { formatCurrency, formatNumberRu } from "@/lib/format";
import type { ForecastResponse, Pipeline } from "@/lib/types";

interface Props {
  pipelines: Pipeline[] | undefined;
}

export function RevenueForecastWidget({ pipelines }: Props) {
  const salesPipelines = useMemo(
    () => (pipelines ?? []).filter((p) => p.kind === "sales" && p.is_active),
    [pipelines],
  );
  const firstId = salesPipelines[0]?.id ?? null;

  const [selectedId, setSelectedId] = useState<number | null>(null);
  useEffect(() => {
    if (selectedId == null && firstId != null) setSelectedId(firstId);
  }, [firstId, selectedId]);

  const { data, isLoading } = useSWR<ForecastResponse>(
    selectedId ? `/analytics/forecast?pipeline_id=${selectedId}` : null,
    fetcher,
  );

  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-3 gap-3">
        <h3 className="text-h5">Forecast выручки</h3>
        <select
          className="input w-auto min-w-[180px] max-w-[260px] text-sm"
          value={selectedId ?? ""}
          onChange={(e) => setSelectedId(e.target.value ? Number(e.target.value) : null)}
          disabled={salesPipelines.length === 0}
        >
          {salesPipelines.length === 0 ? (
            <option value="">Нет sales-воронок</option>
          ) : (
            salesPipelines.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))
          )}
        </select>
      </div>

      {salesPipelines.length === 0 ? (
        <div className="text-sm text-gray-500 dark:text-gray-400">
          Нет активных sales-воронок. Создайте воронку с типом «sales» в разделе «Конструктор воронок».
        </div>
      ) : !selectedId ? (
        <div className="text-sm text-gray-500 dark:text-gray-400">Выберите воронку.</div>
      ) : isLoading || !data ? (
        <div className="text-sm text-gray-500 dark:text-gray-400">Загрузка…</div>
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3 mb-3">
            <Tile
              label="Средний чек"
              value={formatCurrency(data.avg_value_per_won, data.currency, { maxFractionDigits: 0 })}
              hint={`Выиграно сделок: ${data.won_count}`}
            />
            <Tile
              label="Сделок в работе"
              value={formatNumberRu(
                data.by_stage_breakdown.reduce((acc, s) => acc + s.count, 0),
              )}
            />
            <Tile
              label="Прогноз выручки"
              value={formatCurrency(data.estimated_revenue, data.currency, { maxFractionDigits: 0 })}
              accent="#1F9D55"
            />
          </div>

          {data.by_stage_breakdown.length === 0 ? (
            <div className="text-sm text-gray-500 dark:text-gray-400">Нет активных сделок для прогноза.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-gray-600 dark:text-gray-300">
                  <tr className="border-b border-gray-200 dark:border-gray-700">
                    <th className="text-left py-1.5 pr-2 font-medium">Этап</th>
                    <th className="text-right py-1.5 px-2 font-medium">Сделок</th>
                    <th className="text-right py-1.5 px-2 font-medium" title="Вероятность закрытия по этапу (heuristic на имени этапа)">
                      Вероятн.
                    </th>
                    <th className="text-right py-1.5 pl-2 font-medium">Ожидаемая выручка</th>
                  </tr>
                </thead>
                <tbody>
                  {data.by_stage_breakdown.map((s) => (
                    <tr key={s.stage_id} className="border-b border-gray-100 dark:border-gray-700/50 last:border-b-0">
                      <td className="py-1.5 pr-2 truncate" title={s.stage_name}>{s.stage_name}</td>
                      <td className="py-1.5 px-2 text-right tabular-nums">{s.count}</td>
                      <td className="py-1.5 px-2 text-right tabular-nums text-gray-500 dark:text-gray-400">
                        {Math.round(s.probability * 100)}%
                      </td>
                      <td className="py-1.5 pl-2 text-right tabular-nums font-medium">
                        {formatCurrency(s.estimated, data.currency, { maxFractionDigits: 0 })}
                      </td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-gray-300 dark:border-gray-600">
                    <td className="py-2 pr-2 font-semibold">Итого</td>
                    <td />
                    <td />
                    <td className="py-2 pl-2 text-right tabular-nums font-semibold" style={{ color: "#1F9D55" }}>
                      {formatCurrency(data.estimated_revenue, data.currency, { maxFractionDigits: 0 })}
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function Tile({ label, value, hint, accent }: { label: string; value: string; hint?: string; accent?: string }) {
  return (
    <div className="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2">
      <div className="text-h4 tabular-nums" style={accent ? { color: accent } : undefined}>{value}</div>
      <div className="text-xs text-gray-500 dark:text-gray-400">{label}</div>
      {hint && <div className="text-[11px] text-gray-400 dark:text-gray-500 mt-0.5">{hint}</div>}
    </div>
  );
}
