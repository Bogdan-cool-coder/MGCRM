"use client";

import { useState } from "react";
import clsx from "clsx";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { Modal } from "./Modal";
import { TextareaField } from "./TextareaField";
import type { ContractApprovalSummary, User } from "@/lib/types";

export function ApprovalPanel({
  contractId, contractStatus, isAuthor, onChanged,
}: {
  contractId: number;
  contractStatus: string;
  isAuthor: boolean;
  onChanged: () => void;
}) {
  const { user: me } = useMe();
  const { data: summary, mutate } = useSWR<ContractApprovalSummary>(
    `/contracts/${contractId}/approval-summary`,
    fetcher,
  );
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const [rejectModal, setRejectModal] = useState<{ open: boolean; comment: string }>({ open: false, comment: "" });
  const [reworkModal, setReworkModal] = useState<{ open: boolean; comment: string }>({ open: false, comment: "" });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!summary) {
    return <div className="card p-5 text-gray-500 text-sm">Загрузка статуса согласования…</div>;
  }

  const userName = (id: number) => users?.find(u => u.id === id)?.full_name ?? `User #${id}`;

  // Approvals последнего attempt сгруппированные по user_id
  const lastAttempt = summary.approvals.length ? Math.max(...summary.approvals.map(a => a.attempt)) : 1;
  const currentApprovals = summary.approvals.filter(a => a.attempt === lastAttempt);
  const myApproval = me ? currentApprovals.find(a => a.user_id === me.id && a.decision === "pending") : null;
  // Бэкенд различает два исхода: окончательное отклонение (decision === "rejected",
  // статус договора "rejected") и возврат на доработку (decision === "needs_rework",
  // статус договора "needs_rework"). Их замечания и маркеры разные, поэтому ищем
  // отдельно. Resubmit разрешён из обоих статусов (backend can_submit_from).
  const lastRejection = currentApprovals.find(a => a.decision === "rejected");
  const lastRework = currentApprovals.find(a => a.decision === "needs_rework");

  async function approve() {
    setBusy(true); setError(null);
    try {
      await api(`/contracts/${contractId}/decide`, {
        method: "POST", body: { decision: "approved", comment: null },
      });
      await mutate();
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка");
    } finally { setBusy(false); }
  }

  async function reject() {
    if (!rejectModal.comment.trim()) { setError("Укажите причину отклонения"); return; }
    setBusy(true); setError(null);
    try {
      await api(`/contracts/${contractId}/decide`, {
        method: "POST", body: { decision: "rejected", comment: rejectModal.comment.trim() },
      });
      setRejectModal({ open: false, comment: "" });
      await mutate();
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка");
    } finally { setBusy(false); }
  }

  async function returnForRework() {
    if (!reworkModal.comment.trim()) { setError("Укажите, что нужно исправить"); return; }
    setBusy(true); setError(null);
    try {
      await api(`/contracts/${contractId}/return-for-rework`, {
        method: "POST", body: { comment: reworkModal.comment.trim() },
      });
      setReworkModal({ open: false, comment: "" });
      await mutate();
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка");
    } finally { setBusy(false); }
  }

  async function resubmit() {
    setBusy(true); setError(null);
    try {
      await api(`/contracts/${contractId}/resubmit`, { method: "POST" });
      await mutate();
      onChanged();
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка");
    } finally { setBusy(false); }
  }

  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-h5">Согласование</h3>
        {summary.total_stages > 0 && (
          <span className="text-xs text-gray-600">
            Этап {Math.min(summary.current_stage + 1, summary.total_stages)} из {summary.total_stages}
          </span>
        )}
      </div>

      {error && <div className="text-danger text-sm bg-danger/10 px-3 py-2 rounded mb-3">{error}</div>}

      {lastRejection && contractStatus === "rejected" && isAuthor && (
        <div className="bg-danger/10 border border-danger/30 rounded p-3 mb-3 space-y-2">
          <div className="text-sm font-medium text-gray-900">
            ❌ Договор отклонён ({userName(lastRejection.user_id)})
          </div>
          <div className="text-sm text-gray-800 whitespace-pre-wrap">
            <strong>Причина:</strong> {lastRejection.comment || "(не указана)"}
          </div>
          <button onClick={resubmit} disabled={busy} className="btn-primary">
            <i className="bi bi-arrow-clockwise" /> Я исправил, отправить повторно
          </button>
        </div>
      )}

      {contractStatus === "needs_rework" && isAuthor && (
        <div className="bg-warning/10 border border-warning/40 rounded p-3 mb-3 space-y-2">
          <div className="text-sm font-medium text-gray-900">
            <i className="bi bi-arrow-counterclockwise mr-1" />
            Возвращён на доработку{lastRework && ` (${userName(lastRework.user_id)})`}
          </div>
          <div className="text-sm text-gray-800 whitespace-pre-wrap">
            <strong>Что поправить:</strong> {lastRework?.comment || "(не указано)"}
          </div>
          <button onClick={resubmit} disabled={busy} className="btn-primary">
            <i className="bi bi-arrow-clockwise" /> Я исправил, отправить на согласование
          </button>
        </div>
      )}

      {summary.stages.length === 0 && (
        <div className="text-sm text-gray-500">Маршрут согласования не настроен.</div>
      )}

      <div className="space-y-3">
        {summary.stages.map((stage, idx) => (
          <div key={stage.order} className={clsx(
            "border rounded-md p-3",
            stage.is_active ? "border-warning bg-warning/10" : "border-gray-200 bg-gray-50",
          )}>
            <div className="flex items-center justify-between mb-2">
              <div className="font-medium text-sm">
                {stage.is_active && "▶ "}Этап {idx + 1}: {stage.name}
              </div>
              <div className="text-xs text-gray-600">
                {stage.approved} / {stage.min_required} согласовали
                {stage.rejected > 0 && ` • ${stage.rejected} отклонили`}
              </div>
            </div>
            <ul className="space-y-1">
              {stage.user_ids.map(uid => {
                const ap = currentApprovals.find(a => a.user_id === uid && a.stage_order === stage.order);
                const isRework = ap?.decision === "needs_rework";
                const statusIcon =
                  ap?.decision === "approved" ? <span>✅</span> :
                  ap?.decision === "rejected" ? <span>❌</span> :
                  isRework ? <i className="bi bi-arrow-counterclockwise text-warning" title="На доработку" /> :
                  stage.is_active ? <span>⌛</span> : <span>·</span>;
                return (
                  <li key={uid} className="text-sm flex items-start gap-2">
                    <span className="w-5 text-center">{statusIcon}</span>
                    <span className="flex-1">
                      <span className={clsx(
                        ap?.decision === "rejected" && "text-danger font-medium",
                        isRework && "text-warning font-medium",
                      )}>
                        {userName(uid)}
                      </span>
                      {isRework && <span className="ml-1 text-xs text-warning">(на доработку)</span>}
                      {ap?.comment && (
                        <span className="block text-xs text-gray-600 italic ml-0 mt-0.5">«{ap.comment}»</span>
                      )}
                    </span>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </div>

      {myApproval && contractStatus === "in_review" && (
        <div className="mt-4 pt-4 border-t border-gray-200 space-y-2">
          <div className="flex gap-2">
            <button onClick={approve} disabled={busy} className="btn-primary flex-1 justify-center">
              <i className="bi bi-check-lg" /> Согласовать
            </button>
            <button onClick={() => setRejectModal({ open: true, comment: "" })} disabled={busy} className="btn-secondary flex-1 justify-center border-danger text-danger">
              <i className="bi bi-x-lg" /> Отклонить
            </button>
          </div>
          <button
            onClick={() => setReworkModal({ open: true, comment: "" })}
            disabled={busy}
            className="btn-secondary w-full justify-center border-warning text-warning"
          >
            <i className="bi bi-arrow-counterclockwise" /> Вернуть на доработку
          </button>
        </div>
      )}

      <Modal
        open={rejectModal.open}
        onClose={() => setRejectModal({ open: false, comment: "" })}
        isDirty={!!rejectModal.comment.trim()}
        title="Отклонить договор"
        description="Укажите причину — автор увидит её в карточке и сможет исправить."
        width="md"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setRejectModal({ open: false, comment: "" })}>Отмена</button>
            <button onClick={reject} disabled={busy || !rejectModal.comment.trim()} className="btn-danger">
              <i className="bi bi-x-lg" /> Отклонить
            </button>
          </>
        }
      >
        <TextareaField
          label="Причина отклонения"
          required
          value={rejectModal.comment}
          onChange={(v) => setRejectModal({ open: true, comment: v })}
          rows={5}
          placeholder="Например: в п. 4 неверная сумма НДС, поправьте на 12% и пришлите повторно."
        />
      </Modal>

      <Modal
        open={reworkModal.open}
        onClose={() => setReworkModal({ open: false, comment: "" })}
        isDirty={!!reworkModal.comment.trim()}
        title="Вернуть на доработку"
        description="Договор вернётся автору. Он внесёт правки и отправит на согласование повторно."
        width="md"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setReworkModal({ open: false, comment: "" })}>Отмена</button>
            <button
              onClick={returnForRework}
              disabled={busy || !reworkModal.comment.trim()}
              className="btn-secondary border-warning text-warning"
            >
              <i className="bi bi-arrow-counterclockwise" /> Вернуть на доработку
            </button>
          </>
        }
      >
        <TextareaField
          label="Что нужно исправить"
          required
          value={reworkModal.comment}
          onChange={(v) => setReworkModal({ open: true, comment: v })}
          rows={5}
          placeholder="Например: уточните реквизиты лицензиара в разделе 1 и пришлите повторно."
        />
      </Modal>
    </div>
  );
}
