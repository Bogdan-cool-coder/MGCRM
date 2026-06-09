"use client";

import { useState, useMemo } from "react";
import Link from "next/link";
import useSWR, { mutate as globalMutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import {
  JournalLineEditor,
  journalLinesToPayload,
  type JournalLineDraft,
} from "@/components/Finance/JournalLineEditor";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { FinManualJournal, FinJournalStatus, FinLegalEntity, UserRole } from "@/lib/types";
import { formatDate } from "@/lib/dates";

const ALLOWED_ROLES: UserRole[] = ["accountant", "cfo", "admin"];

const STATUS_META: Record<FinJournalStatus, { label: string; classes: string }> = {
  draft:    { label: "Черновик",     classes: "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400" },
  posted:   { label: "Проведено",    classes: "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400" },
  reversed: { label: "Сторнировано", classes: "bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-400" },
};

function StatusBadge({ status }: { status: FinJournalStatus }) {
  const m = STATUS_META[status];
  return (
    <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium ${m.classes}`}>
      <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
      {m.label}
    </span>
  );
}

function extractErrMsg(err: unknown): string {
  if (err instanceof ApiError) {
    const d = err.detail;
    if (typeof d === "object" && d !== null && "detail" in d) return String((d as Record<string, unknown>)["detail"]);
    if (typeof d === "string") return d;
  }
  return "Ошибка операции";
}

export default function JournalDetailPage({ params }: { params: { id: string } }) {
  const { id } = params;
  const { user } = useMe();
  const { toast } = useToast();

  const swrKey = `/api/finance/journals/${id}`;
  const { data: journal, isLoading, error } = useSWR<FinManualJournal>(swrKey, fetcher);
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const [editLines, setEditLines] = useState<JournalLineDraft[] | null>(null);
  const [editMemo, setEditMemo] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [posting, setPosting] = useState(false);
  const [reversing, setReversing] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const canAct = user && (ALLOWED_ROLES as UserRole[]).includes(user.role);
  const isEditable = journal?.status === "draft";

  const funcCurrency = useMemo(
    () => entities?.find((e) => e.id === journal?.legal_entity_id)?.functional_currency ?? "RUB",
    [entities, journal?.legal_entity_id]
  );

  function startEdit() {
    if (!journal) return;
    setEditLines(
      journal.lines.map((l) => ({
        _key: Math.random().toString(36).slice(2),
        account_gl_id: l.account_gl_id,
        side: l.side,
        amount: String(l.amount),
        currency: l.currency,
        counterparty_company_id: l.counterparty_company_id,
        comment: l.comment ?? "",
      }))
    );
    setEditMemo(journal.memo);
  }

  function cancelEdit() {
    setEditLines(null);
    setEditMemo(null);
    setActionError(null);
  }

  const currentLines = editLines;
  const dtSum = (currentLines ?? []).filter((l) => l.side === "dt").reduce((a, l) => a + (parseFloat(l.amount) || 0), 0);
  const ktSum = (currentLines ?? []).filter((l) => l.side === "kt").reduce((a, l) => a + (parseFloat(l.amount) || 0), 0);
  const balanced = Math.abs(dtSum - ktSum) < 0.01 && dtSum > 0;

  async function handleSave() {
    if (!editLines) return;
    setSaving(true);
    setActionError(null);
    try {
      await api(`/finance/journals/${id}`, {
        method: "PATCH",
        body: {
          memo: editMemo?.trim(),
          lines: journalLinesToPayload(editLines),
        },
      });
      await globalMutate(swrKey);
      setEditLines(null);
      setEditMemo(null);
      toast.success("Журнал сохранён");
    } catch (err) {
      const msg = extractErrMsg(err);
      setActionError(msg);
      toast.error("Не удалось сохранить", msg);
    } finally {
      setSaving(false);
    }
  }

  async function handlePost() {
    setPosting(true);
    setActionError(null);
    try {
      await api(`/finance/journals/${id}/post`, { method: "POST" });
      await globalMutate(swrKey);
      toast.success("Журнал проведён");
    } catch (err) {
      const msg = extractErrMsg(err);
      setActionError(msg);
      toast.error("Не удалось провести", msg);
    } finally {
      setPosting(false);
    }
  }

  async function handleReverse() {
    if (!confirm("Создать сторно-проводку?")) return;
    setReversing(true);
    setActionError(null);
    try {
      await api(`/finance/journals/${id}/reverse`, { method: "POST", body: {} });
      await globalMutate(swrKey);
      toast.success("Сторно-проводка создана");
    } catch (err) {
      const msg = extractErrMsg(err);
      setActionError(msg);
      toast.error("Не удалось сторнировать", msg);
    } finally {
      setReversing(false);
    }
  }

  if (isLoading) {
    return (
      <div className="p-6 animate-pulse space-y-4">
        <div className="h-7 bg-gray-100 dark:bg-gray-800 rounded w-64" />
        <div className="h-36 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
        <div className="h-48 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
      </div>
    );
  }

  if (error || !journal) {
    return (
      <div className="p-6">
        <p className="text-sm text-danger">Не удалось загрузить журнал</p>
        <Link href="/finance/journals" className="btn-ghost mt-3 inline-block">← Журналы</Link>
      </div>
    );
  }

  const readOnlyLines: JournalLineDraft[] = journal.lines.map((l) => ({
    _key: String(l.id ?? Math.random()),
    account_gl_id: l.account_gl_id,
    side: l.side,
    amount: String(l.amount),
    currency: l.currency,
    counterparty_company_id: l.counterparty_company_id,
    comment: l.comment ?? "",
  }));

  return (
    <RoleGate
      allowed={ALLOWED_ROLES}
      fallback={
        <div className="p-8 text-center">
          <p className="text-sm text-gray-500 dark:text-gray-400">Нет доступа</p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title={journal.number ? `Журнал ${journal.number}` : "Ручная проводка"}
          actions={
            <Link href="/finance/journals" className="btn-ghost">
              <i className="bi bi-arrow-left mr-1" />
              Журналы
            </Link>
          }
        />

        <div className="p-6 flex flex-col gap-5 max-w-4xl">
          {/* Заголовок карточки v2 */}
          <div className="card rounded-2xl shadow-elev-1 p-5">
            <div className="flex items-start justify-between gap-4 mb-4">
              <div className="flex flex-col gap-1.5">
                <div className="flex items-center gap-2.5">
                  <StatusBadge status={journal.status} />
                  {journal.number && (
                    <span className="text-xs font-mono text-gray-400 dark:text-gray-500">{journal.number}</span>
                  )}
                </div>
                <p className="text-xs text-gray-400 dark:text-gray-500">
                  {entities?.find((e) => e.id === journal.legal_entity_id)?.name ?? `Юрлицо #${journal.legal_entity_id}`}
                </p>
              </div>
              {isEditable && canAct && !editLines && (
                <button className="btn-secondary text-sm" onClick={startEdit}>
                  <i className="bi bi-pencil mr-1" />
                  Редактировать
                </button>
              )}
            </div>

            <div className="grid grid-cols-2 gap-4 text-sm mb-4">
              <div>
                <span className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Дата</span>
                <p className="font-medium text-gray-800 dark:text-gray-200 mt-0.5">{formatDate(journal.date)}</p>
              </div>
              {journal.journal_entry_id && (
                <div>
                  <span className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">Проводка</span>
                  <p className="font-mono text-gray-600 dark:text-gray-400 mt-0.5">#{journal.journal_entry_id}</p>
                </div>
              )}
            </div>

            {editMemo !== null ? (
              <div>
                <label className="label">Обоснование</label>
                <textarea
                  className="input min-h-[72px] resize-y"
                  value={editMemo}
                  onChange={(e) => setEditMemo(e.target.value)}
                />
              </div>
            ) : (
              <div className="bg-gray-50 dark:bg-gray-800/60 rounded-xl p-3">
                <p className="text-sm text-gray-700 dark:text-gray-300">
                  {journal.memo || <span className="italic text-gray-400">Нет обоснования</span>}
                </p>
              </div>
            )}
          </div>

          {/* Строки проводки v2 */}
          <div className="card rounded-2xl shadow-elev-1 p-5">
            <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
              Строки проводки
            </h2>
            {editLines !== null ? (
              <JournalLineEditor
                lines={editLines}
                onChange={setEditLines}
                funcCurrency={funcCurrency}
              />
            ) : (
              <JournalLineEditor
                lines={readOnlyLines}
                onChange={() => { /* no-op в режиме просмотра */ }}
                funcCurrency={funcCurrency}
                readOnly
              />
            )}
          </div>

          {/* Ошибка */}
          {actionError && (
            <p className="text-sm text-danger">{actionError}</p>
          )}

          {/* Кнопки действий */}
          <div className="flex items-center gap-2 flex-wrap">
            {editLines !== null ? (
              <>
                <button className="btn-ghost" onClick={cancelEdit} disabled={saving}>Отмена</button>
                <button
                  className="btn-primary"
                  disabled={saving || !balanced}
                  onClick={handleSave}
                >
                  {saving ? "Сохранение..." : "Сохранить"}
                </button>
              </>
            ) : (
              <>
                {journal.status === "draft" && canAct && (
                  <button
                    className="btn-primary"
                    disabled={posting}
                    onClick={handlePost}
                  >
                    <i className="bi bi-check-circle mr-1" />
                    {posting ? "Проведение..." : "Провести"}
                  </button>
                )}
                {journal.status === "posted" && canAct && (
                  <button
                    className="btn-secondary"
                    disabled={reversing}
                    onClick={handleReverse}
                  >
                    <i className="bi bi-arrow-counterclockwise mr-1" />
                    {reversing ? "Сторнирование..." : "Сторнировать"}
                  </button>
                )}
              </>
            )}
          </div>
        </div>
      </div>
    </RoleGate>
  );
}
