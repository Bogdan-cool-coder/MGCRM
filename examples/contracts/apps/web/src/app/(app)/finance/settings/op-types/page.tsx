"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { Modal } from "@/components/Modal";
import { useToast } from "@/components/ui/Toast";
import { fetcher, api } from "@/lib/api";
import type { FinOpType } from "@/lib/types";

/**
 * /finance/settings/op-types — Типы финансовых операций.
 * Гейтинг: cfo/admin (capability manage_categories).
 */

const SETTINGS_ROLES = ["cfo", "admin"] as const;

const DIRECTION_OPTIONS = [
  { value: "in", label: "Приход (in)" },
  { value: "out", label: "Расход (out)" },
  { value: "transfer", label: "Перевод (transfer)" },
  { value: "none", label: "Без направления (none)" },
];

const POSTING_TEMPLATE_OPTIONS = [
  { value: "cash_in", label: "cash_in — поступление" },
  { value: "cash_out", label: "cash_out — расход" },
  { value: "transfer", label: "transfer — перевод" },
  { value: "opening", label: "opening — ввод остатка" },
  { value: "manual_journal", label: "manual_journal — ручная проводка" },
  { value: "reversal", label: "reversal — сторно" },
];

const DIRECTION_LABELS: Record<string, string> = {
  in: "Приход",
  out: "Расход",
  transfer: "Перевод",
  none: "—",
};

const DIRECTION_BADGE: Record<string, string> = {
  in: "bg-success/10 text-success",
  out: "bg-danger/10 text-danger",
  transfer: "bg-info/10 text-info",
  none: "bg-gray-100 text-gray-500 dark:bg-gray-700/60 dark:text-gray-400",
};

interface OpTypeForm {
  code: string;
  name: string;
  direction: string;
  posting_template: string;
  counts_in_pnl: boolean;
  counts_in_cashflow: boolean;
  is_internal_transfer: boolean;
  sort_order: string;
}

const EMPTY_FORM: OpTypeForm = {
  code: "",
  name: "",
  direction: "in",
  posting_template: "cash_in",
  counts_in_pnl: true,
  counts_in_cashflow: true,
  is_internal_transfer: false,
  sort_order: "0",
};

