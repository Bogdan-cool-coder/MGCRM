"use client";

import { useState, useEffect } from "react";
import { createPortal } from "react-dom";
import { useRouter } from "next/navigation";
import useSWR, { mutate } from "swr";
import { api, fetcher } from "@/lib/api";
import { UserSelect } from "@/components/UserSelect";
import { RecurrenceSelector } from "./RecurrenceSelector";
import { DateTimePicker } from "@/components/ui/DateTimePicker";
import type { TaskCategory, ActivityKind, ActivityPriority, ActivityTargetType } from "@/lib/types";
import { ACTIVITY_TARGET_LABELS } from "@/lib/types";

type RecurrenceRuleValue = "none" | "daily" | "weekly" | "monthly";

interface ChecklistDraft {
  _id: string;
  text: string;
  sort_order: number;
}

interface CollaboratorDraft {
  user_id: string;
  role: "co_executor" | "auditor" | "observer";
}

interface FormState {
  kind: ActivityKind;
  title: string;
  due_at: string;
  category_id: string;
  responsible_id: string;
  priority: ActivityPriority;
  planned_hours: string;
  body: string;
  tags_input: string;
  tags: string[];
  target_type: ActivityTargetType | "";
  recurrence_rule: RecurrenceRuleValue;
  recurrence_until: string | null;
  checklist_items: ChecklistDraft[];
  collaborators: CollaboratorDraft[];
  parent_activity_id: string;
  // FTM
  is_first_time_meeting: boolean;
  ftm_decision_maker_attended: boolean;
  ftm_presentation_shown: boolean;
  ftm_report_url: string;
}

interface Props {
  open: boolean;
  onClose: () => void;
  parentActivityId?: number;
  defaultKind?: ActivityKind;
}

const KIND_LABELS: Record<ActivityKind, string> = {
  task: "Задача",
  call: "Звонок",
  meeting: "Встреча",
  note: "Заметка",
};

const PRIORITY_LABELS: Record<ActivityPriority, string> = {
  low: "Низкий",
  normal: "Нормальный",
  high: "Высокий",
  critical: "Критический",
};

const PRIORITY_COLORS: Record<ActivityPriority, string> = {
  low: "bg-gray-300",
  normal: "bg-info",
  high: "bg-warning",
  critical: "bg-danger",
};

const TARGET_TYPES: { value: ActivityTargetType; label: string }[] = [
  { value: "lead", label: ACTIVITY_TARGET_LABELS.lead },
  { value: "deal", label: ACTIVITY_TARGET_LABELS.deal },
  { value: "contact", label: ACTIVITY_TARGET_LABELS.contact },
  { value: "company", label: ACTIVITY_TARGET_LABELS.company },
  { value: "counterparty", label: ACTIVITY_TARGET_LABELS.counterparty },
  { value: "contract", label: ACTIVITY_TARGET_LABELS.contract },
  { value: "subscription", label: ACTIVITY_TARGET_LABELS.subscription },
];

function defaultForm(parentActivityId?: number): FormState {
  return {
    kind: "task",
    title: "",
    due_at: "",
    category_id: "",
    responsible_id: "",
    priority: "normal",
    planned_hours: "",
    body: "",
    tags_input: "",
    tags: [],
    target_type: "",
    recurrence_rule: "none",
    recurrence_until: null,
    checklist_items: [],
    collaborators: [],
    parent_activity_id: parentActivityId ? String(parentActivityId) : "",
    is_first_time_meeting: false,
    ftm_decision_maker_attended: false,
    ftm_presentation_shown: false,
    ftm_report_url: "",
  };
}

