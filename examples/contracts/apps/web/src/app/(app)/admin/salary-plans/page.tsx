"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { UserSelect } from "@/components/UserSelect";
import { SalaryPlansList } from "@/components/SalaryPlans/SalaryPlansList";
import { EmptyState } from "@/components/EmptyState";
import type { SalaryPlan } from "@/lib/types";

const MONTHS_RU = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

const STATUS_OPTIONS = [
  { value: "", label: "Все статусы" },
  { value: "draft", label: "Черновик" },
  { value: "finalized", label: "Финализирован" },
  { value: "paid", label: "Выплачен" },
];

function generatePeriods() {
  const now = new Date();
  const opts: { value: string; year: number; month: number; label: string }[] = [];
  for (let i = 0; i < 12; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const y = d.getFullYear();
    const m = d.getMonth() + 1;
    opts.push({ value: `${y}-${m}`, year: y, month: m, label: `${MONTHS_RU[m - 1]} ${y}` });
  }
  return opts;
}

export default function SalaryPlansPage() {
  const periods = generatePeriods();
  const [userId, setUserId] = useState("");
  const [period, setPeriod] = useState("");
  const [status, setStatus] = useState("");

  const params = new URLSearchParams();
  if (userId) params.set("user_id", userId);
  if (period) {
    const p = periods.find((x) => x.value === period);
    if (p) { params.set("year", String(p.year)); params.set("month", String(p.month)); }
  }
  if (status) params.set("status", status);

  const { data: plans, isLoading, error } = useSWR<SalaryPlan[]>(
    `/admin/salary-plans?${params.toString()}`,
    fetcher,
  );

  return (
    <RoleGate allowed={["admin", "director"]}>
      <PageHeader
        title="Планы зарплат"
        actions={
          <a href="/admin/salary-plans/new" className="btn-primary text-sm">
            <i className="bi bi-plus-lg mr-1" />
            Создать план
          </a>
        }
      />

      <div className="p-6 space-y-4">
        {/* Фильтры */}
        <div className="flex flex-wrap gap-3 items-end">
          <div>
            <label className="label text-xs">Менеджер</label>
            <UserSelect
              value={userId}
              onChange={setUserId}
              placeholder="Все"
              className="input w-48"
            />
          </div>
          <div>
            <label className="label text-xs">Период</label>
            <select
              className="input w-44"
              value={period}
              onChange={(e) => setPeriod(e.target.value)}
            >
              <option value="">Все периоды</option>
              {periods.map((p) => (
                <option key={p.value} value={p.value}>{p.label}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label text-xs">Статус</label>
            <select
              className="input w-40"
              value={status}
              onChange={(e) => setStatus(e.target.value)}
            >
              {STATUS_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>
        </div>

        {/* Skeleton */}
        {isLoading && (
          <div className="card rounded-2xl overflow-hidden animate-pulse">
            <div className="h-10 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700" />
            {[1, 2, 3].map((i) => (
              <div key={i} className="flex items-center gap-4 px-4 py-3 border-b border-gray-100 dark:border-gray-800">
                <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/4" />
                <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-16" />
                <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-24 ml-auto" />
              </div>
            ))}
          </div>
        )}

        {!isLoading && error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
            Не удалось загрузить планы
          </div>
        )}

        {!isLoading && !error && plans && plans.length === 0 && (
          <div className="card rounded-2xl">
            <EmptyState
              icon="bi-cash-coin"
              title="Нет планов зарплат"
              description="Попробуйте изменить фильтры или создайте новый план"
              cta={
                <a href="/admin/salary-plans/new" className="btn-primary text-sm">
                  <i className="bi bi-plus-lg mr-1" /> Создать план
                </a>
              }
            />
          </div>
        )}

        {!isLoading && !error && plans && plans.length > 0 && (
          <SalaryPlansList plans={plans} />
        )}
      </div>
    </RoleGate>
  );
}
