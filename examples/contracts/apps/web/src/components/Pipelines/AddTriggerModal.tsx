"use client";

import { useState, useMemo } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import { TRIGGER_OPTIONS, ACTION_OPTIONS, ACTION_LABELS, ACTION_ICONS, ACTION_ICON_COLORS } from "@/lib/automationConfig";
import { isNewActionKind } from "@/lib/pipelineVisual";
import type { AutomationActionKind, AutomationTriggerKind, Pipeline, PipelineStage } from "@/lib/types";

// Action configs
import { CreateTaskConfig } from "@/components/Automations/actions/CreateTaskConfig";
import { TgNotifyConfig } from "@/components/Automations/actions/TgNotifyConfig";
import { EmailConfig } from "@/components/Automations/actions/EmailConfig";
import { ChangeOwnerConfig } from "@/components/Automations/actions/ChangeOwnerConfig";
import { SetFieldConfig } from "@/components/Automations/actions/SetFieldConfig";
import { WebhookConfig } from "@/components/Automations/actions/WebhookConfig";
import { GenerateDocumentConfig } from "@/components/Automations/actions/GenerateDocumentConfig";
import { StartSequenceConfig } from "@/components/Automations/actions/StartSequenceConfig";
import { SetTagsConfig } from "@/components/Automations/actions/SetTagsConfig";
import { CompleteTasksConfig } from "@/components/Automations/actions/CompleteTasksConfig";
import { ChangeStageConfig } from "@/components/Automations/actions/ChangeStageConfig";

// Trigger configs
import { IdleInStageDaysConfig } from "@/components/Automations/triggers/IdleInStageDaysConfig";
import { DateFieldApproachingConfig } from "@/components/Automations/triggers/DateFieldApproachingConfig";

interface AddTriggerModalProps {
  pipelineId: number;
  stage: PipelineStage;
  onClose: () => void;
  onCreated: () => void;
}

type Step = 1 | 2 | 3;

function StepIndicator({ current }: { current: Step }) {
  const steps: { num: Step; label: string }[] = [
    { num: 1, label: "Действие" },
    { num: 2, label: "Параметры" },
    { num: 3, label: "Триггер" },
  ];

  return (
    <div className="flex items-center gap-1 mb-6">
      {steps.map((s, i) => (
        <div key={s.num} className="flex items-center gap-1">
          <span
            className={`text-sm px-2 py-0.5 rounded ${
              current === s.num
                ? "text-primary font-semibold bg-primary/10"
                : current > s.num
                  ? "text-success"
                  : "text-gray-400"
            }`}
          >
            {s.num} {s.label}
          </span>
          {i < steps.length - 1 && <span className="text-gray-300">────</span>}
        </div>
      ))}
    </div>
  );
}

function CreateDealConfig({
  config,
  onChange,
}: {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}) {
  const { data: pipelines = [] } = useSWR<Pipeline[]>("/api/pipelines", fetcher, {
    revalidateOnFocus: false,
    dedupingInterval: 60_000,
  });

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Воронка для новой сделки</label>
        <select
          className="input"
          value={typeof config.pipeline_id === "number" ? config.pipeline_id : ""}
          onChange={(e) =>
            onChange({ ...config, pipeline_id: e.target.value ? Number(e.target.value) : undefined })
          }
        >
          <option value="">Выберите воронку…</option>
          {pipelines.map((p) => (
            <option key={p.id} value={p.id}>{p.name}</option>
          ))}
        </select>
      </div>
    </div>
  );
}