export function TaskCreateDrawer({ open, onClose, parentActivityId, defaultKind }: Props) {
  const router = useRouter();
  const [mounted, setMounted] = useState(false);
  const [form, setForm] = useState<FormState>(() => defaultForm(parentActivityId));
  const [advanced, setAdvanced] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");
  const [checklistInput, setChecklistInput] = useState("");

  const { data: categories } = useSWR<TaskCategory[]>(open ? "/task-categories" : null, fetcher);

  useEffect(() => { setMounted(true); }, []);

  useEffect(() => {
    if (open) {
      setForm(defaultForm(parentActivityId));
      setAdvanced(false);
      setError("");
      if (defaultKind) setForm((f) => ({ ...f, kind: defaultKind }));
    }
  }, [open, parentActivityId, defaultKind]);

  function setField<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  function handleCategoryChange(catId: string) {
    setField("category_id", catId);
    if (!catId || !categories) return;
    const cat = categories.find((c) => String(c.id) === catId);
    if (!cat) return;
    if (cat.default_executor_id) setField("responsible_id", String(cat.default_executor_id));
    if (cat.description_template) setField("body", cat.description_template);
    if (cat.checklist_template_items.length > 0) {
      setField("checklist_items", cat.checklist_template_items.map((it) => ({ _id: crypto.randomUUID(), text: it.text, sort_order: it.sort_order })));
    }
  }

  function addChecklistItem() {
    if (!checklistInput.trim()) return;
    const next = [
      ...form.checklist_items,
      { _id: crypto.randomUUID(), text: checklistInput.trim(), sort_order: form.checklist_items.length },
    ];
    setField("checklist_items", next);
    setChecklistInput("");
  }

  function removeChecklistItem(idx: number) {
    setField("checklist_items", form.checklist_items.filter((_, i) => i !== idx));
  }

  function addTag() {
    const tag = form.tags_input.trim();
    if (!tag || form.tags.includes(tag)) return;
    setField("tags", [...form.tags, tag]);
    setField("tags_input", "");
  }

  function removeTag(tag: string) {
    setField("tags", form.tags.filter((t) => t !== tag));
  }

  function addCollaborator(role: "co_executor" | "auditor" | "observer") {
    setField("collaborators", [
      ...form.collaborators,
      { user_id: "", role },
    ]);
  }

  function updateCollaboratorUser(idx: number, userId: string) {
    const next = [...form.collaborators];
    next[idx] = { ...next[idx], user_id: userId };
    setField("collaborators", next);
  }

  function removeCollaborator(idx: number) {
    setField("collaborators", form.collaborators.filter((_, i) => i !== idx));
  }

  function validate(): string {
    if (!form.title.trim()) return "Укажите название задачи";
    if (form.kind !== "note" && !form.due_at) return "Укажите дедлайн";
    return "";
  }

  async function submit(openAfter: boolean) {
    const err = validate();
    if (err) { setError(err); return; }
    setError("");
    setSubmitting(true);
    try {
      const body: Record<string, unknown> = {
        kind: form.kind,
        title: form.title.trim(),
        priority: form.priority,
      };
      if (form.due_at) body.due_at = new Date(form.due_at).toISOString();
      if (form.category_id) body.category_id = Number(form.category_id);
      if (form.responsible_id) body.responsible_id = Number(form.responsible_id);
      if (form.planned_hours) body.planned_hours = Number(form.planned_hours);
      if (form.body.trim()) body.body = form.body.trim();
      if (form.tags.length > 0) body.tags = form.tags;
      if (form.recurrence_rule !== "none") {
        body.recurrence_rule = form.recurrence_rule;
        if (form.recurrence_until) body.recurrence_until = form.recurrence_until;
      }
      if (form.target_type) body.target_type = form.target_type;
      if (form.parent_activity_id) body.parent_activity_id = Number(form.parent_activity_id);
      const validCollabs = form.collaborators.filter((c) => c.user_id);
      if (validCollabs.length > 0) {
        body.collaborators = validCollabs.map((c) => ({ user_id: Number(c.user_id), role: c.role }));
      }
      if (form.checklist_items.length > 0) {
        body.checklist_items = form.checklist_items;
      }
      if (form.is_first_time_meeting) {
        body.is_first_time_meeting = true;
        body.ftm_decision_maker_attended = form.ftm_decision_maker_attended;
        body.ftm_presentation_shown = form.ftm_presentation_shown;
        if (form.ftm_report_url) body.ftm_report_url = form.ftm_report_url;
      }

      const created = await api<{ id: number }>("/activities", { method: "POST", body });
      await mutate("/activities?kind=task");
      await mutate("/activities/counts-by-preset");
      await mutate("/activities/my-open-count");
      onClose();
      if (openAfter) {
        router.push(`/tasks/${created.id}`);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Не удалось создать задачу");
    } finally {
      setSubmitting(false);
    }
  }

  function tryClose() {
    const isDirty = form.title.trim().length > 0;
    if (isDirty) {
      if (!confirm("Отменить создание задачи?")) return;
    }
    onClose();
  }

  if (!mounted || !open) return null;

  function CollabSection(role: "co_executor" | "auditor" | "observer", label: string) {
    const items = form.collaborators
      .map((c, i) => ({ ...c, idx: i }))
      .filter((c) => c.role === role);
    return (
      <div>
        <label className="label">{label}</label>
        <div className="space-y-1.5">
          {items.map((c) => (
            <div key={c.idx} className="flex items-center gap-2">
              <UserSelect
                value={c.user_id}
                onChange={(v) => updateCollaboratorUser(c.idx, v)}
                placeholder="Выбрать..."
                className="input flex-1"
              />
              <button
                type="button"
                onClick={() => removeCollaborator(c.idx)}
                className="text-gray-400 hover:text-danger p-1"
              >
                <i className="bi bi-x text-sm" />
              </button>
            </div>
          ))}
          <button
            type="button"
            onClick={() => addCollaborator(role)}
            className="text-sm text-primary hover:underline"
          >
            + Добавить
          </button>
        </div>
      </div>
    );
  }

  return createPortal(
    <div className="fixed inset-0 z-40">
      <div className="absolute inset-0 bg-black/40" onClick={tryClose} />
      <div className="absolute inset-y-0 right-0 w-[640px] bg-white dark:bg-gray-800 shadow-xl flex flex-col overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <h2 className="text-h4 dark:text-gray-100">Новая задача</h2>
          <button onClick={tryClose} className="p-1.5 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500">
            <i className="bi bi-x-lg text-xl" />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-5 space-y-5">
          {/* Тип */}
          <div>
            <label className="label">Тип</label>
            <div className="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
              {(["task", "call", "meeting", "note"] as ActivityKind[]).map((k) => (
                <button
                  key={k}
                  type="button"
                  onClick={() => setField("kind", k)}
                  className={
                    form.kind === k
                      ? "px-4 py-2 bg-primary text-white"
                      : "px-4 py-2 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700"
                  }
                >
                  {KIND_LABELS[k]}
                </button>
              ))}
            </div>
          </div>

          {/* Название */}
          <div>
            <label className="label">Название *</label>
            <input
              className="input"
              placeholder="Что нужно сделать..."
              value={form.title}
              onChange={(e) => setField("title", e.target.value)}
            />
          </div>

          {/* Дедлайн + Категория */}
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="label">Дедлайн {form.kind !== "note" ? "*" : ""}</label>
              <DateTimePicker
                value={form.due_at}
                onChange={(v) => setField("due_at", v)}
                placeholder="Выберите дату и время"
              />
            </div>
            <div>
              <label className="label">Категория</label>
              <select
                className="input"
                value={form.category_id}
                onChange={(e) => handleCategoryChange(e.target.value)}
              >
                <option value="">Без категории</option>
                {categories?.map((c) => (
                  <option key={c.id} value={String(c.id)}>{c.name}</option>
                ))}
              </select>
            </div>
          </div>

          {/* Ответственный */}
          <div>
            <label className="label">Ответственный</label>
            <UserSelect
              value={form.responsible_id}
              onChange={(v) => setField("responsible_id", v)}
              placeholder="Выбрать..."
            />
          </div>

          {/* Больше параметров */}
          <button
            type="button"
            onClick={() => setAdvanced(!advanced)}
            className="flex items-center gap-1 text-sm text-gray-500 hover:text-primary transition-colors"
          >
            <i className={`bi ${advanced ? "bi-chevron-down" : "bi-chevron-right"} text-xs`} />
            Больше параметров
          </button>

          {advanced && (
            <div className="space-y-5 border-t border-gray-100 dark:border-gray-700 pt-5">
              {/* Приоритет */}
              <div>
                <label className="label">Приоритет</label>
                <div className="flex flex-wrap gap-3">
                  {(["low", "normal", "high", "critical"] as ActivityPriority[]).map((p) => (
                    <label key={p} className="flex items-center gap-1.5 cursor-pointer text-sm">
                      <input
                        type="radio"
                        name="priority"
                        value={p}
                        checked={form.priority === p}
                        onChange={() => setField("priority", p)}
                      />
                      <span className={`w-2 h-2 rounded-full ${PRIORITY_COLORS[p]}`} />
                      {PRIORITY_LABELS[p]}
                    </label>
                  ))}
                </div>
              </div>

              {/* Соисполнители */}
              {CollabSection("co_executor", "Соисполнители")}
              {/* Аудиторы */}
              {CollabSection("auditor", "Аудиторы")}
              {/* Наблюдатели */}
              {CollabSection("observer", "Наблюдатели")}

              {/* Плановое время + Описание */}
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="label">Плановое время</label>
                  <div className="relative">
                    <input
                      type="number"
                      min="0"
                      step="0.5"
                      className="input"
                      placeholder="0"
                      value={form.planned_hours}
                      onChange={(e) => setField("planned_hours", e.target.value)}
                    />
                    <span className="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">ч</span>
                  </div>
                </div>
              </div>

              <div>
                <label className="label">Описание</label>
                <textarea
                  className="input min-h-[80px]"
                  placeholder="Добавить описание..."
                  value={form.body}
                  onChange={(e) => setField("body", e.target.value)}
                />
              </div>

              {/* Чек-лист */}
              <div>
                <label className="label">Чек-лист</label>
                <div className="space-y-1.5 mb-2">
                  {form.checklist_items.map((item, idx) => (
                    <div key={item._id ?? idx} className="flex items-center gap-2">
                      <span className="text-sm flex-1 text-gray-700 dark:text-gray-300">{item.text}</span>
                      <button type="button" onClick={() => removeChecklistItem(idx)} className="text-gray-400 hover:text-danger p-0.5">
                        <i className="bi bi-x text-sm" />
                      </button>
                    </div>
                  ))}
                </div>
                <div className="flex gap-2">
                  <input
                    className="input flex-1 text-sm"
                    placeholder="Название пункта..."
                    value={checklistInput}
                    onChange={(e) => setChecklistInput(e.target.value)}
                    onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addChecklistItem(); } }}
                  />
                  <button type="button" onClick={addChecklistItem} className="btn-secondary text-sm">
                    + Добавить
                  </button>
                </div>
              </div>

              {/* Привязать к */}
              <div>
                <label className="label">Привязать к</label>
                <select
                  className="input"
                  value={form.target_type}
                  onChange={(e) => setField("target_type", e.target.value as ActivityTargetType | "")}
                >
                  <option value="">Не привязывать</option>
                  {TARGET_TYPES.map((t) => (
                    <option key={t.value} value={t.value}>{t.label}</option>
                  ))}
                </select>
              </div>

              {/* Теги */}
              <div>
                <label className="label">Теги</label>
                <div className="flex flex-wrap gap-1.5 mb-2">
                  {form.tags.map((tag) => (
                    <span
                      key={tag}
                      className="flex items-center gap-1 bg-primary/10 text-primary text-xs rounded-full px-2.5 py-1"
                    >
                      {tag}
                      <button type="button" onClick={() => removeTag(tag)}>
                        <i className="bi bi-x text-[10px]" />
                      </button>
                    </span>
                  ))}
                </div>
                <div className="flex gap-2">
                  <input
                    className="input flex-1 text-sm"
                    placeholder="Добавить тег..."
                    value={form.tags_input}
                    onChange={(e) => setField("tags_input", e.target.value)}
                    onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addTag(); } }}
                  />
                  <button type="button" onClick={addTag} className="btn-secondary text-sm">
                    Добавить
                  </button>
                </div>
              </div>

              {/* Повторение */}
              <RecurrenceSelector
                rule={form.recurrence_rule}
                until={form.recurrence_until}
                onChange={(rule, until) => {
                  setField("recurrence_rule", rule);
                  setField("recurrence_until", until);
                }}
              />

              {/* FTM секция для встреч */}
              {form.kind === "meeting" && (
                <div className="rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                  <label className="flex items-center gap-2 cursor-pointer select-none">
                    <input
                      type="checkbox"
                      checked={form.is_first_time_meeting}
                      onChange={(e) => setField("is_first_time_meeting", e.target.checked)}
                    />
                    <span className="text-sm font-medium">Это первая встреча с клиентом (FTM)</span>
                    <span className="badge bg-info/10 text-info text-[10px]">FTM</span>
                  </label>
                  {form.is_first_time_meeting && (
                    <div className="pl-6 space-y-2">
                      <label className="flex items-center gap-2 cursor-pointer text-sm">
                        <input
                          type="checkbox"
                          checked={form.ftm_decision_maker_attended}
                          onChange={(e) => setField("ftm_decision_maker_attended", e.target.checked)}
                        />
                        Decision maker присутствовал
                      </label>
                      <label className="flex items-center gap-2 cursor-pointer text-sm">
                        <input
                          type="checkbox"
                          checked={form.ftm_presentation_shown}
                          onChange={(e) => setField("ftm_presentation_shown", e.target.checked)}
                        />
                        Презентация показана
                      </label>
                      <div>
                        <label className="label">Отчёт (ссылка)</label>
                        <input
                          className="input"
                          placeholder="https://..."
                          value={form.ftm_report_url}
                          onChange={(e) => setField("ftm_report_url", e.target.value)}
                        />
                        <p className="text-xs text-gray-500 mt-1">
                          Ссылка на отчёт в AmoCRM или внутренней системе
                        </p>
                      </div>
                    </div>
                  )}
                </div>
              )}
            </div>
          )}
        </div>

        {/* Error */}
        {error && (
          <div className="px-6 py-2">
            <div className="text-danger text-sm bg-danger/10 rounded px-3 py-2">{error}</div>
          </div>
        )}

        {/* Footer */}
        <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 flex items-center justify-end gap-2">
          <button type="button" className="btn-ghost" onClick={tryClose}>Отмена</button>
          <button
            type="button"
            className="btn-secondary"
            disabled={submitting}
            onClick={() => submit(true)}
          >
            Создать и открыть
          </button>
          <button
            type="button"
            className="btn-primary"
            disabled={submitting}
            onClick={() => submit(false)}
          >
            {submitting ? "Создаём…" : "Создать"}
          </button>
        </div>
      </div>
    </div>,
    document.body
  );
}
