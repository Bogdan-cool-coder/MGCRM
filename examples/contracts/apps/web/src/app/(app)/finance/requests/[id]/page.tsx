"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR, { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RequestStatusBadge } from "@/components/Finance/RequestStatusBadge";
import { RequestFulfillModal } from "@/components/Finance/RequestFulfillModal";
import { ApprovalSummaryPanel } from "@/components/Finance/ApprovalSummaryPanel";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { useToast } from "@/components/ui/Toast";
import { api, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { FinRequest, FinApprovalSummary, FinLegalEntity, User } from "@/lib/types";

const REQUEST_TYPE_LABELS: Record<string, string> = {
  salary: "Зарплата",
  commission: "Комиссия",
  expense_reimbursement: "Возмещение расходов",
  payment: "Платёж",
};

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid grid-cols-2 gap-2 py-2.5 border-b border-gray-100 dark:border-gray-800 last:border-0">
      <span className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide self-center">{label}</span>
      <span className="text-sm text-gray-800 dark:text-gray-200">{children}</span>
    </div>
  );
}

function DetailSkeleton() {
  return (
    <div className="p-6 animate-pulse space-y-4">
      {Array.from({ length: 3 }).map((_, i) => (
        <div key={i} className="h-12 bg-gray-100 dark:bg-gray-800 rounded" />
      ))}
    </div>
  );
}

