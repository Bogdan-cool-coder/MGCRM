"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import type {
  FinSettings,
  FinLegalEntity,
  FinRevaluationRun,
  UserRole,
} from "@/lib/types";

const ALLOWED_ROLES: UserRole[] = ["cfo", "admin"];

function monthLabel(year: number, month: number): string {
  return new Date(year, month - 1, 1).toLocaleString("ru-RU", { month: "long", year: "numeric" });
}

function lastTwelvePeriods(): { year: number; month: number }[] {
  const now = new Date();
  const out: { year: number; month: number }[] = [];
  for (let i = 0; i < 12; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    out.push({ year: d.getFullYear(), month: d.getMonth() + 1 });
  }
  return out;
}

function extractErrMsg(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "object" && d !== null && "detail" in d) return String((d as Record<string, unknown>)["detail"]);
    if (typeof d === "string") return d;
  }
  return "Ошибка операции";
}

export default function RevaluationPage() {
  const { toast } = useToast();
  const { data: settings } = useSWR<FinSettings>("/api/finance/settings", fetcher);
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const periods = lastTwelvePeriods();
  const [revEntityId, setRevEntityId] = useState("");
  const [revPeriodIdx, setRevPeriodIdx] = useState(0);
  const [revBusy, setRevBusy] = useState(false);
  const [revResult, setRevResult] = useState<FinRevaluationRun | null>(null);

  async function handleRevaluation() {
    if (!revEntityId) {
      toast.warning("Выберите юрлицо");
      return;
    }
    const period = periods[revPeriodIdx];
    setRevBusy(true);
    setRevResult(null);
    try {
      const res = await api<FinRevaluationRun>("/finance/revaluation/run", {
        method: "POST",
        body: {
          legal_entity_id: Number(revEntityId),
          year: period.year,
          month: period.month,
        },
      });
      setRevResult(res);
      toast.success(`Переоценка за ${period.year}-${String(period.month).padStart(2, "0")} выполнена`);
    } catch (err) {
      toast.error(extractErrMsg(err));
    } finally {
      setRevBusy(false);
    }
  }

  const currentBase = settings?.base_currency ?? "—";

  return (
    <RoleGate
      allowed={ALLOWED_ROLES}
      fallback={
        <div className="p-8 text-center flex flex-col items-center gap-3">
          <i className="bi bi-lock text-4xl text-gray-300 dark:text-gray-600" />
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Этот раздел доступен только CFO и администратору
          </p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Переоценка валютных остатков"
          description="Переоценка остатков счетов в валюте, отличной от функциональной, на конец периода"
        />

        <div className="p-6 flex flex-col gap-6 max-w-3xl">
          {/* Подсказка про базовую валюту */}
          <div className="flex items-start gap-2 p-3 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-sm">
            <i className="bi bi-info-circle text-info mt-0.5 shrink-0" />
            <p className="text-gray-600 dark:text-gray-400">
              Базовая валюта группы (сейчас:{" "}
              <strong className="text-gray-800 dark:text-gray-200">{currentBase}</strong>
              ) настраивается в разделе{" "}
              <Link href="/admin/currency-rates" className="text-primary dark:text-primary-light hover:underline">
                Курсы валют →
              </Link>
            </p>
          </div>

          {/* Форма переоценки */}
          <section className="card p-5 flex flex-col gap-4">
            <h2 className="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
              <i className="bi bi-arrow-repeat text-info" />
              Переоценка валютных остатков
            </h2>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              На конец периода остатки счетов в валюте, отличной от функциональной, переоцениваются
              по курсу. Курсовая разница попадает в P&L (счета 4910 доход / 5910 расход).
            </p>

            <div className="flex flex-wrap items-end gap-3">
              <div>
                <label className="label">Юрлицо</label>
                <select
                  className="input text-sm max-w-xs"
                  value={revEntityId}
                  onChange={(e) => setRevEntityId(e.target.value)}
                >
                  <option value="">Выберите юрлицо...</option>
                  {entities?.map((e) => (
                    <option key={e.id} value={e.id}>{e.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="label">Период</label>
                <select
                  className="input text-sm"
                  value={revPeriodIdx}
                  onChange={(e) => setRevPeriodIdx(Number(e.target.value))}
                >
                  {periods.map((p, idx) => (
                    <option key={`${p.year}-${p.month}`} value={idx}>
                      {monthLabel(p.year, p.month)}
                    </option>
                  ))}
                </select>
              </div>
              <button
                type="button"
                className="btn-secondary text-sm"
                disabled={!revEntityId || revBusy}
                onClick={handleRevaluation}
              >
                {revBusy ? (
                  <>
                    <i className="bi bi-hourglass mr-1" />
                    Переоцениваю...
                  </>
                ) : "Переоценить"}
              </button>
            </div>

            {revResult && (
              <div className="rounded-lg border border-success/30 bg-success/5 dark:bg-success/10 p-4 text-sm">
                <div className="font-medium text-success mb-2">
                  Переоценка за {revResult.period} выполнена
                </div>
                <ul className="text-xs text-gray-600 dark:text-gray-400 space-y-0.5">
                  <li>Обработано счетов: <strong>{revResult.processed}</strong></li>
                  <li>Переоценено: <strong>{revResult.revalued}</strong></li>
                  <li>Без изменений: <strong>{revResult.no_change}</strong></li>
                  <li>В функц. валюте (пропущено): <strong>{revResult.skipped_func_ccy}</strong></li>
                </ul>
              </div>
            )}
          </section>
        </div>
      </div>
    </RoleGate>
  );
}
