"use client";

import { useState, useEffect, useCallback } from "react";
import Link from "next/link";
import useSWR, { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { EmptyState } from "@/components/EmptyState";
import { useToast } from "@/components/ui/Toast";
import { api, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { FinRequest, FinRegistry, FinApprovalSummary } from "@/lib/types";

// TODO: backend endpoint GET /api/finance/approvals/pending-for-me
// Currently building inbox via sequential fetches + client-side filtering.
// At low pending counts (<20) this is fine. Optimize via backend endpoint later.

interface PendingItem {
  kind: "request" | "registry";
  id: number;
  obj: FinRequest | FinRegistry;
  summary: FinApprovalSummary;
  activeStage: FinApprovalSummary["stages"][number] | undefined;
}

/** Fetches submitted requests + on_review registries, then loads approval summary
 *  for each. Filters to items where myId is an active approver without a vote. */
function useApprovalInbox(myId: number | undefined): {
  items: PendingItem[];
  loading: boolean;
} {
  const [items, setItems] = useState<PendingItem[]>([]);
  const [loading, setLoading] = useState(false);

  const { data: submittedRequests } = useSWR<FinRequest[]>(
    "/api/finance/requests?status=submitted",
    fetcher,
    { refreshInterval: 30_000 }
  );
  const { data: onReviewRegistries } = useSWR<FinRegistry[]>(
    "/api/finance/registries?approval_status=on_review",
    fetcher,
    { refreshInterval: 30_000 }
  );

  const rebuild = useCallback(async () => {
    if (myId === undefined) return;
    if (!submittedRequests && !onReviewRegistries) return;

    setLoading(true);
    const result: PendingItem[] = [];

    for (const req of submittedRequests ?? []) {
      try {
        const summary = await fetcher<FinApprovalSummary>(
          `/api/finance/requests/${req.id}/approval`
        );
        const activeStage = summary.stages.find((s) => s.is_active);
        if (!activeStage?.user_ids.includes(myId)) continue;
        const alreadyVoted = summary.votes.some(
          (v) =>
            v.user_id === myId &&
            v.stage_order === activeStage.order &&
            v.decision !== "pending"
        );
        if (alreadyVoted) continue;
        result.push({ kind: "request", id: req.id, obj: req, summary, activeStage });
      } catch {
        // skip inaccessible
      }
    }

    for (const reg of onReviewRegistries ?? []) {
      try {
        const summary = await fetcher<FinApprovalSummary>(
          `/api/finance/registries/${reg.id}/approval`
        );
        const activeStage = summary.stages.find((s) => s.is_active);
        if (!activeStage?.user_ids.includes(myId)) continue;
        const alreadyVoted = summary.votes.some(
          (v) =>
            v.user_id === myId &&
            v.stage_order === activeStage.order &&
            v.decision !== "pending"
        );
        if (alreadyVoted) continue;
        result.push({ kind: "registry", id: reg.id, obj: reg, summary, activeStage });
      } catch {
        // skip inaccessible
      }
    }

    setItems(result);
    setLoading(false);
  }, [myId, submittedRequests, onReviewRegistries]);

  useEffect(() => {
    void rebuild();
  }, [rebuild]);

  return { items, loading };
}

const REQUEST_TYPE_LABELS: Record<string, string> = {
  salary: "Зарплата",
  commission: "Комиссия",
  expense_reimbursement: "Возмещение расходов",
  payment: "Платёж",
};

function ApprovalInboxCard({ item, onDecided }: { item: PendingItem; onDecided: () => void }) {
  const { toast } = useToast();
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function decide(decision: "approved" | "rejected") {
    setSubmitting(true);
    const base =
      item.kind === "request"
        ? `/api/finance/requests/${item.id}/decision`
        : `/api/finance/registries/${item.id}/decision`;
    try {
      await api(base, {
        method: "POST",
        body: { decision, comment: comment || null },
      });
      toast.success(
        decision === "approved" ? "Решение принято: одобрено" : "Решение принято: отклонено"
      );
      if (item.kind === "request") {
        await mutate(`/api/finance/requests/${item.id}/approval`);
        await mutate(
          (k: unknown) =>
            typeof k === "string" &&
            k.includes("/api/finance/requests?status=submitted"),
          undefined,
          { revalidate: true }
        );
      } else {
        await mutate(`/api/finance/registries/${item.id}/approval`);
        await mutate(
          (k: unknown) =>
            typeof k === "string" &&
            k.includes("/api/finance/registries?approval_status=on_review"),
          undefined,
          { revalidate: true }
        );
      }
      onDecided();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Не удалось сохранить решение");
    } finally {
      setSubmitting(false);
    }
  }

  const isRequest = item.kind === "request";
  const req = isRequest ? (item.obj as FinRequest) : null;

  return (
    <div className="card p-5 space-y-4 transition-shadow hover:shadow-elev-2">
      {/* Header */}
      <div className="flex items-start gap-3">
        <div className={[
          "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-base",
          isRequest
            ? "bg-primary/10 text-primary dark:bg-primary/20"
            : "bg-info/10 text-info dark:bg-info/20",
        ].join(" ")}>
          <i className={`bi ${isRequest ? "bi-file-earmark-text" : "bi-list-check"}`} />
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold text-gray-800 dark:text-gray-100">
            {isRequest ? "Заявка" : "Реестр"}
            {req && (
              <span className="ml-2 font-normal text-gray-500 dark:text-gray-400">
                {REQUEST_TYPE_LABELS[req.request_type] ?? req.request_type}
              </span>
            )}
          </p>
          {item.activeStage && (
            <p className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
              Этап: <span className="font-medium">{item.activeStage.name}</span>
            </p>
          )}
        </div>
        {req && (
          <MoneyCell amount={req.amount} currency={req.currency} direction="out" />
        )}
      </div>

      {/* Comment */}
      <textarea
        className="input text-sm w-full resize-none"
        rows={2}
        placeholder="Комментарий к решению (необязательно)…"
        value={comment}
        onChange={(e) => setComment(e.target.value)}
        disabled={submitting}
      />

      {/* Actions */}
      <div className="flex items-center justify-between">
        <Link
          href={isRequest ? `/finance/requests/${item.id}` : `/finance/registries/${item.id}`}
          className="btn-ghost text-sm text-primary"
        >
          <i className="bi bi-box-arrow-up-right mr-1 text-xs" />
          Открыть
        </Link>
        <div className="flex gap-2">
          <button
            type="button"
            className="btn-secondary text-danger text-sm"
            disabled={submitting}
            onClick={() => decide("rejected")}
          >
            <i className="bi bi-x-circle mr-1" />
            Отклонить
          </button>
          <button
            type="button"
            className="btn-primary text-sm"
            disabled={submitting}
            onClick={() => decide("approved")}
          >
            <i className="bi bi-check2 mr-1" />
            Одобрить
          </button>
        </div>
      </div>
    </div>
  );
}

function CardSkeleton() {
  return (
    <div className="card p-5 space-y-4 animate-pulse">
      <div className="flex items-start gap-3">
        <div className="h-9 w-9 rounded-lg bg-gray-100 dark:bg-gray-800 shrink-0" />
        <div className="flex-1 space-y-2">
          <div className="h-4 w-32 bg-gray-100 dark:bg-gray-800 rounded" />
          <div className="h-3 w-24 bg-gray-100 dark:bg-gray-800 rounded" />
        </div>
        <div className="h-4 w-20 bg-gray-100 dark:bg-gray-800 rounded" />
      </div>
      <div className="h-10 bg-gray-100 dark:bg-gray-800 rounded" />
      <div className="flex justify-between">
        <div className="h-7 w-20 bg-gray-100 dark:bg-gray-800 rounded" />
        <div className="flex gap-2">
          <div className="h-7 w-20 bg-gray-100 dark:bg-gray-800 rounded" />
          <div className="h-7 w-20 bg-gray-100 dark:bg-gray-800 rounded" />
        </div>
      </div>
    </div>
  );
}

type TabKind = "all" | "requests" | "registries";

export default function ApprovalsPage() {
  const { user } = useMe();
  const [tab, setTab] = useState<TabKind>("all");
  const [decidedCount, setDecidedCount] = useState(0);

  const { items, loading } = useApprovalInbox(user?.id);

  const requestItems = items.filter((i) => i.kind === "request");
  const registryItems = items.filter((i) => i.kind === "registry");

  const displayed =
    tab === "all" ? items : tab === "requests" ? requestItems : registryItems;

  const tabClass = (t: TabKind) =>
    `px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
      tab === t
        ? "border-primary text-primary"
        : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
    }`;

  function handleDecided() {
    setDecidedCount((c) => c + 1);
  }

  return (
    <div className="flex flex-col h-full">
      <PageHeader title="Согласования" />

      <div className="p-6 flex-1 overflow-auto space-y-4">
        {/* Вкладки */}
        <div className="flex border-b border-gray-200 dark:border-gray-700">
          <button className={tabClass("all")} onClick={() => setTab("all")}>
            Все
            {items.length > 0 && (
              <span className="ml-1.5 inline-flex items-center justify-center h-4 min-w-[1rem] px-1 rounded-full text-[10px] font-bold bg-primary text-white">
                {items.length}
              </span>
            )}
          </button>
          <button className={tabClass("requests")} onClick={() => setTab("requests")}>
            Заявки{requestItems.length > 0 && ` (${requestItems.length})`}
          </button>
          <button className={tabClass("registries")} onClick={() => setTab("registries")}>
            Реестры{registryItems.length > 0 && ` (${registryItems.length})`}
          </button>
        </div>

        {/* Skeleton */}
        {loading && (
          <div className="space-y-4">
            <CardSkeleton />
            <CardSkeleton />
          </div>
        )}

        {/* Empty */}
        {!loading && displayed.length === 0 && (
          <EmptyState
            icon="bi-check2-all"
            title="Нет объектов на согласовании"
            description="Здесь появятся заявки и реестры, ожидающие вашего решения"
            className="py-20"
          />
        )}

        {/* Cards */}
        {!loading && displayed.length > 0 && (
          <div className="space-y-4" key={decidedCount}>
            {displayed.map((item) => (
              <ApprovalInboxCard
                key={`${item.kind}-${item.id}`}
                item={item}
                onDecided={handleDecided}
              />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
