"use client";

import Link from "next/link";
import useSWR, { mutate } from "swr";
import { useState } from "react";
import { PageHeader } from "@/components/PageHeader";
import { RegistryApprovalBadge, RegistryPaymentBadge } from "@/components/Finance/RegistryStatusBadge";
import { RegistryItemsPanel } from "@/components/Finance/RegistryItemsPanel";
import { ApprovalSummaryPanel } from "@/components/Finance/ApprovalSummaryPanel";
import { EmptyState } from "@/components/EmptyState";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { RoleGate } from "@/components/RoleGate";
import { useToast } from "@/components/ui/Toast";
import { api, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { FinRegistryDetail, FinApprovalSummary, FinMoneyAccount, FinLegalEntity } from "@/lib/types";
import { formatDate } from "@/lib/dates";
import { formatAmount } from "@/lib/format";

interface DetailRowProps {
  label: string;
  children: React.ReactNode;
}

function DetailRow({ label, children }: DetailRowProps) {
  return (
    <div className="grid grid-cols-2 gap-2 py-2.5 border-b border-gray-100 dark:border-gray-800 last:border-0">
      <span className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider self-center">{label}</span>
      <span className="text-sm font-medium text-gray-800 dark:text-gray-200">{children}</span>
    </div>
  );
}

export default function RegistryDetailPage({ params }: { params: { id: string } }) {
  const id = parseInt(params.id);
  const { user } = useMe();
  const { toast } = useToast();
  const [actioning, setActioning] = useState<string | null>(null);

  const { data: reg, isLoading, error } = useSWR<FinRegistryDetail>(
    `/api/finance/registries/${id}`,
    fetcher
  );
  const { data: approval, isLoading: approvalLoading } = useSWR<FinApprovalSummary>(
    `/api/finance/registries/${id}/approval`,
    fetcher
  );
  const { data: accounts } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);
  const { data: legalEntities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const accountName = (aid: number) => accounts?.find((a) => a.id === aid)?.name ?? `#${aid}`;
  const entityName = (eid: number) => legalEntities?.find((le) => le.id === eid)?.name ?? `#${eid}`;

  const canManage = user && ["accountant", "cfo", "admin"].includes(user.role);
  const canApprove = user && ["accountant", "cfo", "director", "admin"].includes(user.role);

  const activeStage = approval?.stages.find((s) => s.is_active);
  const userCanDecide =
    canApprove &&
    approval?.status === "pending" &&
    reg?.created_by_user_id !== user?.id &&
    activeStage?.user_ids.includes(user?.id ?? -1) &&
    !approval.votes.some(
      (v) => v.user_id === user?.id && v.stage_order === activeStage.order && v.decision !== "pending"
    );

  async function handleDecide(decision: "approved" | "rejected", comment: string) {
    await api(`/api/finance/registries/${id}/decision`, {
      method: "POST",
      body: { decision, comment: comment || null },
    });
    await mutate(`/api/finance/registries/${id}/approval`);
    await mutate(`/api/finance/registries/${id}`);
    toast.success(decision === "approved" ? "Реестр согласован" : "Реестр отклонён");
  }

  async function handleSubmit() {
    if (!reg) return;
    if (!confirm("Подать реестр на согласование? После подачи состав нельзя изменить.")) return;
    setActioning("submit");
    try {
      await api(`/api/finance/registries/${id}/submit`, { method: "POST" });
      await mutate(`/api/finance/registries/${id}`);
      await mutate(`/api/finance/registries/${id}/approval`);
      toast.success("Реестр отправлен на согласование");
    } catch {
      toast.error("Не удалось отправить реестр");
    } finally {
      setActioning(null);
    }
  }

  async function handleProvision() {
    if (!confirm("Провести все позиции реестра? Отменить это действие нельзя.")) return;
    setActioning("provision");
    try {
      await api(`/api/finance/registries/${id}/provision`, { method: "POST" });
      await mutate(`/api/finance/registries/${id}`);
      toast.success("Все позиции проведены");
    } catch {
      toast.error("Не удалось провести позиции");
    } finally {
      setActioning(null);
    }
  }

  if (isLoading) {
    return (
      <RoleGate allowed={["accountant", "cfo", "admin"]}>
        <div className="p-6 animate-pulse space-y-4">
          <div className="h-7 bg-gray-100 dark:bg-gray-800 rounded w-48" />
          <div className="grid grid-cols-2 gap-5">
            <div className="h-52 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
            <div className="h-52 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
          </div>
          <div className="h-64 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
        </div>
      </RoleGate>
    );
  }

  if (error || !reg) {
    return (
      <RoleGate allowed={["accountant", "cfo", "admin"]}>
        <div className="p-6">
          <EmptyState
            icon="bi-exclamation-triangle"
            title="Реестр не найден"
            description="Реестр не существует или у вас нет доступа"
            cta={
              <Link href="/finance/registries" className="btn-ghost">
                ← Назад к реестрам
              </Link>
            }
          />
        </div>
      </RoleGate>
    );
  }

  const headerTitle = reg.number ? `Реестр №${reg.number}` : `Реестр #${reg.id}`;
  const itemCount = reg.items?.length ?? 0;

  const headerActions = (
    <>
      {reg.approval_status === "draft" && itemCount > 0 && canManage && (
        <button
          type="button"
          className="btn-primary"
          disabled={actioning === "submit"}
          onClick={handleSubmit}
        >
          <i className="bi bi-send mr-1" />
          {actioning === "submit" ? "Подача..." : "Подать на согласование"}
        </button>
      )}
      {reg.approval_status === "approved" &&
        reg.payment_status !== "paid" &&
        canManage && (
          <button
            type="button"
            className="btn-primary"
            disabled={actioning === "provision"}
            onClick={handleProvision}
          >
            <i className="bi bi-check2-all mr-1" />
            {actioning === "provision" ? "Проведение..." : "Провести всё"}
          </button>
        )}
    </>
  );

  return (
    <RoleGate allowed={["accountant", "cfo", "admin"]} fallback={
      <div className="p-8 text-gray-400 dark:text-gray-500">Нет доступа к реестрам</div>
    }>
      <div className="flex flex-col h-full">
        {/* Hero v2 */}
        <div className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-8 py-5">
          <div className="flex items-center gap-2 mb-2">
            <Link
              href="/finance/registries"
              className="text-sm text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-blue-400 flex items-center gap-1 transition-colors"
            >
              <i className="bi bi-arrow-left" /> Реестры
            </Link>
          </div>
          <div className="flex items-start justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-xl bg-primary/10 dark:bg-primary/20 grid place-items-center shrink-0">
                <i className="bi bi-list-check text-lg text-primary dark:text-blue-400" />
              </div>
              <div>
                <h1 className="text-h3 dark:text-gray-100">{headerTitle}</h1>
                <div className="flex items-center gap-2 mt-1">
                  <RegistryApprovalBadge status={reg.approval_status} />
                  <RegistryPaymentBadge status={reg.payment_status} />
                </div>
              </div>
            </div>
            <div className="flex items-center gap-2 shrink-0">
              {headerActions}
            </div>
          </div>
        </div>

        <div className="p-6 space-y-5">
          {/* Top row: details + approval */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
            {/* Details */}
            <div className="card rounded-2xl shadow-elev-1 p-5">
              <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
                Детали реестра
              </h2>
              <DetailRow label="Дата">
                {formatDate(reg.registry_date)}
              </DetailRow>
              <DetailRow label="Счёт">{accountName(reg.source_account_id)}</DetailRow>
              <DetailRow label="Юрлицо">{entityName(reg.legal_entity_id)}</DetailRow>
              {reg.title && <DetailRow label="Название">{reg.title}</DetailRow>}
              {reg.total_amount != null && (
                <DetailRow label="Итого">
                  <span className="tabular-nums font-semibold text-gray-900 dark:text-gray-100">
                    {formatAmount(reg.total_amount)}
                  </span>
                </DetailRow>
              )}
              {reg.comment && (
                <div className="pt-3 mt-1 border-t border-gray-100 dark:border-gray-800">
                  <p className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Комментарий</p>
                  <p className="text-sm text-gray-700 dark:text-gray-300">{reg.comment}</p>
                </div>
              )}
            </div>

            {/* Approval */}
            <div className="card rounded-2xl shadow-elev-1 p-5">
              <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-3">
                Согласование
              </h2>
              <ApprovalSummaryPanel
                summary={approval}
                isLoading={approvalLoading}
                onDecide={userCanDecide ? handleDecide : undefined}
                canDecide={userCanDecide ?? false}
              />
            </div>
          </div>

          {/* Items panel */}
          <RegistryItemsPanel registryId={id} registry={reg} />
        </div>
      </div>
    </RoleGate>
  );
}