function TableSkeleton() {
  return (
    <>
      {Array.from({ length: 4 }).map((_, i) => (
        <tr key={i} className="border-b border-gray-100 dark:border-gray-800 animate-pulse">
          {[24, 40, 20, 28, 12, 12, 16, 16].map((w, j) => (
            <td key={j} className="px-3 py-2.5">
              <div className="h-4 bg-gray-100 dark:bg-gray-800 rounded" style={{ width: `${w * 4}px` }} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}

export default function OpTypesPage() {
  const { toast } = useToast();
  const [showArchived, setShowArchived] = useState(false);
  const apiKey = `/api/finance/op-types?include_archived=${showArchived}`;
  const { data: opTypes, isLoading, error } = useSWR<FinOpType[]>(apiKey, fetcher);

  const [createOpen, setCreateOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<FinOpType | null>(null);
  const [form, setForm] = useState<OpTypeForm>(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  function setF<K extends keyof OpTypeForm>(k: K, v: OpTypeForm[K]) {
    setForm((prev) => ({ ...prev, [k]: v }));
  }

  function openCreate() {
    setForm(EMPTY_FORM);
    setFormError(null);
    setCreateOpen(true);
  }

  function openEdit(ot: FinOpType) {
    setForm({
      code: ot.code,
      name: ot.name,
      direction: ot.direction,
      posting_template: ot.posting_template,
      counts_in_pnl: ot.counts_in_pnl,
      counts_in_cashflow: ot.counts_in_cashflow,
      is_internal_transfer: ot.is_internal_transfer,
      sort_order: String(ot.sort_order),
    });
    setFormError(null);
    setEditTarget(ot);
  }

  function validate(): string | null {
    if (!form.code.trim()) return "Укажите код";
    if (!form.name.trim()) return "Укажите название";
    if (!form.direction) return "Выберите направление";
    if (!form.posting_template) return "Выберите шаблон проводки";
    return null;
  }

  async function handleCreate() {
    const err = validate();
    if (err) { setFormError(err); return; }
    setSubmitting(true);
    setFormError(null);
    try {
      await api("/finance/op-types", {
        method: "POST",
        body: {
          code: form.code.trim(),
          name: form.name.trim(),
          direction: form.direction,
          posting_template: form.posting_template,
          counts_in_pnl: form.counts_in_pnl,
          counts_in_cashflow: form.counts_in_cashflow,
          is_internal_transfer: form.is_internal_transfer,
          sort_order: Number(form.sort_order) || 0,
        },
      });
      await mutate(apiKey);
      setCreateOpen(false);
      toast.success("Тип операции создан");
    } catch (e: unknown) {
      setFormError(e instanceof Error ? e.message : "Ошибка создания");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleEdit() {
    if (!editTarget) return;
    const errMsg = form.name.trim() ? null : "Укажите название";
    if (errMsg) { setFormError(errMsg); return; }
    setSubmitting(true);
    setFormError(null);
    try {
      await api(`/finance/op-types/${editTarget.id}`, {
        method: "PATCH",
        body: {
          name: form.name.trim(),
          counts_in_pnl: form.counts_in_pnl,
          counts_in_cashflow: form.counts_in_cashflow,
          is_internal_transfer: form.is_internal_transfer,
          sort_order: Number(form.sort_order) || 0,
        },
      });
      await mutate(apiKey);
      setEditTarget(null);
      toast.success("Тип операции обновлён");
    } catch (e: unknown) {
      setFormError(e instanceof Error ? e.message : "Ошибка обновления");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleArchive(ot: FinOpType) {
    const label = ot.is_archived ? "Восстановить" : "Архивировать";
    if (!confirm(`${label} тип операции «${ot.name}»?`)) return;
    try {
      await api(`/finance/op-types/${ot.id}`, {
        method: "PATCH",
        body: { is_archived: !ot.is_archived },
      });
      await mutate(apiKey);
      toast.success(ot.is_archived ? `«${ot.name}» восстановлен` : `«${ot.name}» архивирован`);
    } catch (e: unknown) {
      toast.error(e instanceof Error ? e.message : `Не удалось ${ot.is_archived ? "восстановить" : "архивировать"} «${ot.name}»`);
    }
  }

  return (
    <RoleGate
      allowed={[...SETTINGS_ROLES]}
      fallback={
        <div className="p-8 text-center text-danger">
          <i className="bi bi-lock text-3xl mb-3 block" />
          <p>Недостаточно прав. Требуется роль CFO или Администратор.</p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Типы операций"
          description="Справочник типов финансовых операций (шаблоны проводок)"
          actions={
            <div className="flex items-center gap-3">
              <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={showArchived}
                  onChange={(e) => setShowArchived(e.target.checked)}
                />
                Показать архивные
              </label>
              <button className="btn-primary text-sm" onClick={openCreate}>
                <i className="bi bi-plus mr-1" />
                Добавить тип
              </button>
            </div>
          }
        />

        <div className="p-6">
          {error && (
            <div className="card p-4 text-danger text-sm mb-4">
              <i className="bi bi-exclamation-triangle mr-2" />
              Не удалось загрузить типы операций
            </div>
          )}

          <div className="card overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-900/30 border-b border-gray-200 dark:border-gray-700">
                <tr>
                  <th className="text-left px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-32">Код</th>
                  <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Название</th>
                  <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-24">Направление</th>
                  <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-36">Шаблон</th>
                  <th className="text-center px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-16">P&L</th>
                  <th className="text-center px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-16">ДДС</th>
                  <th className="text-center px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-20">Перевод</th>
                  <th className="text-right px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-28">Действия</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {isLoading && <TableSkeleton />}
                {!isLoading && opTypes && opTypes.length === 0 && (
                  <tr>
                    <td colSpan={8} className="px-5 py-10 text-center text-gray-400 dark:text-gray-500 text-sm italic">
                      Нет типов операций
                    </td>
                  </tr>
                )}
                {!isLoading && opTypes?.map((ot) => (
                  <tr
                    key={ot.id}
                    className={[
                      "hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors",
                      ot.is_archived ? "opacity-50" : "",
                    ].join(" ")}
                  >
                    <td className="px-5 py-2.5 font-mono text-xs text-gray-600 dark:text-gray-400">
                      {ot.code}
                      {ot.is_archived && (
                        <span className="ml-1.5 badge bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500 text-[10px] px-1">
                          архив
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-gray-800 dark:text-gray-200 font-medium">
                      {ot.name}
                    </td>
                    <td className="px-3 py-2.5">
                      <span className={`badge text-xs px-2 py-0.5 rounded-full ${DIRECTION_BADGE[ot.direction] ?? ""}`}>
                        {DIRECTION_LABELS[ot.direction] ?? ot.direction}
                      </span>
                    </td>
                    <td className="px-3 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                      {ot.posting_template}
                    </td>
                    <td className="px-3 py-2.5 text-center">
                      {ot.counts_in_pnl ? (
                        <i className="bi bi-check-circle-fill text-success" />
                      ) : (
                        <i className="bi bi-dash text-gray-300 dark:text-gray-600" />
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-center">
                      {ot.counts_in_cashflow ? (
                        <i className="bi bi-check-circle-fill text-success" />
                      ) : (
                        <i className="bi bi-dash text-gray-300 dark:text-gray-600" />
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-center">
                      {ot.is_internal_transfer ? (
                        <i className="bi bi-arrow-left-right text-info" />
                      ) : (
                        <i className="bi bi-dash text-gray-300 dark:text-gray-600" />
                      )}
                    </td>
                    <td className="px-5 py-2.5 text-right">
                      <div className="flex items-center justify-end gap-1">
                        <button
                          className="btn-ghost text-xs px-2 py-1"
                          onClick={() => openEdit(ot)}
                          title="Редактировать"
                        >
                          <i className="bi bi-pencil" />
                        </button>
                        <button
                          className={`btn-ghost text-xs px-2 py-1 ${ot.is_archived ? "text-success" : "text-warning"}`}
                          onClick={() => handleArchive(ot)}
                          title={ot.is_archived ? "Восстановить" : "Архивировать"}
                        >
                          <i className={`bi ${ot.is_archived ? "bi-arrow-counterclockwise" : "bi-archive"}`} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* Модалка создания */}
      <Modal
        open={createOpen}
        title="Новый тип операции"
        onClose={() => setCreateOpen(false)}
        width="md"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setCreateOpen(false)} disabled={submitting}>Отмена</button>
            <button className="btn-primary" onClick={handleCreate} disabled={submitting}>
              {submitting ? "Создание…" : "Создать"}
            </button>
          </>
        }
      >
        <OpTypeFormFields form={form} setF={setF} mode="create" error={formError} />
      </Modal>

      {/* Модалка редактирования */}
      <Modal
        open={!!editTarget}
        title={editTarget ? `Редактировать: ${editTarget.name}` : ""}
        onClose={() => setEditTarget(null)}
        width="md"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setEditTarget(null)} disabled={submitting}>Отмена</button>
            <button className="btn-primary" onClick={handleEdit} disabled={submitting}>
              {submitting ? "Сохранение…" : "Сохранить"}
            </button>
          </>
        }
      >
        <OpTypeFormFields form={form} setF={setF} mode="edit" error={formError} />
      </Modal>
    </RoleGate>
  );
}

// ── Форма полей ──────────────────────────────────────────────────────────────

interface FormFieldsProps {
  form: OpTypeForm;
  setF: <K extends keyof OpTypeForm>(k: K, v: OpTypeForm[K]) => void;
  mode: "create" | "edit";
  error: string | null;
}

function OpTypeFormFields({ form, setF, mode, error }: FormFieldsProps) {
  return (
    <div className="space-y-4">
      {error && (
        <div className="text-danger text-sm bg-danger/10 rounded px-3 py-2">
          <i className="bi bi-exclamation-triangle mr-2" />{error}
        </div>
      )}

      {mode === "create" && (
        <div>
          <label className="label">Код <span className="text-danger">*</span></label>
          <input
            className="input w-full font-mono"
            placeholder="income_generic"
            value={form.code}
            onChange={(e) => setF("code", e.target.value)}
            maxLength={32}
          />
          <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">Уникальный идентификатор (латиница, snake_case)</p>
        </div>
      )}

      <div>
        <label className="label">Название <span className="text-danger">*</span></label>
        <input
          className="input w-full"
          placeholder="Поступление денег"
          value={form.name}
          onChange={(e) => setF("name", e.target.value)}
          maxLength={128}
        />
      </div>

      {mode === "create" && (
        <>
          <div>
            <label className="label">Направление <span className="text-danger">*</span></label>
            <select className="input w-full" value={form.direction} onChange={(e) => setF("direction", e.target.value)}>
              {DIRECTION_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>

          <div>
            <label className="label">Шаблон проводки <span className="text-danger">*</span></label>
            <select className="input w-full" value={form.posting_template} onChange={(e) => setF("posting_template", e.target.value)}>
              {POSTING_TEMPLATE_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>
        </>
      )}

      {mode === "edit" && (
        <div className="p-3 bg-gray-50 dark:bg-gray-900/30 rounded text-xs text-gray-500 dark:text-gray-400">
          <i className="bi bi-info-circle mr-1" />
          Код, направление и шаблон проводки нельзя изменить после создания.
        </div>
      )}

      <div className="grid grid-cols-3 gap-4">
        <label className="flex items-start gap-2 cursor-pointer">
          <input
            type="checkbox"
            className="mt-0.5"
            checked={form.counts_in_pnl}
            onChange={(e) => setF("counts_in_pnl", e.target.checked)}
          />
          <div>
            <div className="text-sm font-medium text-gray-700 dark:text-gray-300">P&L</div>
            <div className="text-xs text-gray-400 dark:text-gray-500">Учитывать в P&L</div>
          </div>
        </label>

        <label className="flex items-start gap-2 cursor-pointer">
          <input
            type="checkbox"
            className="mt-0.5"
            checked={form.counts_in_cashflow}
            onChange={(e) => setF("counts_in_cashflow", e.target.checked)}
          />
          <div>
            <div className="text-sm font-medium text-gray-700 dark:text-gray-300">ДДС</div>
            <div className="text-xs text-gray-400 dark:text-gray-500">Учитывать в ДДС</div>
          </div>
        </label>

        <label className="flex items-start gap-2 cursor-pointer">
          <input
            type="checkbox"
            className="mt-0.5"
            checked={form.is_internal_transfer}
            onChange={(e) => setF("is_internal_transfer", e.target.checked)}
          />
          <div>
            <div className="text-sm font-medium text-gray-700 dark:text-gray-300">Перевод</div>
            <div className="text-xs text-gray-400 dark:text-gray-500">Внутренний перевод</div>
          </div>
        </label>
      </div>

      <div>
        <label className="label">Порядок сортировки</label>
        <input
          type="number"
          className="input w-24"
          value={form.sort_order}
          onChange={(e) => setF("sort_order", e.target.value)}
          min={0}
        />
      </div>
    </div>
  );
}
