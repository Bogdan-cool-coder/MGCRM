"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import { TARGET_TYPE_LABELS, TARGET_TYPE_OPTIONS } from "@/lib/automationConfig";
import type {
  Automation,
  AutomationTargetType,
  AutomationTestPreview,
  AutomationTestResult,
  AutomationExecuteResponse,
} from "@/lib/types";

interface Props {
  open: boolean;
  onClose: () => void;
  automation: Automation;
}

type DryRunState =
  | "idle"
  | "analyzing"
  | "previewing"
  | "confirm_execute"
  | "executing"
  | "done"
  | "error";

/**
 * Dry-run 2.0 modal. State machine:
 * idle → analyzing → previewing → confirm_execute → executing → done/error
 *
 * Для idle_in_stage_days — backend сам пробегает все подходящие записи,
 * поля «Тип цели» / «ID цели» скрыты.
 */
export function TestRunModal({ open, onClose, automation }: Props) {
  const needsTarget = automation.trigger_kind === "on_enter_stage";
  const isSlaKind = automation.trigger_kind === "idle_in_stage_days";

  const [targetType, setTargetType] = useState<AutomationTargetType>(() => {
    if (needsTarget) return "deal";
    const fromCfg = (automation.trigger_config as Record<string, unknown>).target_type;
    if (
      typeof fromCfg === "string" &&
      (TARGET_TYPE_OPTIONS as ReadonlyArray<{ value: string }>).some((o) => o.value === fromCfg)
    ) {
      return fromCfg as AutomationTargetType;
    }
    return "deal";
  });
  const [targetId, setTargetId] = useState<string>("");

  const [dryState, setDryState] = useState<DryRunState>("idle");
  const [result, setResult] = useState<AutomationTestResult | null>(null);
  const [executeResult, setExecuteResult] = useState<AutomationExecuteResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  const matchCount = result?.previews.filter((p) => p.would_execute !== false).length ?? 0;

  async function runAnalysis() {
    setDryState("analyzing");
    setError(null);
    setResult(null);
    setExecuteResult(null);
    try {
      const body: Record<string, unknown> = {};
      if (targetId.trim()) {
        body.target_type = targetType;
        body.target_id = Number(targetId);
      } else if (needsTarget) {
        setError(
          `Для триггера «При входе на этап» нужно указать ID цели (${TARGET_TYPE_LABELS[targetType].toLowerCase()})`,
        );
        setDryState("idle");
        return;
      }
      const res = await api<AutomationTestResult>(`/automations/${automation.id}/test`, {
        method: "POST",
        body,
      });
      setResult(res);
      setDryState("previewing");
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось выполнить анализ",
      );
      setDryState("error");
    }
  }

  async function runExecute() {
    setDryState("executing");
    setError(null);
    try {
      const res = await api<AutomationExecuteResponse>(`/automations/${automation.id}/execute`, {
        method: "POST",
        body: {},
      });
      setExecuteResult(res);
      setDryState("done");
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось выполнить",
      );
      setDryState("error");
    }
  }

  function reset() {
    setDryState("idle");
    setResult(null);
    setExecuteResult(null);
    setError(null);
    setTargetId("");
  }

  function close() {
    reset();
    onClose();
  }

  const analyzing = dryState === "analyzing";
  const executing = dryState === "executing";
  const busy = analyzing || executing;

  return (
    <Modal
      open={open}
      onClose={close}
      title="Тестовый прогон автоматизации"
      description={automation.name}
      width="xl"
      footer={
        <div className="flex items-center justify-between w-full gap-2">
          <button className="btn-ghost" onClick={close} disabled={busy}>
            Закрыть
          </button>
          <div className="flex items-center gap-2">
            {(dryState === "previewing" || dryState === "error") && (
              <button className="btn-secondary" onClick={reset} disabled={busy}>
                <i className="bi bi-arrow-counterclockwise mr-1" />
                Повторить анализ
              </button>
            )}
            {dryState === "idle" && (
              <button className="btn-primary" onClick={runAnalysis}>
                <i className="bi bi-play-fill mr-1" />
                Запустить анализ
              </button>
            )}
            {dryState === "previewing" && matchCount > 0 && (
              <button
                className="btn-danger"
                onClick={() => setDryState("confirm_execute")}
              >
                <i className="bi bi-lightning-fill mr-1" />
                Выполнить сейчас
              </button>
            )}
          </div>
        </div>
      }
    >
      <div className="space-y-4">
        {/* Info banner */}
        <div className="text-sm text-gray-700 dark:text-gray-300 bg-info/10 border border-info/30 rounded-md p-3">
          <i className="bi bi-info-circle mr-1" />
          Dry-run: записи проверяются, действия <strong>НЕ выполняются</strong>.
        </div>

        {/* Target inputs — только если нужны */}
        {!isSlaKind && (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label className="label">Тип цели</label>
              <select
                className="input"
                value={targetType}
                onChange={(e) => setTargetType(e.target.value as AutomationTargetType)}
                disabled={busy}
              >
                {TARGET_TYPE_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="label">
                ID цели {needsTarget && <span className="text-danger">*</span>}
              </label>
              <input
                className="input"
                type="number"
                min={1}
                value={targetId}
                onChange={(e) => setTargetId(e.target.value)}
                placeholder={needsTarget ? "обязательно" : "пусто = первые 5 подходящих"}
                disabled={busy}
              />
              <div className="text-xs text-gray-500 mt-1">
                {needsTarget
                  ? "Для on_enter_stage обязателен явный target."
                  : "Для cron-триггеров — оставьте пусто, чтобы взять первые 5 целей из БД."}
              </div>
            </div>
          </div>
        )}

        {/* Analyzing spinner */}
        {analyzing && (
          <div className="flex items-center gap-3 py-4 text-sm text-gray-600">
            <i className="bi bi-arrow-repeat animate-spin text-lg" />
            Анализируем подходящие записи…
          </div>
        )}

        {/* Executing spinner */}
        {executing && (
          <div className="flex items-center gap-3 py-4 text-sm text-gray-600">
            <i className="bi bi-arrow-repeat animate-spin text-lg" />
            Выполняем…
          </div>
        )}

        {/* Done */}
        {dryState === "done" && executeResult && (
          <div className="bg-success/10 border border-success/40 rounded-md p-4 space-y-1">
            <div className="font-semibold text-success">
              <i className="bi bi-check-circle mr-1" />
              Выполнено. Затронуто {executeResult.executed} записей.
            </div>
            {executeResult.skipped > 0 && (
              <div className="text-sm text-gray-600">Пропущено: {executeResult.skipped}</div>
            )}
            {executeResult.errors.length > 0 && (
              <div className="text-sm text-danger mt-1">
                Ошибки: {executeResult.errors.join(", ")}
              </div>
            )}
          </div>
        )}

        {/* Error */}
        {dryState === "error" && error && (
          <div className="text-sm text-danger bg-danger/10 border border-danger/30 px-3 py-2 rounded-md">
            <i className="bi bi-exclamation-triangle mr-1" />
            {error}
          </div>
        )}

        {/* Preview */}
        {result && dryState !== "error" && dryState !== "done" && (
          <div className="space-y-3">
            {/* Confirm banner */}
            {dryState === "confirm_execute" && (
              <div className="bg-warning/10 border border-warning/40 rounded-md p-3 space-y-3">
                <div className="text-sm font-medium text-gray-800 dark:text-gray-200">
                  <i className="bi bi-exclamation-triangle mr-1 text-warning" />
                  Это реальное выполнение. Уведомления уйдут получателям. Будет затронуто{" "}
                  <strong>{matchCount}</strong> записей. Продолжить?
                </div>
                <div className="flex gap-2">
                  <button
                    className="btn-ghost text-sm"
                    onClick={() => setDryState("previewing")}
                  >
                    Отмена выполнения
                  </button>
                  <button className="btn-danger text-sm" onClick={runExecute}>
                    Да, выполнить
                  </button>
                </div>
              </div>
            )}

            {/* Счётчик */}
            <div className="text-lg font-semibold text-primary">
              Подходит записей: {matchCount}
            </div>

            {matchCount === 0 && (
              <div className="text-sm text-gray-500 italic">
                Нет записей, которые попадают под это правило
              </div>
            )}

            {result.previews.map((p, i) => (
              <PreviewCard key={i} preview={p} />
            ))}
          </div>
        )}
      </div>
    </Modal>
  );
}