function ActionConfigRenderer({
  actionKind,
  config,
  onChange,
}: {
  actionKind: AutomationActionKind;
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}) {
  switch (actionKind) {
    case "create_task":
      return <CreateTaskConfig config={config} onChange={onChange} />;
    case "tg_notify":
      return <TgNotifyConfig config={config} onChange={onChange} />;
    case "email":
      return <EmailConfig config={config} onChange={onChange} />;
    case "change_owner":
      return <ChangeOwnerConfig config={config} onChange={onChange} />;
    case "set_field":
      return <SetFieldConfig config={config} onChange={onChange} targetType="deal" />;
    case "webhook":
      return <WebhookConfig config={config} onChange={onChange} />;
    case "generate_document":
      return <GenerateDocumentConfig config={config} onChange={onChange} />;
    case "start_sequence":
      return <StartSequenceConfig config={config} onChange={onChange} />;
    case "set_tags":
      return <SetTagsConfig config={config} onChange={onChange} />;
    case "complete_tasks":
      return <CompleteTasksConfig config={config} onChange={onChange} />;
    case "change_stage":
      return <ChangeStageConfig config={config} onChange={onChange} />;
    case "create_deal":
      return <CreateDealConfig config={config} onChange={onChange} />;
    default:
      return <div className="text-sm text-gray-500">Нет конфигурации для этого действия.</div>;
  }
}

