"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { useToast } from "@/components/ui/Toast";
import {
  ACTION_OPTIONS,
  IDLE_SUPPORTED_TARGET_TYPES,
  TARGET_TYPE_LABELS,
  TRIGGER_OPTIONS,
} from "@/lib/automationConfig";
import type {
  Automation,
  AutomationActionKind,
  AutomationTargetType,
  AutomationTriggerKind,
  Pipeline,
  PipelineStage,
} from "@/lib/types";
import { TriggerBadge } from "./TriggerBadge";
import { ActionBadge } from "./ActionBadge";
import { TestRunModal } from "./TestRunModal";
import { OnEnterStageConfig } from "./triggers/OnEnterStageConfig";
import { IdleInStageDaysConfig } from "./triggers/IdleInStageDaysConfig";
import { DateFieldApproachingConfig } from "./triggers/DateFieldApproachingConfig";
import { OnCreateConfig } from "./triggers/OnCreateConfig";
import { TgNotifyConfig } from "./actions/TgNotifyConfig";
import { CreateTaskConfig } from "./actions/CreateTaskConfig";
import { SetFieldConfig } from "./actions/SetFieldConfig";
import { GenerateDocumentConfig } from "./actions/GenerateDocumentConfig";
import { ChangeOwnerConfig } from "./actions/ChangeOwnerConfig";
import { WebhookConfig } from "./actions/WebhookConfig";
import { EmailConfig } from "./actions/EmailConfig";
import { StartSequenceConfig } from "./actions/StartSequenceConfig";

interface Props {
  mode: "new" | "edit";
  initialData?: Automation;
}

interface FormState {
  name: string;
  description: string;
  pipeline_id: string;
  stage_id: string;
  trigger_kind: AutomationTriggerKind;
  trigger_config: Record<string, unknown>;
  action_kind: AutomationActionKind;
  action_config: Record<string, unknown>;
  is_active: boolean;
}

function inferTargetType(
  triggerKind: AutomationTriggerKind,
  triggerConfig: Record<string, unknown>,
  pipelines: Pipeline[],
  pipelineId: string,
): AutomationTargetType {
  // idle / date_field / on_create → читаем из trigger_config.target_type
  if (triggerKind === "idle_in_stage_days") {
    const t = triggerConfig.target_type;
    if (typeof t === "string" && IDLE_SUPPORTED_TARGET_TYPES.includes(t as AutomationTargetType)) {
      return t as AutomationTargetType;
    }
    return "deal";
  }
  if (triggerKind === "date_field_approaching") {
    const t = triggerConfig.target_type;
    if (typeof t === "string" && (t === "subscription" || t === "deal" || t === "lead")) {
      return t as AutomationTargetType;
    }
    return "subscription";
  }
  if (triggerKind === "on_create") {
    const t = triggerConfig.target_type;
    // inbound_message is a valid on_create target in trigger_config but is not a
    // backend-supported AutomationTargetType for action execution — map it to "lead"
    // so action configs (set_field whitelist, etc.) fall back gracefully.
    if (t === "lead" || t === "deal") {
      return t as AutomationTargetType;
    }
    return "lead";
  }
  // on_enter_stage → определяем по pipeline.kind
  const pipe = pipelines.find((p) => String(p.id) === pipelineId);
  if (!pipe) return "deal";
  if (pipe.kind === "lead") return "lead";
  if (pipe.kind === "lifecycle" || pipe.kind === "renewal") return "subscription";
  return "deal";
}

const EMPTY: FormState = {
  name: "",
  description: "",
  pipeline_id: "",
  stage_id: "",
  trigger_kind: "on_enter_stage",
  trigger_config: {},
  action_kind: "tg_notify",
  action_config: { recipient: "owner", message: "" },
  is_active: true,
};

function fromAutomation(a: Automation): FormState {
  return {
    name: a.name,
    description: a.description ?? "",
    pipeline_id: String(a.pipeline_id),
    stage_id: a.stage_id == null ? "" : String(a.stage_id),
    trigger_kind: a.trigger_kind,
    trigger_config: a.trigger_config ?? {},
    action_kind: a.action_kind,
    action_config: a.action_config ?? {},
    is_active: a.is_active,
  };
}

