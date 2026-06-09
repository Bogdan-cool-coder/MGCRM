"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { SlaEscalationChain } from "./SlaEscalationChain";
import { FloatingInput, FloatingTextarea } from "@/components/ui/FloatingInput";
import { useToast } from "@/components/ui/Toast";
import type { Pipeline, PipelineStage, Automation, EscalationLevel } from "@/lib/types";

interface Props {
  mode: "create" | "edit";
  initialData?: Automation;
  onSuccess: () => void;
}

type EntityType = "deal" | "lead" | "approval" | "task";

const ENTITY_OPTIONS: { value: EntityType; label: string }[] = [
  { value: "deal", label: "Сделка" },
  { value: "lead", label: "Лид" },
  { value: "approval", label: "Согласование" },
  { value: "task", label: "Задача" },
];

const UNIT_OPTIONS = [
  { value: "days", label: "дней" },
  { value: "hours", label: "часов" },
] as const;

type TimeUnit = "days" | "hours";

interface Step1State {
  name: string;
  entityType: EntityType;
  pipelineId: string;
  stageId: string;
}

interface Step2State {
  threshold: number;
  unit: TimeUnit;
  escalationChain: EscalationLevel[];
}

interface Step3State {
  sendTg: boolean;
  tgMessage: string;
  markOverdue: boolean;
  createNotification: boolean;
  description: string;
}

function parseInitial(data: Automation | undefined): {
  step1: Step1State;
  step2: Step2State;
  step3: Step3State;
} {
  const cfg = data?.trigger_config ?? {};
  const targetType = typeof cfg.target_type === "string" ? (cfg.target_type as EntityType) : "deal";
  const days = typeof cfg.days === "number" ? cfg.days : 7;
  const hours = typeof cfg.hours === "number" ? cfg.hours : 0;
  const chain = Array.isArray(cfg.escalation_chain) ? (cfg.escalation_chain as EscalationLevel[]) : [];

  const actionCfg = data?.action_config ?? {};
  const tgMsg = typeof actionCfg.message === "string" ? actionCfg.message : "";

  return {
    step1: {
      name: data?.name ?? "",
      entityType: targetType,
      pipelineId: data?.pipeline_id ? String(data.pipeline_id) : "",
      stageId: data?.stage_id ? String(data.stage_id) : "",
    },
    step2: {
      threshold: hours > 0 ? hours : days,
      unit: hours > 0 ? "hours" : "days",
      escalationChain: chain,
    },
    step3: {
      sendTg: data?.action_kind === "tg_notify" || !!tgMsg,
      tgMessage: tgMsg,
      markOverdue: !!(actionCfg.mark_overdue),
      createNotification: !!(actionCfg.create_notification),
      description: data?.description ?? "",
    },
  };
}

function StepIndicator({ current, step }: { current: number; step: 1 | 2 | 3 }) {
  const done = current > step;
  const active = current === step;
  const labels: Record<number, string> = {
    1: "Что отслеживаем",
    2: "Порог реакции",
    3: "Действия при нарушении",
  };
  return (
    <div className="flex flex-col items-center gap-1.5">
      <div
        className={[
          "w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold",
          "ring-2 transition-all duration-200",
          done
            ? "bg-success/15 text-success ring-success/30 dark:bg-success/20 dark:ring-success/40"
            : active
            ? "bg-primary text-white ring-primary/30 shadow-[0_0_0_4px] shadow-primary/10"
            : "bg-gray-100 text-gray-400 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700",
        ].join(" ")}
      >
        {done ? <i className="bi bi-check-lg" /> : step}
      </div>
      <span
        className={[
          "text-xs hidden sm:inline text-center max-w-[90px] leading-tight",
          active
            ? "font-semibold text-primary dark:text-primary-light"
            : done
            ? "text-success"
            : "text-gray-400 dark:text-gray-600",
        ].join(" ")}
      >
        {labels[step]}
      </span>
    </div>
  );
}