export function AddTriggerModal({ pipelineId, stage, onClose, onCreated }: AddTriggerModalProps) {
  const [step, setStep] = useState<Step>(1);
  const [searchQuery, setSearchQuery] = useState("");
  const [selectedAction, setSelectedAction] = useState<AutomationActionKind | null>(null);
  const [actionConfig, setActionConfig] = useState<Record<string, unknown>>({});
  const [triggerKind, setTriggerKind] = useState<AutomationTriggerKind>("on_enter_stage");
  const [triggerConfig, setTriggerConfig] = useState<Record<string, unknown>>({});
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  const filteredActions = useMemo(() => {
    const q = searchQuery.toLowerCase().trim();
    if (!q) return ACTION_OPTIONS;
    return ACTION_OPTIONS.filter((a) => a.label.toLowerCase().includes(q));
  }, [searchQuery]);

  function selectAction(kind: AutomationActionKind) {
    setSelectedAction(kind);
    setActionConfig({});
    setStep(2);
  }

  function goBack() {
    if (step === 2) setStep(1);
    else if (step === 3) setStep(2);
  }

  function goNext() {
    if (step === 2) setStep(3);
  }

  async function handleSave() {
    if (!selectedAction || !triggerKind) return;
    setSaving(true);
    setSaveError(null);

    const actionLabel = ACTION_LABELS[selectedAction] ?? selectedAction;
    const triggerOpt = TRIGGER_OPTIONS.find((t) => t.value === triggerKind);
    const triggerLabel = triggerOpt?.label ?? triggerKind;

    try {
      await api("/automations", {
        method: "POST",
        body: {
          name: `${actionLabel} при ${triggerLabel}`,
          pipeline_id: pipelineId,
          stage_id: stage.id,
          trigger_kind: triggerKind,
          trigger_config: triggerConfig,
          action_kind: selectedAction,
          action_config: actionConfig,
          is_active: true,
        },
      });
      onCreated();
      onClose();
    } catch (err) {
      setSaveError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось сохранить автоматизацию",
      );
    } finally {
      setSaving(false);
    }
  }

  const isNewAction = selectedAction ? isNewActionKind(selectedAction) : false;

  return (
    <Modal
      open
      title="Добавить автоматизацию"
      description={`на этап «${stage.name}»`}
      onClose={onClose}
    >
      <StepIndicator current={step} />

      {/* Шаг 1: Выбор действия */}
      {step === 1 && (
        <div>
          <input
            className="input mb-4"
            placeholder="Поиск действий…"
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
          />
          <div className="grid grid-cols-3 gap-3">
            {filteredActions.map((action) => {
              const icon = ACTION_ICONS[action.value];
              const iconColor = ACTION_ICON_COLORS[action.value];
              return (
                <button
                  key={action.value}
                  onClick={() => selectAction(action.value)}
                  className="flex flex-col items-center gap-2 p-4 rounded-lg border border-gray-200 hover:border-primary hover:bg-primary/5 transition-colors cursor-pointer text-center"
                >
                  <span className={`text-2xl ${iconColor}`}>
                    <i className={`bi ${icon}`} />
                  </span>
                  <span className="text-sm font-medium text-gray-700 leading-tight">
                    {action.label}
                  </span>
                  {action.isNew && (
                    <span className="text-xs text-warning bg-warning/10 px-1.5 py-0.5 rounded">
                      скоро
                    </span>
                  )}
                </button>
              );
            })}
            {filteredActions.length === 0 && (
              <div className="col-span-3 text-center text-gray-400 py-8 text-sm">
                Ничего не найдено
              </div>
            )}
          </div>
          <div className="flex justify-end mt-4">
            <button onClick={onClose} className="btn-ghost">Отмена</button>
          </div>
        </div>
      )}

      {/* Шаг 2: Параметры действия */}
      {step === 2 && selectedAction && (
        <div>
          <h3 className="text-sm font-semibold text-gray-700 mb-4">
            Настройки: {ACTION_LABELS[selectedAction] ?? selectedAction}
          </h3>

          {isNewAction && (
            <div className="mb-4 bg-warning/10 border border-warning/30 text-warning text-xs px-3 py-2 rounded">
              <i className="bi bi-exclamation-triangle mr-1" />
              Это действие требует обновления backend. Сохранение вернёт ошибку 422.
            </div>
          )}

          <ActionConfigRenderer
            actionKind={selectedAction}
            config={actionConfig}
            onChange={setActionConfig}
          />

          <div className="flex justify-between mt-6">
            <button onClick={goBack} className="btn-secondary">
              <i className="bi bi-arrow-left mr-1" />Назад
            </button>
            <button onClick={goNext} className="btn-primary">
              Далее <i className="bi bi-arrow-right ml-1" />
            </button>
          </div>
        </div>
      )}

      {/* Шаг 3: Триггер */}
      {step === 3 && (
        <div>
          <h3 className="text-sm font-semibold text-gray-700 mb-4">Когда запускать?</h3>

          <div className="space-y-2 mb-4">
            {TRIGGER_OPTIONS.map((trigger) => (
              <button
                key={trigger.value}
                onClick={() => {
                  setTriggerKind(trigger.value);
                  setTriggerConfig({});
                }}
                className={`w-full text-left p-3 rounded-lg border transition-colors ${
                  triggerKind === trigger.value
                    ? "border-primary bg-primary/5"
                    : "border-gray-200 hover:border-gray-300"
                }`}
              >
                <div className="flex items-center gap-3">
                  <div className="flex-1">
                    <div className="text-sm font-medium text-gray-800">{trigger.label}</div>
                    <div className="text-xs text-gray-500">{trigger.description}</div>
                  </div>
                  {triggerKind === trigger.value && (
                    <i className="bi bi-check-circle-fill text-primary shrink-0" />
                  )}
                </div>
              </button>
            ))}
          </div>

          {/* Дополнительный конфиг триггера */}
          {triggerKind === "idle_in_stage_days" && (
            <div className="border border-gray-200 rounded-lg p-3 mb-4">
              <IdleInStageDaysConfig config={triggerConfig} onChange={setTriggerConfig} />
            </div>
          )}
          {triggerKind === "date_field_approaching" && (
            <div className="border border-gray-200 rounded-lg p-3 mb-4">
              <DateFieldApproachingConfig config={triggerConfig} onChange={setTriggerConfig} />
            </div>
          )}

          {saveError && (
            <div className="mb-3 text-danger bg-danger/10 px-3 py-2 rounded text-sm">
              {saveError}
            </div>
          )}

          <div className="flex justify-between">
            <button onClick={goBack} className="btn-secondary">
              <i className="bi bi-arrow-left mr-1" />Назад
            </button>
            <button
              onClick={handleSave}
              disabled={saving || !triggerKind}
              className="btn-primary disabled:opacity-50"
            >
              {saving ? "Сохранение…" : "Сохранить и добавить"}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}
