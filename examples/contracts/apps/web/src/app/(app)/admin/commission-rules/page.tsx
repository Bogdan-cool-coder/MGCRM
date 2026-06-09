"use client";

import { useState } from "react";
import useSWR, { useSWRConfig } from "swr";
import { fetcher, api } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { Modal } from "@/components/Modal";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { useToast } from "@/components/ui/Toast";
import { CommissionRuleForm } from "@/components/SalaryPlans/CommissionRuleForm";
import type { CommissionRule } from "@/lib/types";

const BASE_LABELS: Record<string, string> = {
  new_income_payments: "Новые поступления",
  all_payments: "Все поступления",
};

const TIMING_LABELS: Record<string, string> = {
  immediately: "Сразу",
  end_of_month: "Конец месяца",
  end_of_quarter: "Конец квартала",
};

export default function CommissionRulesPage() {
  const { mutate: globalMutate } = useSWRConfig();
  const { data: rules, isLoading, error } = useSWR<CommissionRule[]>("/admin/commission-rules", fetcher);
  const [modalOpen, setModalOpen] = useState(false);
  const [editRule, setEditRule] = useState<CommissionRule | null>(null);
  const { toast } = useToast();

  async function handleDelete(id: number) {
    if (!confirm("Удалить правило комиссии?")) return;
    try {
      await api(`/admin/commission-rules/${id}`, { method: "DELETE" });
      globalMutate("/admin/commission-rules");
      toast.success("Правило удалено");
    } catch {
      toast.error("Не удалось удалить правило");
    }
  }

  function openCreate() { setEditRule(null); setModalOpen(true); }
  function openEdit(rule: CommissionRule) { setEditRule(rule); setModalOpen(true); }

  const columns: DataTableColumn<CommissionRule>[] = [
    {
      key: "name",
      header: "Название",
      skeletonWidth: "55%",
      render: (r) => (
        <span className="font-medium text-gray-900 dark:text-gray-100">{r.name}</span>
      ),
    },
    {
      key: "rate_pct",
      header: "Ставка",
      width: "7rem",
      align: "right",
      skeletonWidth: "40%",
      render: (r) => (
        <span className="tabular-nums font-semibold text-primary">{r.rate_pct}%</span>
      ),
    },
    {
      key: "base",
      header: "База",
      width: "12rem",
      skeletonWidth: "70%",
      render: (r) => (
        <span className="text-gray-500 dark:text-gray-400">
          {BASE_LABELS[r.base] ?? r.base}
        </span>
      ),
    },
    {
      key: "payout_timing",
      header: "Выплата",
      width: "10rem",
      skeletonWidth: "60%",
      render: (r) => (
        <span className="text-gray-500 dark:text-gray-400">
          {TIMING_LABELS[r.payout_timing] ?? r.payout_timing}
        </span>
      ),
    },
    {
      key: "flags",
      header: "",
      width: "14rem",
      skeletonWidth: "80%",
      render: (r) => (
        <div className="flex flex-wrap gap-1">
          {r.first_payment_only && (
            <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400">
              Первый платёж
            </span>
          )}
          {r.requires_signed_contract && (
            <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
              Нужен договор
            </span>
          )}
        </div>
      ),
    },
  ];

  return (
    <RoleGate allowed={["admin", "director"]}>
      <PageHeader
        title="Правила комиссии"
        actions={
          <button onClick={openCreate} className="btn-primary text-sm">
            <i className="bi bi-plus-lg mr-1" />
            Создать правило
          </button>
        }
      />

      <div className="p-6">
        <DataTable
          columns={columns}
          rows={isLoading ? undefined : (rules ?? [])}
          getRowKey={(r) => r.id}
          onRowClick={openEdit}
          isError={!!error}
          errorText="Не удалось загрузить правила"
          skeletonRows={4}
          emptyIcon="bi-percent"
          emptyTitle="Нет правил комиссии"
          emptyText="Создайте первое правило начисления комиссии"
          emptyCta={
            <button onClick={openCreate} className="btn-primary text-sm">
              <i className="bi bi-plus-lg mr-1" /> Создать правило
            </button>
          }
          rowActions={(r) => (
            <>
              <button
                onClick={(e) => { e.stopPropagation(); openEdit(r); }}
                className="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-primary transition-colors"
                title="Редактировать"
              >
                <i className="bi bi-pencil text-xs" />
              </button>
              <button
                onClick={(e) => { e.stopPropagation(); void handleDelete(r.id); }}
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
        title={editRule ? "Редактировать правило" : "Создать правило комиссии"}
        onClose={() => setModalOpen(false)}
        footer={
          <>
            <button onClick={() => setModalOpen(false)} className="btn-ghost">Отмена</button>
            <button form="commission-rule-form" type="submit" className="btn-primary">
              {editRule ? "Сохранить" : "Создать"}
            </button>
          </>
        }
      >
        <CommissionRuleForm
          editRule={editRule}
          inModal
          onSaved={() => {
            setModalOpen(false);
            globalMutate("/admin/commission-rules");
            toast.success(editRule ? "Правило обновлено" : "Правило создано");
          }}
        />
      </Modal>
    </RoleGate>
  );
}
