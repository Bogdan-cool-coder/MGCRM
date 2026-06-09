"use client";

import Link from "next/link";
import { api } from "@/lib/api";
import { useSWRConfig } from "swr";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { formatCurrency } from "@/lib/format";
import type { SalaryPlan } from "@/lib/types";

interface Props {
  plans: SalaryPlan[];
}

const STATUS_BADGE: Record<string, string> = {
  draft:     "bg-info-50    text-info-700    dark:bg-info-500/10    dark:text-info-400",
  finalized: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
  paid:      "bg-primary/10 text-primary     dark:bg-primary/15     dark:text-blue-300",
};

const STATUS_LABEL: Record<string, string> = {
  draft:     "Черновик",
  finalized: "Финализирован",
  paid:      "Выплачен",
};

const MONTHS_RU = [
  "Янв", "Фев", "Мар", "Апр", "Май", "Июн",
  "Июл", "Авг", "Сен", "Окт", "Ноя", "Дек",
];

export function SalaryPlansList({ plans }: Props) {
  const { mutate } = useSWRConfig();

  async function handleCompute(plan: SalaryPlan) {
    try {
      await api(`/admin/motivational-cards/${plan.user_id}/${plan.year}/${plan.month}/compute`, {
        method: "POST",
      });
      mutate("/admin/salary-plans");
    } catch {
      // silent — страница detail покажет результат
    }
  }

  const columns: DataTableColumn<SalaryPlan>[] = [
    {
      key: "user_name",
      header: "Менеджер",
      skeletonWidth: "50%",
      render: (plan) => (
        <Link
          href={`/admin/salary-plans/${plan.user_id}/${plan.year}/${plan.month}`}
          className="font-medium text-primary hover:underline"
          onClick={(e) => e.stopPropagation()}
        >
          {plan.user_name}
        </Link>
      ),
    },
    {
      key: "period",
      header: "Период",
      width: "8rem",
      skeletonWidth: "60%",
      render: (plan) => (
        <span className="tabular-nums text-gray-600 dark:text-gray-400">
          {MONTHS_RU[plan.month - 1]} {plan.year}
        </span>
      ),
    },
    {
      key: "base_salary_amount",
      header: "Оклад",
      align: "right",
      width: "10rem",
      skeletonWidth: "50%",
      render: (plan) => (
        <span className="tabular-nums font-semibold text-gray-900 dark:text-gray-100">
          {formatCurrency(plan.base_salary_amount, plan.base_salary_currency)}
        </span>
      ),
    },
    {
      key: "commission_rule_name",
      header: "Правило комиссии",
      skeletonWidth: "60%",
      render: (plan) => (
        <span className="text-gray-500 dark:text-gray-400">
          {plan.commission_rule_name ?? "—"}
        </span>
      ),
    },
    {
      key: "status",
      header: "Статус",
      width: "9rem",
      skeletonWidth: "60%",
      render: (plan) => (
        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_BADGE[plan.status] ?? "bg-gray-100 text-gray-600"}`}>
          {STATUS_LABEL[plan.status] ?? plan.status}
        </span>
      ),
    },
  ];

  return (
    <DataTable
      columns={columns}
      rows={plans}
      getRowKey={(p) => p.id}
      skeletonRows={5}
      emptyIcon="bi-cash-coin"
      emptyTitle="Нет планов зарплат"
      rowActions={(plan) => (
        <>
          <Link
            href={`/admin/salary-plans/${plan.user_id}/${plan.year}/${plan.month}`}
            className="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-primary transition-colors"
            title="Редактировать"
            onClick={(e) => e.stopPropagation()}
          >
            <i className="bi bi-pencil text-xs" />
          </Link>
          <button
            onClick={(e) => { e.stopPropagation(); void handleCompute(plan); }}
            className="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-primary transition-colors"
            title="Пересчитать МК"
          >
            <i className="bi bi-calculator text-xs" />
          </button>
        </>
      )}
    />
  );
}
