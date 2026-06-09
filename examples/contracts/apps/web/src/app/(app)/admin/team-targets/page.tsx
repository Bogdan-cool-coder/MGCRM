"use client";

import { useState } from "react";
import useSWR, { useSWRConfig } from "swr";
import { fetcher, api } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { Modal } from "@/components/Modal";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { useToast } from "@/components/ui/Toast";
import { TeamTargetForm } from "@/components/SalaryPlans/TeamTargetForm";
import { formatCurrency } from "@/lib/format";
import type { TeamTarget } from "@/lib/types";

const MONTHS_RU = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

const METRIC_LABELS: Record<string, string> = {
  new_income: "Новые поступления",
  ftm: "FTM встречи",
};

export default function TeamTargetsPage() {
  const { mutate: globalMutate } = useSWRConfig();
  const { data: targets, isLoading, error } = useSWR<TeamTarget[]>("/admin/team-targets", fetcher);
  const [modalOpen, setModalOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<TeamTarget | null>(null);
  const { toast } = useToast();

  async function handleDelete(id: number) {
    if (!confirm("Удалить командную цель?")) return;
    try {
      await api(`/admin/team-targets/${id}`, { method: "DELETE" });
      globalMutate("/admin/team-targets");
      toast.success("Цель удалена");
    } catch {
      toast.error("Не удалось удалить цель");
    }
  }

  function openCreate() { setEditTarget(null); setModalOpen(true); }
  function openEdit(t: TeamTarget) { setEditTarget(t); setModalOpen(true); }

  const columns: DataTableColumn<TeamTarget>[] = [
    {
      key: "period",
      header: "Период",
      width: "10rem",
      skeletonWidth: "70%",
      render: (t) => (
        <span className="font-medium text-gray-900 dark:text-gray-100 tabular-nums">
          {MONTHS_RU[t.month - 1]} {t.year}
        </span>
      ),
    },
    {
      key: "metric",
      header: "Метрика",
      width: "11rem",
      skeletonWidth: "60%",
      render: (t) => (
        <span className="text-gray-500 dark:text-gray-400">
          {METRIC_LABELS[t.metric] ?? t.metric}
        </span>
      ),
    },
    {
      key: "target_amount",
      header: "Цель",
      align: "right",
      width: "10rem",
      skeletonWidth: "50%",
      render: (t) => (
        <span className="tabular-nums font-semibold text-gray-900 dark:text-gray-100">
          {formatCurrency(t.target_amount, t.target_currency)}
        </span>
      ),
    },
    {
      key: "bonus_pool_amount",
      header: "Пул бонуса",
      align: "right",
      width: "10rem",
      skeletonWidth: "50%",
      render: (t) => (
        <span className="tabular-nums text-gray-600 dark:text-gray-300">
          {formatCurrency(t.bonus_pool_amount, t.bonus_pool_currency)}
        </span>
      ),
    },
    {
      key: "thresholds",
      header: "Пороги",
      width: "10rem",
      skeletonWidth: "60%",
      render: (t) => (
        <span className="text-xs text-gray-500 dark:text-gray-400">
          мин {t.min_threshold_pct}% · {t.proportional_pct}/{t.equal_pct}
        </span>
      ),
    },
  ];

  return (
    <RoleGate allowed={["admin", "director"]}>
      <PageHeader
        title="Командные цели"
        actions={
          <button onClick={openCreate} className="btn-primary text-sm">
            <i className="bi bi-plus-lg mr-1" />
            Создать цель
          </button>
        }
      />

      <div className="p-6">
        <DataTable
          columns={columns}
          rows={isLoading ? undefined : (targets ?? [])}
          getRowKey={(t) => t.id}
          onRowClick={openEdit}
          isError={!!error}
          errorText="Не удалось загрузить цели"
          skeletonRows={4}
          emptyIcon="bi-bullseye"
          emptyTitle="Нет командных целей"
          emptyText="Создайте первую цель для расчёта командного бонуса"
          emptyCta={
            <button onClick={openCreate} className="btn-primary text-sm">
              <i className="bi bi-plus-lg mr-1" /> Создать цель
            </button>
          }
          rowActions={(t) => (
            <>
              <button
                onClick={(e) => { e.stopPropagation(); openEdit(t); }}
                className="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-primary transition-colors"
                title="Редактировать"
              >
                <i className="bi bi-pencil text-xs" />
              </button>
              <button
                onClick={(e) => { e.stopPropagation(); void handleDelete(t.id); }}
                className="p-1.5 rounded hover:bg-danger/10 text-gray-400 hover:text-danger transition-colors"
                title="Удалить"
              >
                <i className="bi bi-trash text-xs" />
              </button>
            </>
          )}
        />
      </div>

      <Modal
        open={modalOpen}
        title={editTarget ? "Редактировать цель" : "Создать командную цель"}
        onClose={() => setModalOpen(false)}
        footer={
          <>
            <button onClick={() => setModalOpen(false)} className="btn-ghost">Отмена</button>
            <button form="team-target-form" type="submit" className="btn-primary">
              {editTarget ? "Сохранить" : "Создать"}
            </button>
          </>
        }
      >
        <TeamTargetForm
          editTarget={editTarget}
          inModal
          onSaved={() => {
            setModalOpen(false);
            globalMutate("/admin/team-targets");
            toast.success(editTarget ? "Цель обновлена" : "Цель создана");
          }}
        />
      </Modal>
    </RoleGate>
  );
}
