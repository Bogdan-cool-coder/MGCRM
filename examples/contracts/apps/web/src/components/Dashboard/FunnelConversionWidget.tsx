"use client";

/** Виджет «Конверсия воронки» (Эпик 6 MVP).
 *
 *  Селектор воронки → таблица per-stage метрик с count, avg_days_in_stage,
 *  conversion_pct (% переходов в следующий этап).
 *
 *  Без графиков (Recharts не в стеке) — простая таблица с барами per-stage.
 */
import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { FunnelResponse, Pipeline } from "@/lib/types";

interface Props {
  pipelines: Pipeline[] | undefined;
}

export function FunnelConversionWidget({ pipelines }: Props) {
  // Дефолт: первая активная воронка (sales/lifecycle — любая)
  const firstId = useMemo(() => {
    if (!pipelines || pipelines.length === 0) return null;
    const active = pipelines.filter((p) => p.is_active);
    if (active.length === 0) return null;
    // Приоритет: sales → lifecycle → lead → renewal → first
    const order = ["sales", "lifecycle", "lead", "renewal"];
    for (const k of order) {
      const found = active.find((p) => p.kind === k);
      if (found) return found.id;
    }
    return active[0].id;
  }, [pipelines]);

  const [selectedId, setSelectedId] = useState<number | null>(null);
  useEffect(() => {
    if (selectedId == null && firstId != null) setSelectedId(firstId);
  }, [firstId, selectedId]);

  const { data, isLoading } = useSWR<FunnelResponse>(
    selectedId ? `/analytics/funnel/${selectedId}` : null,
    fetcher,
  );

  const maxCount = useMemo(() => {
    if (!data) return 1;
    return Math.max(1, ...data.stages.map((s) => s.count));
  }, [data]);

  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-6 lift">
      <div className="flex items-center justify-between mb-3 gap-3">
        <h3 className="text-h5">Конверсия воронки</h3>
        <select
          className="input w-auto min-w-[180px] max-w-[260px] text-sm"
          value={selectedId ?? ""}
          onChange={(e) => setSelectedId(e.target.value ? Number(e.target.value) : null)}
          disabled={!pipelines || pipelines.length === 0}
        >
          {(pipelines ?? []).filter((p) => p.is_active).map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </div>

      {!selectedId ? (
        <div className="text-sm text-gray-500 dark:text-gray-400">Нет активных воронок.</div>
      ) : isLoading || !data ? (
        <div className="text-sm text-gray-500 dark:text-gray-400">Загрузка…</div>
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3 mb-3">
            <Tile label="В работе" value={data.total_active} accent="#2B4987" />
            <Tile label="Выиграно" value={data.total_won} accent="#1F9D55" />
            <Tile label="Проиграно" value={data.total_lost} accent="#C0392B" />
          </div>
          {data.stages.length === 0 ? (
            <div className="text-sm text-gray-500 dark:text-gray-400">Этапов нет.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-gray-600 dark:text-gray-300">
                  <tr className="border-b border-gray-200 dark:border-gray-700">
                    <th className="text-left py-1.5 pr-2 font-medium">Этап</th>
                    <th className="text-right py-1.5 px-2 font-medium">Кол-во</th>
                    <th className="text-right py-1.5 px-2 font-medium" title="Среднее время на этапе (приближённое — по Deal.updated_at)">
                      Ср. дней
                    </th>
                    <th className="text-right py-1.5 pl-2 font-medium" title="% переходов на следующий этап">
                      Конверсия
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {data.stages.map((s) => {
                    const conv = s.transition_to_next_pct ?? s.conversion_pct ?? null;
                    const isTerminal = s.is_won || s.is_lost;
                    const colorClass = s.is_won
                      ? "text-success"
                      : s.is_lost
                      ? "text-danger"
                      : conv != null && conv < 25
                      ? "text-danger"
                      : conv != null && conv < 50
                      ? "text-warning"
                      : "text-gray-700 dark:text-gray-200";
                    return (
                      <tr key={s.stage_id} className="border-b border-gray-100 dark:border-gray-700/50 last:border-b-0">
                        <td className="py-1.5 pr-2">
                          <div className="flex items-center gap-2">
                            <div className="w-14 h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden">
                              <div
                                className="h-full bg-primary-light"
                                style={{ width: `${(s.count / maxCount) * 100}%` }}
                              />
                            </div>
                            <span className="truncate" title={s.stage_name}>{s.stage_name}</span>
                          </div>
                        </td>
                        <td className="py-1.5 px-2 text-right tabular-nums">{s.count}</td>
                        <td className="py-1.5 px-2 text-right tabular-nums text-gray-500 dark:text-gray-400">
                          {s.avg_days_in_stage != null ? s.avg_days_in_stage : "—"}
                        </td>
                        <td className={`py-1.5 pl-2 text-right tabular-nums font-medium ${colorClass}`}>
                          {isTerminal ? "—" : conv != null ? `${conv}%` : "—"}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function Tile({ label, value, accent }: { label: string; value: number; accent: string }) {
  return (
    <div className="rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2">
      <div className="text-h4 tabular-nums" style={{ color: accent }}>{value}</div>
      <div className="text-xs text-gray-500 dark:text-gray-400">{label}</div>
    </div>
  );
}