export default function RequestDetailPage({ params }: { params: { id: string } }) {
  const id = parseInt(params.id);
  const { user } = useMe();
  const { toast } = useToast();
  const [fulfillOpen, setFulfillOpen] = useState(false);
  const [actioning, setActioning] = useState<string | null>(null);

  const { data: req, isLoading: reqLoading, error: reqError } = useSWR<FinRequest>(
    `/api/finance/requests/${id}`,
    fetcher
  );
  const { data: approval, isLoading: approvalLoading } = useSWR<FinApprovalSummary>(
    `/api/finance/requests/${id}/approval`,
    fetcher
  );
  const { data: legalEntities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: users } = useSWR<User[]>("/api/users", fetcher);

  const entityName = (eid: number) => legalEntities?.find((le) => le.id === eid)?.name ?? String(eid);
  const userName = (uid: number | null) =>
    uid ? (users?.find((u) => u.id === uid)?.full_name ?? `#${uid}`) : "—";

  const isAuthor = req?.requester_user_id === user?.id;
  const canManage = user && ["accountant", "cfo", "admin"].includes(user.role);
  const canApprove = user && ["accountant", "cfo", "director", "admin"].includes(user.role);

  const activeStage = approval?.stages.find((s) => s.is_active);
  const userCanDecide =
    canApprove &&
    approval?.status === "pending" &&
    req?.requester_user_id !== user?.id &&
    activeStage?.user_ids.includes(user?.id ?? -1) &&
    !approval.votes.some(
      (v) => v.user_id === user?.id && v.stage_order === activeStage.order && v.decision !== "pending"
    );

  async function handleDecide(decision: "approved" | "rejected", comment: string) {
    await api(`/api/finance/requests/${id}/decision`, {
      method: "POST",
      body: { decision, comment: comment || null },
    });
    await mutate(`/api/finance/requests/${id}/approval`);
    await mutate(`/api/finance/requests/${id}`);
    toast.success(decision === "approved" ? "Заявка одобрена" : "Заявка отклонена");
  }

  async function handleSubmit() {
    if (!confirm("Отправить заявку на согласование?")) return;
    setActioning("submit");
    try {
      await api(`/api/finance/requests/${id}/submit`, { method: "POST" });
      await mutate(`/api/finance/requests/${id}`);
      await mutate(`/api/finance/requests/${id}/approval`);
      toast.success("Заявка отправлена на согласование");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Не удалось отправить заявку");
    } finally {
      setActioning(null);
    }
  }

  async function handleCancel() {
    if (!confirm("Отменить заявку?\nЗаявка будет отменена, отменить это действие нельзя.")) return;
    setActioning("cancel");
    try {
      await api(`/api/finance/requests/${id}/cancel`, { method: "POST" });
      await mutate(`/api/finance/requests/${id}`);
      toast.success("Заявка отменена");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Не удалось отменить заявку");
    } finally {
      setActioning(null);
    }
  }

  if (reqLoading) {
    return (
      <div className="flex flex-col h-full">
        <PageHeader title="Загрузка…" />
        <DetailSkeleton />
      </div>
    );
  }

  if (reqError || !req) {
    return (
      <div className="flex flex-col h-full">
        <PageHeader title="Заявка не найдена" />
        <div className="p-6">
          <p className="text-danger text-sm">Не удалось загрузить заявку</p>
          <Link href="/finance/requests" className="btn-ghost mt-3 inline-flex items-center gap-1">
            <i className="bi bi-arrow-left" />
            Назад к заявкам
          </Link>
        </div>
      </div>
    );
  }

  const headerTitle = req.number ? `Заявка №${req.number}` : `Заявка #${req.id}`;

  const headerActions = (
    <>
      {req.status === "draft" && isAuthor && (
        <button
          type="button"
          className="btn-primary"
          disabled={actioning === "submit"}
          onClick={handleSubmit}
        >
          <i className="bi bi-send mr-1" />
          {actioning === "submit" ? "Отправка..." : "На согласование"}
        </button>
      )}
      {req.status === "approved" && canManage && (
        <button type="button" className="btn-primary" onClick={() => setFulfillOpen(true)}>
          <i className="bi bi-check2-circle mr-1" />
          Исполнить
        </button>
      )}
      {(req.status === "draft" || req.status === "submitted") && (isAuthor || canManage) && (
        <button
          type="button"
          className="btn-secondary text-danger"
          disabled={actioning === "cancel"}
          onClick={handleCancel}
        >
          {actioning === "cancel" ? "Отмена..." : "Отменить заявку"}
        </button>
      )}
    </>
  );

  return (
    <div className="flex flex-col h-full">
      <PageHeader title={headerTitle} actions={headerActions} />

      <div className="p-6 flex-1 overflow-auto">
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
          {/* Детали */}
          <div className="card p-5">
            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-4">
              Детали заявки
            </h2>

            <DetailRow label="Тип">
              {REQUEST_TYPE_LABELS[req.request_type] ?? req.request_type}
            </DetailRow>
            <DetailRow label="Статус">
              <RequestStatusBadge status={req.status} />
            </DetailRow>
            <DetailRow label="Сумма">
              <MoneyCell amount={req.amount} currency={req.currency} direction="out" />
            </DetailRow>
            <DetailRow label="Юрлицо">{entityName(req.legal_entity_id)}</DetailRow>
            <DetailRow label="Желаемая дата">
              <span className="tabular-nums">
                {req.desired_date
                  ? new Date(req.desired_date).toLocaleDateString("ru-RU")
                  : "—"}
              </span>
            </DetailRow>
            <DetailRow label="Заявитель">{userName(req.requester_user_id)}</DetailRow>
            {req.payee_user_id && (
              <DetailRow label="Получатель">{userName(req.payee_user_id)}</DetailRow>
            )}
            {req.period_year && (
              <DetailRow label="Период">
                <span className="tabular-nums">
                  {req.period_year}
                  {req.period_month ? `-${String(req.period_month).padStart(2, "0")}` : ""}
                </span>
              </DetailRow>
            )}

            {req.description && (
              <div className="pt-3">
                <p className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-1.5">Описание</p>
                <p className="text-sm text-gray-700 dark:text-gray-200 whitespace-pre-wrap">{req.description}</p>
              </div>
            )}

            {req.rejected_reason && (
              <div className="mt-3 p-3 rounded-lg bg-danger/5 border border-danger/20">
                <p className="text-xs font-semibold text-danger uppercase tracking-wide mb-1">Причина отклонения</p>
                <p className="text-sm text-danger">{req.rejected_reason}</p>
              </div>
            )}

            {req.resulting_operation_id && req.status === "paid" && (
              <div className="mt-3 pt-3 border-t border-gray-100 dark:border-gray-800">
                <Link
                  href={`/finance/operations/${req.resulting_operation_id}`}
                  className="text-sm text-primary hover:underline inline-flex items-center gap-1"
                >
                  <i className="bi bi-arrow-up-right-square text-xs" />
                  Создана операция #{req.resulting_operation_id}
                </Link>
              </div>
            )}
          </div>

          {/* Согласование */}
          <div className="card p-5">
            <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-200 mb-4">
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
      </div>

      <RequestFulfillModal
        open={fulfillOpen}
        requestId={id}
        onClose={() => setFulfillOpen(false)}
      />
    </div>
  );
}
