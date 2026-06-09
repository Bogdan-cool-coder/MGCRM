"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { FinApprovalSummary, User } from "@/lib/types";

interface Props {
  summary: FinApprovalSummary | undefined;
  isLoading: boolean;
  onDecide?: (decision: "approved" | "rejected", comment: string) => Promise<void>;
  canDecide?: boolean;
}

function useUsers() {
  const { data } = useSWR<User[]>("/api/users", fetcher);
  return data ?? [];
}

function userName(users: User[], id: number): string {
  return users.find((u) => u.id === id)?.full_name ?? `Пользователь #${id}`;
}

function ApprovalStatusBadge({ status }: { status: string }) {
  if (status === "approved") {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400">
        Согласовано
      </span>
    );
  }
  if (status === "rejected") {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400">
        Отклонено
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-50 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400">
      На согласовании
    </span>
  );
}

export function ApprovalSummaryPanel({ summary, isLoading, onDecide, canDecide }: Props) {
  const [comment, setComment] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const users = useUsers();

  if (isLoading) {
    return (
      <div className="animate-pulse space-y-3">
        {[1, 2, 3].map((i) => (
          <div key={i} className="h-8 bg-gray-100 dark:bg-gray-700 rounded" />
        ))}
      </div>
    );
  }

  if (!summary || summary.total_stages === 0) {
    return (
      <p className="text-sm text-gray-400 dark:text-gray-500 italic">
        Согласование ещё не запущено
      </p>
    );
  }

  if (summary.status === "approved") {
    return (
      <div className="bg-green-50 dark:bg-green-900/20 p-3 rounded flex items-center gap-2">
        <i className="bi bi-check-circle-fill text-success text-lg" />
        <span className="text-sm font-medium text-success">Согласовано</span>
      </div>
    );
  }

  if (summary.status === "rejected") {
    const lastRejected = summary.votes.find((v) => v.decision === "rejected");
    return (
      <div className="bg-red-50 dark:bg-red-900/20 p-3 rounded">
        <div className="flex items-center gap-2 mb-1">
          <i className="bi bi-x-circle-fill text-danger text-lg" />
          <span className="text-sm font-medium text-danger">Отклонено</span>
        </div>
        {lastRejected?.comment && (
          <p className="text-xs text-gray-600 dark:text-gray-400 ml-6">{lastRejected.comment}</p>
        )}
      </div>
    );
  }

  async function handleDecide(decision: "approved" | "rejected") {
    if (!onDecide) return;
    setSubmitting(true);
    try {
      await onDecide(decision, comment);
      setComment("");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <ApprovalStatusBadge status={summary.status} />
        </div>
        <span className="text-xs text-gray-500 dark:text-gray-400">
          Этап {summary.active_stage + 1} из {summary.total_stages}
        </span>
      </div>

      {/* Stages */}
      <div className="space-y-3">
        {summary.stages.map((stage) => {
          const modeLabel =
            stage.mode === "all"
              ? "Нужны все"
              : `Достаточно ${stage.min_required} из ${stage.user_ids.length}`;

          return (
            <div
              key={stage.order}
              className={`border rounded-lg p-3 ${stage.is_active ? "border-primary" : "border-gray-200 dark:border-gray-700"}`}
            >
              <div className="flex items-center justify-between mb-2">
                <span className="text-sm font-medium dark:text-gray-100">{stage.name}</span>
                <span className="text-xs text-gray-500 dark:text-gray-400">{modeLabel}</span>
              </div>

              <div className="flex flex-wrap gap-1.5 mb-2">
                {stage.user_ids.map((uid) => {
                  const vote = summary.votes.find(
                    (v) => v.user_id === uid && v.stage_order === stage.order
                  );
                  const decision = vote?.decision ?? "pending";
                  return (
                    <span
                      key={uid}
                      className={`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full ${
                        decision === "approved"
                          ? "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400"
                          : decision === "rejected"
                          ? "bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400"
                          : "bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400"
                      }`}
                    >
                      {decision === "approved" && <i className="bi bi-check" />}
                      {decision === "rejected" && <i className="bi bi-x" />}
                      {decision === "pending" && <i className="bi bi-clock" />}
                      {userName(users, uid)}
                    </span>
                  );
                })}
              </div>

              {!stage.is_active && (
                <p className="text-xs text-gray-400 dark:text-gray-500 italic">
                  Ожидает завершения предыдущего этапа
                </p>
              )}

              <p className="text-xs text-gray-500 dark:text-gray-400">
                ✓ {stage.approved} одобрили · ✗ {stage.rejected} отклонили · ⏳ {stage.pending} ожидают
              </p>

              {/* Vote comments for this stage */}
              {summary.votes
                .filter((v) => v.stage_order === stage.order && v.comment)
                .map((v) => (
                  <div
                    key={v.id}
                    className="mt-2 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-700 pt-2"
                  >
                    <span className="font-medium">{userName(users, v.user_id)}</span>
                    {v.decided_at && (
                      <span className="ml-1">
                        {new Date(v.decided_at).toLocaleDateString("ru-RU")}
                      </span>
                    )}
                    {": "}
                    {v.comment}
                  </div>
                ))}
            </div>
          );
        })}
      </div>

      {/* Voting form */}
      {canDecide && summary.status === "pending" && (
        <div className="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-3">
          <textarea
            className="input w-full text-sm"
            rows={2}
            placeholder="Комментарий к решению (необязательно)"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
          <div className="flex gap-2 justify-end">
            <button
              type="button"
              disabled={submitting}
              onClick={() => handleDecide("rejected")}
              className="btn-secondary text-danger text-sm"
            >
              {submitting ? "..." : "Отклонить"}
            </button>
            <button
              type="button"
              disabled={submitting}
              onClick={() => handleDecide("approved")}
              className="btn-primary text-sm"
            >
              {submitting ? "..." : "Одобрить"}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
