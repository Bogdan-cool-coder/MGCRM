"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { api, ApiError } from "@/lib/api";
import { AutomationStatusToggle } from "@/components/Automations/AutomationStatusToggle";
import { Modal } from "@/components/Modal";
import type { Automation, EscalationLevel } from "@/lib/types";

interface Props {
  automation: Automation;
  onMutate: () => void;
  onDryRun: (automation: Automation) => void;
}

const ENTITY_LABELS: Record<string, string> = {
  deal: "Сделка",
  lead: "Лид",
  approval: "Согласование",
  task: "Задача",
};

const NOTIFY_LABELS: Record<string, string> = {
  owner: "Ответственный",
  manager: "Менеджер",
};

export function SlaCard({ automation, onMutate, onDryRun }: Props) {
  const router = useRouter();
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const cfg = automation.trigger_config;
  const targetType = typeof cfg.target_type === "string" ? cfg.target_type : "deal";
  const days = typeof cfg.days === "number" ? cfg.days : 0;
  const hours = typeof cfg.hours === "number" ? cfg.hours : 0;
  const chain: EscalationLevel[] = Array.isArray(cfg.escalation_chain)
    ? (cfg.escalation_chain as EscalationLevel[])
    : [];

  const thresholdLabel = hours > 0 ? `${hours} ч.` : `${days} дн.`;

  async function confirmDelete() {
    setDeleting(true);
    setDeleteError(null);
    try {
      await api(`/automations/${automation.id}`, { method: "DELETE" });
      setDeleteOpen(false);
      onMutate();
    } catch (err) {
      setDeleteError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось удалить",
      );
    } finally {
      setDeleting(false);
    }
  }

  return (
    <>
      <div className="card rounded-2xl shadow-elev-1 p-5 space-y-3 transition-shadow hover:shadow-elev-2">
        {/* Заголовок + toggle */}
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <div className="text-base font-semibold text-primary truncate">{automation.name}</div>
            <div className="text-sm text-gray-500 mt-0.5">
              {ENTITY_LABELS[targetType] ?? targetType}
              {automation.pipeline_name && ` · Воронка «${automation.pipeline_name}»`}
              {automation.stage_name && ` · Этап «${automation.stage_name}»`}
              {!automation.stage_name && automation.pipeline_name && ` · Все этапы`}
            </div>
          </div>
          <AutomationStatusToggle
            automationId={automation.id}
            isActive={automation.is_active}
            onChanged={() => onMutate()}
          />
        </div>

        {/* Порог + эскалации */}
        <div className="rounded-lg border-l-4 border-warning/50 bg-warning/5 dark:bg-warning/10 pl-3 pr-3 py-2 text-sm space-y-0.5">
          <div className="font-medium text-gray-800 dark:text-gray-200">
            Порог: <span className="tabular-nums text-warning">{thresholdLabel}</span>
          </div>
          {chain.map((lvl, i) => (
            <div key={i} className="text-xs text-gray-600 dark:text-gray-400">
              <i className="bi bi-arrow-return-right mr-1 text-gray-400" />
              Эскалация {i + 1}: через {lvl.after_days} дн. → {NOTIFY_LABELS[lvl.notify] ?? lvl.notify}
            </div>
          ))}
        </div>

        {/* Статистика */}
        <div className="flex items-center flex-wrap gap-2 text-sm text-gray-600 dark:text-gray-400">
          <span
            className={[
              "inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium",
              automation.is_active
                ? "bg-success/10 text-success dark:bg-success/20"
                : "bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400",
            ].join(" ")}
          >
            {automation.is_active ? "Активна" : "Выключена"}
          </span>
          <span className="text-xs">
            Сработала{" "}
            <span className="font-semibold tabular-nums text-gray-800 dark:text-gray-200">
              {automation.runs_count}
            </span>{" "}
            раз
          </span>
          {chain.length > 0 && (
            <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-primary/10 text-primary dark:bg-primary/20">
              {chain.length} {chain.length === 1 ? "эскалация" : "эскалации"}
            </span>
          )}
        </div>

        {/* Actions */}
        <div className="flex items-center gap-2 pt-1 flex-wrap">
          <button
            type="button"
            className="btn-ghost text-sm"
            onClick={() => onDryRun(automation)}
          >
            <i className="bi bi-play-circle mr-1" />
            Dry-run
          </button>
          <button
            type="button"
            className="btn-ghost text-sm"
            onClick={() => router.push(`/admin/sla/${automation.id}`)}
          >
            <i className="bi bi-pencil mr-1" />
            Редактировать
          </button>
          <button
            type="button"
            className="btn-ghost text-sm text-danger ml-auto"
            onClick={() => setDeleteOpen(true)}
            title="Удалить правило"
          >
            <i className="bi bi-trash" />
          </button>
        </div>
      </div>

      {/* Confirm delete modal */}
      <Modal
        open={deleteOpen}
        onClose={() => setDeleteOpen(false)}
        title="Удалить SLA-правило?"
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setDeleteOpen(false)}>
              Отмена
            </button>
            <button
              className="btn-danger"
              onClick={confirmDelete}
              disabled={deleting}
            >
              {deleting ? "Удаление…" : "Удалить"}
            </button>
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-gray-700">
            Удалить правило? Оно перестанет срабатывать. История запусков сохранится.
          </p>
          <div className="font-medium text-primary">{automation.name}</div>
          {deleteError && (
            <div className="text-sm text-danger">{deleteError}</div>
          )}
        </div>
      </Modal>
    </>
  );
}