export function AutomationForm({ mode, initialData }: Props) {
  const router = useRouter();
  const { toast } = useToast();
  const [form, setForm] = useState<FormState>(() =>
    initialData ? fromAutomation(initialData) : EMPTY,
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleteConfirm, setDeleteConfirm] = useState(false);
  const [testOpen, setTestOpen] = useState(false);

  const { data: pipelines } = useSWR<Pipeline[]>("/pipelines", fetcher);
  const { data: stages } = useSWR<PipelineStage[]>(
    form.pipeline_id ? `/pipelines/${form.pipeline_id}/stages` : null,
    fetcher,
  );

  const targetType = useMemo<AutomationTargetType>(
    () => inferTargetType(form.trigger_kind, form.trigger_config, pipelines ?? [], form.pipeline_id),
    [form.trigger_kind, form.trigger_config, pipelines, form.pipeline_id],
  );

  const triggerMeta = TRIGGER_OPTIONS.find((t) => t.value === form.trigger_kind);
  const actionMeta = ACTION_OPTIONS.find((a) => a.value === form.action_kind);

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  function changeTrigger(kind: AutomationTriggerKind) {
    // При смене триггера сбрасываем trigger_config — структура разная
    setForm((f) => ({ ...f, trigger_kind: kind, trigger_config: defaultTriggerConfig(kind) }));
  }

  function changeAction(kind: AutomationActionKind) {
    setForm((f) => ({ ...f, action_kind: kind, action_config: defaultActionConfig(kind) }));
  }

  function validate(): string | null {
    if (!form.name.trim()) return "Название обязательно";
    if (!form.pipeline_id) return "Выберите воронку";
    if (form.trigger_kind === "date_field_approaching" && !form.trigger_config.field) {
      return "Для триггера «Дата приближается» выберите поле даты";
    }
    if (form.action_kind === "tg_notify" && !form.action_config.message) {
      return "Заполните текст сообщения для Telegram";
    }
    if (form.action_kind === "set_field") {
      if (!form.action_config.field) return "Для действия «Изменить поле» выберите поле";
    }
    if (form.action_kind === "generate_document" && !form.action_config.template_code) {
      return "Для действия «Сгенерировать документ» выберите шаблон";
    }
    if (form.action_kind === "webhook" && !form.action_config.url) {
      return "Укажи URL для Webhook";
    }
    if (form.action_kind === "email") {
      if (!form.action_config.subject) return "Укажи тему письма";
      if (!form.action_config.body) return "Укажи текст письма";
      if (
        form.action_config.recipient_kind === "specific" &&
        !form.action_config.recipient_user_id
      ) {
        return "Выбери получателя письма";
      }
    }
    if (form.action_kind === "start_sequence" && !form.action_config.sequence_id) {
      return "Выбери последовательность";
    }
    return null;
  }

  async function submit() {
    setError(null);
    const v = validate();
    if (v) {
      setError(v);
      return;
    }
    setSubmitting(true);
    try {
      const body = {
        name: form.name.trim(),
        description: form.description.trim() || null,
        pipeline_id: Number(form.pipeline_id),
        stage_id: form.stage_id ? Number(form.stage_id) : null,
        trigger_kind: form.trigger_kind,
        trigger_config: form.trigger_config,
        action_kind: form.action_kind,
        action_config: form.action_config,
        is_active: form.is_active,
      };
      if (mode === "new") {
        const created = await api<Automation>("/automations", { method: "POST", body });
        toast.success("Автоматизация создана");
        router.push(`/admin/automations/${created.id}`);
      } else if (initialData) {
        await api(`/automations/${initialData.id}`, { method: "PATCH", body });
        toast.success("Изменения сохранены");
        router.push("/admin/automations");
        router.refresh();
      }
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось сохранить",
      );
    } finally {
      setSubmitting(false);
    }
  }

  async function remove() {
    if (!initialData) return;
    setSubmitting(true);
    setError(null);
    try {
      await api(`/automations/${initialData.id}`, { method: "DELETE" });
      router.push("/admin/automations");
      router.refresh();
    } catch (err) {
      setError(
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось удалить",
      );
      setSubmitting(false);
    }
  }

  return (
    <div className="p-8 space-y-6 max-w-4xl">
      {error && (
        <div className="text-sm text-danger bg-danger/10 border border-danger/30 px-3 py-2 rounded-md">
          {error}
        </div>
      )}

      {/* === Основное === */}
      <section className="card rounded-2xl shadow-elev-1 p-6 space-y-4">
        <h2 className="text-h4">Основное</h2>
        <div>
          <label className="label">Название <span className="text-danger">*</span></label>
          <input
            className="input"
            type="text"
            value={form.name}
            onChange={(e) => update("name", e.target.value)}
            placeholder="Например, «Напомнить о холодной сделке через 7 дней»"
          />
        </div>
        <div>
          <label className="label">Описание</label>
          <textarea
            className="input"
            rows={2}
            value={form.description}
            onChange={(e) => update("description", e.target.value)}
            placeholder="Что делает эта автоматизация и зачем — для команды"
          />
        </div>
        <label className="flex items-center gap-2 cursor-pointer">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => update("is_active", e.target.checked)}
          />
          <span>Активна</span>
        </label>
      </section>

      {/* === Воронка и этап === */}
      <section className="card rounded-2xl shadow-elev-1 p-6 space-y-4">
        <h2 className="text-h4">Воронка</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="label">Воронка <span className="text-danger">*</span></label>
            <select
              className="input"
              value={form.pipeline_id}
              onChange={(e) => setForm((f) => ({ ...f, pipeline_id: e.target.value, stage_id: "" }))}
            >
              <option value="">— выберите воронку —</option>
              {(pipelines ?? []).map((p) => (
                <option key={p.id} value={String(p.id)}>
                  {p.name} ({p.kind})
                </option>
              ))}
            </select>
            <div className="text-xs text-gray-500 mt-1">
              Тип цели определяется автоматически по виду воронки:
              <strong> {TARGET_TYPE_LABELS[targetType]}</strong>.
            </div>
          </div>
          <div>
            <label className="label">Этап (опционально)</label>
            <select
              className="input"
              value={form.stage_id}
              onChange={(e) => update("stage_id", e.target.value)}
              disabled={!form.pipeline_id}
            >
              <option value="">Все этапы воронки</option>
              {(stages ?? []).map((s) => (
                <option key={s.id} value={String(s.id)}>{s.name}</option>
              ))}
            </select>
            <div className="text-xs text-gray-500 mt-1">
              Пусто = автоматизация работает на любом этапе воронки.
            </div>
          </div>
        </div>
      </section>

      {/* === Триггер === */}
      <section className="card rounded-2xl shadow-elev-1 p-6 space-y-4">
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-h4">Триггер</h2>
          <TriggerBadge kind={form.trigger_kind} />
        </div>
        <div>
          <label className="label">Когда срабатывает</label>
          <select
            className="input"
            value={form.trigger_kind}
            onChange={(e) => changeTrigger(e.target.value as AutomationTriggerKind)}
          >
            {TRIGGER_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
          {triggerMeta && <div className="text-xs text-gray-500 mt-1">{triggerMeta.description}</div>}
        </div>

        <div className="border-t border-gray-200 pt-4">
          {form.trigger_kind === "on_enter_stage" && <OnEnterStageConfig />}
          {form.trigger_kind === "idle_in_stage_days" && (
            <IdleInStageDaysConfig
              config={form.trigger_config}
              onChange={(next) => update("trigger_config", next)}
            />
          )}
          {form.trigger_kind === "date_field_approaching" && (
            <DateFieldApproachingConfig
              config={form.trigger_config}
              onChange={(next) => update("trigger_config", next)}
            />
          )}
          {form.trigger_kind === "on_create" && (
            <OnCreateConfig
              config={form.trigger_config}
              onChange={(next) => update("trigger_config", next)}
            />
          )}
        </div>
      </section>

      {/* === Действие === */}
      <section className="card rounded-2xl shadow-elev-1 p-6 space-y-4">
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-h4">Действие</h2>
          <ActionBadge kind={form.action_kind} />
        </div>
        <div>
          <label className="label">Что сделать</label>
          <select
            className="input"
            value={form.action_kind}
            onChange={(e) => changeAction(e.target.value as AutomationActionKind)}
          >
            {ACTION_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
          {actionMeta && <div className="text-xs text-gray-500 mt-1">{actionMeta.description}</div>}
        </div>

        <div className="border-t border-gray-200 pt-4">
          {form.action_kind === "tg_notify" && (
            <TgNotifyConfig
              config={form.action_config}
              onChange={(next) => update("action_config", next)}
            />
          )}
          {form.action_kind === "create_task" && (
            <CreateTaskConfig
              config={form.action_config}
              onChange={(next) => update("action_config", next)}
            />
          )}
          {form.action_kind === "set_field" && (
            <SetFieldConfig
              config={form.action_config}
              onChange={(next) => update("action_config", next)}
              targetType={targetType}
            />
          )}
          {form.action_kind === "generate_document" && (
            <GenerateDocumentConfig
              config={form.action_config}
              onChange={(next) => update("action_config", next)}
            />
          )}
          {form.action_kind === "change_owner" && (
            <ChangeOwnerConfig
              config={form.action_config}
              onChange={(next) => update("action_config", next)}
            />
          )}
          {form.action_kind === "webhook" && (
            <WebhookConfig
              config={form.action_config}
              onChange={(next) => update("action_config", next)}
            />
          )}
          {form.action_kind === "email" && (
            <EmailConfig
              config={form.action_config}
              onChange={(next) => update("action_config", next)}
            />
          )}
          {form.action_kind === "start_sequence" && (
            <StartSequenceConfig
              config={form.action_config}
              onChange={(next) => update("action_config", next)}
            />
          )}
        </div>
      </section>

      {/* === Действия === */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex gap-2">
          <button
            className="btn-primary"
            onClick={submit}
            disabled={submitting}
          >
            <i className="bi bi-check-lg" />
            {submitting ? "Сохранение…" : mode === "new" ? "Создать" : "Сохранить"}
          </button>
          <button
            className="btn-secondary"
            onClick={() => router.push("/admin/automations")}
            disabled={submitting}
          >
            Отмена
          </button>
          <span className="relative inline-block">
            <button
              className="btn-secondary"
              onClick={() => setTestOpen(true)}
              disabled={mode === "new" || !initialData}
              title={
                mode === "new"
                  ? "Сначала сохраните автоматизацию, затем тестовый прогон станет доступен"
                  : "Тестовый прогон без записи AutomationRun"
              }
            >
              <i className="bi bi-play-fill" /> Тестовый прогон
            </button>
          </span>
        </div>
        {mode === "edit" && initialData && (
          <button
            className="btn-ghost text-danger"
            onClick={() => setDeleteConfirm(true)}
            disabled={submitting}
          >
            <i className="bi bi-trash" /> Удалить
          </button>
        )}
      </div>

      {/* === Test run modal === */}
      {initialData && (
        <TestRunModal
          open={testOpen}
          onClose={() => setTestOpen(false)}
          automation={initialData}
        />
      )}

      {/* === Delete confirm === */}
      {initialData && (
        <Modal
          open={deleteConfirm}
          title="Удалить автоматизацию?"
          onClose={() => setDeleteConfirm(false)}
          width="sm"
          footer={
            <>
              <button className="btn-ghost" onClick={() => setDeleteConfirm(false)}>
                Отмена
              </button>
              <button
                className="btn-primary bg-danger border-danger hover:bg-danger/90 disabled:opacity-50"
                disabled={submitting}
                onClick={() => { setDeleteConfirm(false); void remove(); }}
              >
                <i className="bi bi-trash mr-1" />
                Удалить
              </button>
            </>
          }
        >
          <p className="text-sm text-gray-700 dark:text-gray-300">
            «{initialData.name}» будет удалена безвозвратно. История запусков
            (AutomationRun) тоже удалится каскадом.
          </p>
        </Modal>
      )}
    </div>
  );
}

function defaultTriggerConfig(kind: AutomationTriggerKind): Record<string, unknown> {
  if (kind === "idle_in_stage_days") return { target_type: "deal", days: 7 };
  if (kind === "date_field_approaching") return { target_type: "subscription", field: "", days: 7 };
  if (kind === "on_create") return { target_type: "lead" };
  return {};
}

function defaultActionConfig(kind: AutomationActionKind): Record<string, unknown> {
  if (kind === "tg_notify") return { recipient: "owner", message: "" };
  if (kind === "create_task") return { title: "", responsible: "owner" };
  if (kind === "set_field") return { field: "", value: "" };
  if (kind === "generate_document") return { template_code: "" };
  if (kind === "change_owner") return { rule: "round_robin", roles: [], is_active: true };
  if (kind === "webhook") return { url: "" };
  if (kind === "email") return { recipient_kind: "owner", subject: "", body: "" };
  if (kind === "start_sequence") return { sequence_id: null };
  return {};
}