export function SlaWizard({ mode, initialData, onSuccess }: Props) {
  const router = useRouter();
  const { toast } = useToast();
  const init = parseInitial(initialData);

  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [step1, setStep1] = useState<Step1State>(init.step1);
  const [step2, setStep2] = useState<Step2State>(init.step2);
  const [step3, setStep3] = useState<Step3State>(init.step3);
  const [submitting, setSubmitting] = useState(false);
  const [apiError, setApiError] = useState<string | null>(null);
  const [step1Errors, setStep1Errors] = useState<Partial<Record<keyof Step1State, string>>>({});

  const showPipeline = step1.entityType === "deal" || step1.entityType === "lead";

  const { data: pipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const { data: stages } = useSWR<PipelineStage[]>(
    step1.pipelineId ? `/pipelines/${step1.pipelineId}/stages` : null,
    fetcher,
  );

  function validateStep1(): boolean {
    const errs: Partial<Record<keyof Step1State, string>> = {};
    if (!step1.name.trim()) errs.name = "Укажи название правила";
    if (!step1.entityType) errs.entityType = "Выбери тип записи";
    setStep1Errors(errs);
    return Object.keys(errs).length === 0;
  }

  function validateStep2(): boolean {
    if (step2.threshold < 1) return false;
    // Проверяем цепочку
    for (let i = 1; i < step2.escalationChain.length; i++) {
      if (step2.escalationChain[i].after_days <= step2.escalationChain[i - 1].after_days) {
        return false;
      }
    }
    return true;
  }

  function goNext() {
    if (step === 1 && !validateStep1()) return;
    if (step === 2 && !validateStep2()) return;
    if (step < 3) setStep((s) => (s + 1) as 1 | 2 | 3);
  }

  function goBack() {
    if (step > 1) setStep((s) => (s - 1) as 1 | 2 | 3);
  }

  async function submit() {
    setSubmitting(true);
    setApiError(null);
    try {
      const thresholdDays = step2.unit === "days" ? step2.threshold : 0;
      const thresholdHours = step2.unit === "hours" ? step2.threshold : 0;

      const payload = {
        name: step1.name,
        description: step3.description || null,
        pipeline_id: step1.pipelineId ? Number(step1.pipelineId) : null,
        stage_id: step1.stageId ? Number(step1.stageId) : null,
        trigger_kind: "idle_in_stage_days",
        trigger_config: {
          target_type: step1.entityType,
          days: thresholdDays,
          hours: thresholdHours,
          escalation_chain: step2.escalationChain,
        },
        action_kind: "tg_notify",
        action_config: {
          recipient: "owner",
          message: step3.tgMessage,
          mark_overdue: step3.markOverdue,
          create_notification: step3.createNotification,
          notification_kind: step3.createNotification ? "sla_breach" : null,
        },
        is_active: true,
      };

      if (mode === "edit" && initialData) {
        await api(`/automations/${initialData.id}`, { method: "PUT", body: payload });
      } else {
        await api("/automations", { method: "POST", body: payload });
      }

      onSuccess();
      toast.success(
        mode === "edit" ? "SLA-правило обновлено" : "SLA-правило создано",
        step1.name
      );
      router.push("/admin/sla");
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить правило. Попробуй ещё раз.";
      setApiError(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="p-8 max-w-2xl mx-auto space-y-8">
      {/* Степпер v2 */}
      <div className="flex items-start gap-0">
        <StepIndicator current={step} step={1} />
        <div className="flex-1 h-px bg-gray-200 dark:bg-gray-700 mt-[18px] mx-1" />
        <StepIndicator current={step} step={2} />
        <div className="flex-1 h-px bg-gray-200 dark:bg-gray-700 mt-[18px] mx-1" />
        <StepIndicator current={step} step={3} />
      </div>

      {/* Шаг 1 */}
      {step === 1 && (
        <div className="card rounded-2xl shadow-elev-1 p-6 space-y-5">
          <h2 className="text-sm font-semibold text-primary uppercase tracking-wide">
            Шаг 1 — Что отслеживаем
          </h2>

          <FloatingInput
            label="Название правила"
            required
            value={step1.name}
            onChange={(e) => setStep1({ ...step1, name: e.target.value })}
            error={step1Errors.name}
            icon="bi-tag"
          />

          <div>
            <label className="label">Тип записи *</label>
            <select
              className="input"
              value={step1.entityType}
              onChange={(e) => {
                setStep1({ ...step1, entityType: e.target.value as EntityType, pipelineId: "", stageId: "" });
              }}
            >
              {ENTITY_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
            {step1Errors.entityType && (
              <div className="text-xs text-danger mt-1">{step1Errors.entityType}</div>
            )}
          </div>

          {showPipeline && (
            <>
              <div>
                <label className="label">Воронка</label>
                <select
                  className="input"
                  value={step1.pipelineId}
                  onChange={(e) => setStep1({ ...step1, pipelineId: e.target.value, stageId: "" })}
                >
                  <option value="">Без воронки</option>
                  {(pipelines ?? []).map((p) => (
                    <option key={p.id} value={String(p.id)}>{p.name}</option>
                  ))}
                </select>
              </div>

              {step1.pipelineId && (
                <div>
                  <label className="label">Этап</label>
                  <select
                    className="input"
                    value={step1.stageId}
                    onChange={(e) => setStep1({ ...step1, stageId: e.target.value })}
                  >
                    <option value="">Все этапы</option>
                    {(stages ?? []).map((s) => (
                      <option key={s.id} value={String(s.id)}>{s.name}</option>
                    ))}
                  </select>
                </div>
              )}
            </>
          )}

          <div className="flex justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
            <button
              type="button"
              className="btn-ghost"
              onClick={() => router.push("/admin/sla")}
            >
              Отмена
            </button>
            <button
              type="button"
              className="btn-primary"
              onClick={goNext}
              disabled={!step1.name.trim()}
            >
              Далее
              <i className="bi bi-arrow-right ml-1" />
            </button>
          </div>
        </div>
      )}

      {/* Шаг 2 */}
      {step === 2 && (
        <div className="card rounded-2xl shadow-elev-1 p-6 space-y-5">
          <h2 className="text-sm font-semibold text-primary uppercase tracking-wide">
            Шаг 2 — Порог реакции
          </h2>

          <div>
            <label className="label">Без изменений дольше *</label>
            <div className="flex gap-2">
              <input
                type="number"
                min={1}
                className="input w-24 tabular-nums"
                value={step2.threshold}
                onChange={(e) =>
                  setStep2({ ...step2, threshold: Math.max(1, Number(e.target.value) || 1) })
                }
              />
              <select
                className="input"
                value={step2.unit}
                onChange={(e) => setStep2({ ...step2, unit: e.target.value as TimeUnit })}
              >
                {UNIT_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="border-t border-gray-100 dark:border-gray-700 pt-4">
            <SlaEscalationChain
              levels={step2.escalationChain}
              onChange={(levels) => setStep2({ ...step2, escalationChain: levels })}
            />
          </div>

          <div className="flex justify-between gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
            <button type="button" className="btn-ghost" onClick={goBack}>
              <i className="bi bi-arrow-left mr-1" />
              Назад
            </button>
            <button
              type="button"
              className="btn-primary"
              onClick={goNext}
              disabled={step2.threshold < 1}
            >
              Далее
              <i className="bi bi-arrow-right ml-1" />
            </button>
          </div>
        </div>
      )}

      {/* Шаг 3 */}
      {step === 3 && (
        <div className="card rounded-2xl shadow-elev-1 p-6 space-y-5">
          <h2 className="text-sm font-semibold text-primary uppercase tracking-wide">
            Шаг 3 — Действия при нарушении
          </h2>

          <div className="space-y-3">
            {/* Telegram */}
            <label className="flex items-start gap-3 cursor-pointer group">
              <input
                id="sla-send-tg"
                type="checkbox"
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary accent-primary"
                checked={step3.sendTg}
                onChange={(e) => setStep3({ ...step3, sendTg: e.target.checked })}
              />
              <span className="text-sm text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors">
                Отправить Telegram-уведомление
              </span>
            </label>

            {step3.sendTg && (
              <div className="ml-7 space-y-2">
                <FloatingTextarea
                  label="Текст уведомления"
                  value={step3.tgMessage}
                  onChange={(e) => setStep3({ ...step3, tgMessage: e.target.value })}
                  rows={3}
                  hint={
                    <span>
                      Jinja: <code className="text-primary">{"{{deal_name}}"}</code>,{" "}
                      <code className="text-primary">{"{{days_idle}}"}</code>,{" "}
                      <code className="text-primary">{"{{stage_name}}"}</code>
                    </span>
                  }
                />
              </div>
            )}

            {/* Mark overdue */}
            <label className="flex items-start gap-3 cursor-pointer group">
              <input
                id="sla-mark-overdue"
                type="checkbox"
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary accent-primary"
                checked={step3.markOverdue}
                onChange={(e) => setStep3({ ...step3, markOverdue: e.target.checked })}
              />
              <span className="text-sm text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors">
                Пометить запись как просроченную
              </span>
            </label>

            {/* System notification */}
            <label className="flex items-start gap-3 cursor-pointer group">
              <input
                id="sla-create-notification"
                type="checkbox"
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-primary accent-primary"
                checked={step3.createNotification}
                onChange={(e) => setStep3({ ...step3, createNotification: e.target.checked })}
              />
              <span className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors">
                Создать системное уведомление
                <span className="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium bg-warning/10 text-warning dark:bg-warning/20">
                  Скоро
                </span>
              </span>
            </label>
          </div>

          <FloatingTextarea
            label="Описание (необязательно)"
            value={step3.description}
            onChange={(e) => setStep3({ ...step3, description: e.target.value })}
            rows={2}
          />

          {apiError && (
            <div className="flex items-start gap-2 rounded-lg border border-danger/30 bg-danger/5 dark:bg-danger/10 px-4 py-3 text-sm text-danger">
              <i className="bi bi-exclamation-triangle-fill mt-0.5 shrink-0" />
              {apiError}
            </div>
          )}

          <div className="flex justify-between gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
            <button type="button" className="btn-ghost" onClick={goBack}>
              <i className="bi bi-arrow-left mr-1" />
              Назад
            </button>
            <button
              type="button"
              className="btn-primary"
              onClick={submit}
              disabled={submitting}
            >
              {submitting ? (
                <>
                  <i className="bi bi-arrow-repeat animate-spin mr-1" />
                  {mode === "edit" ? "Сохраняем…" : "Создаём…"}
                </>
              ) : (
                mode === "edit" ? "Сохранить" : "Создать правило"
              )}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