function PreviewCard({ preview }: { preview: AutomationTestPreview }) {
  const wouldExecute = preview.would_execute !== false;
  const idleDays =
    typeof preview.idle_days === "number"
      ? preview.idle_days
      : typeof (preview as Record<string, unknown>).idle_days === "number"
      ? (preview as Record<string, unknown>).idle_days as number
      : null;

  return (
    <div
      className={`border rounded-md p-3 text-sm ${
        wouldExecute ? "border-success/50 bg-success/10" : "border-gray-200 bg-gray-50 dark:bg-gray-700"
      }`}
    >
      <div className="flex items-center justify-between mb-2">
        <div className="font-medium">
          {wouldExecute ? (
            <span className="text-green-700 dark:text-green-400">
              <i className="bi bi-check-circle mr-1" />
              Выполнилось бы
            </span>
          ) : (
            <span className="text-gray-600 dark:text-gray-400">
              <i className="bi bi-dash-circle mr-1" />
              Пропущено
            </span>
          )}
        </div>
        {preview.target_type && preview.target_id && (
          <span className="text-xs text-gray-600 dark:text-gray-400">
            {preview.target_type} #{preview.target_id}
          </span>
        )}
      </div>

      {idleDays !== null && (
        <div className="text-xs text-gray-700 dark:text-gray-300 mb-1">
          Бездействие: {idleDays} дней
        </div>
      )}

      {preview.reason && (
        <div className="text-xs text-gray-700 dark:text-gray-300 mb-2">{preview.reason}</div>
      )}
      {preview.message && (
        <div className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded p-2 text-xs whitespace-pre-wrap mb-2">
          <div className="text-gray-500 text-[10px] uppercase mb-1">сообщение:</div>
          {preview.message}
        </div>
      )}
      {preview.recipient && (
        <div className="text-xs text-gray-700 dark:text-gray-300 mb-1">
          Получатель: <code>{preview.recipient.kind}</code> ={" "}
          <code>{String(preview.recipient.value ?? "—")}</code>
        </div>
      )}
      {preview.task && (
        <div className="text-xs text-gray-700 dark:text-gray-300 space-y-0.5">
          <div>
            Задача: <strong>{preview.task.title}</strong>
          </div>
          {preview.task.body && <div className="text-gray-500">{preview.task.body}</div>}
          {preview.task.responsible_id != null && (
            <div>Ответственный (user_id): {preview.task.responsible_id}</div>
          )}
          {preview.task.due_in_days != null && (
            <div>Срок через дней: {preview.task.due_in_days}</div>
          )}
        </div>
      )}
      {preview.set_field && (
        <div className="text-xs text-gray-700 dark:text-gray-300">
          Поле <code>{preview.set_field.field}</code>:{" "}
          <span className="text-gray-500">{preview.set_field.old_value ?? "—"}</span> →{" "}
          <span className="font-semibold">{String(preview.set_field.new_value ?? "—")}</span>
        </div>
      )}
      {preview.generate_document && (
        <div className="text-xs text-gray-700 dark:text-gray-300">
          Шаблон: <code>{preview.generate_document.template_code ?? "—"}</code>
          {preview.generate_document.stub && (
            <span className="ml-2 text-warning">⚠ stub (note вместо файла)</span>
          )}
        </div>
      )}
    </div>
  );
}
