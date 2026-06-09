"use client";

import { useState } from "react";
import { UserSelect } from "@/components/UserSelect";
import type { TaskCategory } from "@/lib/types";

interface ChecklistDraft {
  _id?: string;
  text: string;
}

interface FormState {
  name: string;
  description_template: string;
  color: string;
  default_executor_id: string;
  default_co_executor_ids: string[];
  default_auditor_ids: string[];
  default_observer_ids: string[];
  checklist_template_items: ChecklistDraft[];
  required_files_count: string;
  restrict_close_without_result: boolean;
  auto_title_from_category: boolean;
  sort_order: string;
  is_active: boolean;
}

interface Props {
  initial?: TaskCategory | null;
  onSubmit: (data: Partial<TaskCategory>) => Promise<void>;
  onCancel: () => void;
  submitting: boolean;
}

function toForm(cat?: TaskCategory | null): FormState {
  if (!cat) {
    return {
      name: "",
      description_template: "",
      color: "#172747",
      default_executor_id: "",
      default_co_executor_ids: [],
      default_auditor_ids: [],
      default_observer_ids: [],
      checklist_template_items: [],
      required_files_count: "0",
      restrict_close_without_result: false,
      auto_title_from_category: false,
      sort_order: "0",
      is_active: true,
    };
  }
  return {
    name: cat.name,
    description_template: cat.description_template ?? "",
    color: cat.color ?? "#172747",
    default_executor_id: String(cat.default_executor_id ?? ""),
    default_co_executor_ids: cat.default_co_executor_ids.map(String),
    default_auditor_ids: cat.default_auditor_ids.map(String),
    default_observer_ids: cat.default_observer_ids.map(String),
    checklist_template_items: cat.checklist_template_items.map((i) => ({ text: i.text })),
    required_files_count: String(cat.required_files_count),
    restrict_close_without_result: cat.restrict_close_without_result,
    auto_title_from_category: cat.auto_title_from_category,
    sort_order: String(cat.sort_order),
    is_active: cat.is_active,
  };
}

export function TaskCategoryForm({ initial, onSubmit, onCancel, submitting }: Props) {
  const [form, setForm] = useState<FormState>(() => toForm(initial));
  const [checklistInput, setChecklistInput] = useState("");

  function setField<K extends keyof FormState>(k: K, v: FormState[K]) {
    setForm((f) => ({ ...f, [k]: v }));
  }

  function addChecklist() {
    if (!checklistInput.trim()) return;
    setField("checklist_template_items", [...form.checklist_template_items, { _id: crypto.randomUUID(), text: checklistInput.trim() }]);
    setChecklistInput("");
  }

  function removeChecklist(idx: number) {
    setField("checklist_template_items", form.checklist_template_items.filter((_, i) => i !== idx));
  }

  function addMultiUser(field: "default_co_executor_ids" | "default_auditor_ids" | "default_observer_ids", userId: string) {
    if (!userId || form[field].includes(userId)) return;
    setField(field, [...form[field], userId]);
  }

  function removeMultiUser(field: "default_co_executor_ids" | "default_auditor_ids" | "default_observer_ids", userId: string) {
    setField(field, form[field].filter((id) => id !== userId));
  }

  async function handleSubmit() {
    const data: Partial<TaskCategory> = {
      name: form.name.trim(),
      description_template: form.description_template.trim() || null,
      color: form.color || null,
      default_executor_id: form.default_executor_id ? Number(form.default_executor_id) : null,
      default_co_executor_ids: form.default_co_executor_ids.map(Number),
      default_auditor_ids: form.default_auditor_ids.map(Number),
      default_observer_ids: form.default_observer_ids.map(Number),
      checklist_template_items: form.checklist_template_items.map((i, idx) => ({ text: i.text, sort_order: idx })),
      required_files_count: Number(form.required_files_count) || 0,
      restrict_close_without_result: form.restrict_close_without_result,
      auto_title_from_category: form.auto_title_from_category,
      sort_order: Number(form.sort_order) || 0,
      is_active: form.is_active,
    };
    await onSubmit(data);
  }

  function MultiUserField({
    label,
    field,
  }: {
    label: string;
    field: "default_co_executor_ids" | "default_auditor_ids" | "default_observer_ids";
  }) {
    return (
      <div>
        <label className="label">{label}</label>
        <div className="flex flex-wrap gap-2 mb-2">
          {form[field].map((uid) => (
            <span
              key={uid}
              className="flex items-center gap-1 bg-gray-100 dark:bg-gray-700 rounded-full px-3 py-1 text-sm"
            >
              ID: {uid}
              <button
                type="button"
                onClick={() => removeMultiUser(field, uid)}
                className="ml-0.5"
              >
                <i className="bi bi-x text-xs" />
              </button>
            </span>
          ))}
        </div>
        <UserSelect
          value=""
          onChange={(v) => addMultiUser(field, v)}
          placeholder="+ Добавить"
          className="input text-sm"
        />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      {/* Название */}
      <div>
        <label className="label">Название *</label>
        <input
          className="input"
          value={form.name}
          onChange={(e) => setField("name", e.target.value)}
          placeholder="Название категории"
        />
      </div>

      {/* Описание-шаблон */}
      <div>
        <label className="label">Описание-шаблон</label>
        <textarea
          className="input min-h-[80px]"
          value={form.description_template}
          onChange={(e) => setField("description_template", e.target.value)}
          placeholder="Шаблон описания новых задач этой категории..."
        />
      </div>

      {/* Цвет */}
      <div>
        <label className="label">Цвет категории</label>
        <div className="flex items-center gap-3">
          <input
            type="color"
            className="w-10 h-10 rounded cursor-pointer border border-gray-200 dark:border-gray-700"
            value={form.color}
            onChange={(e) => setField("color", e.target.value)}
          />
          <span className="text-sm text-gray-500">{form.color}</span>
        </div>
      </div>

      <hr className="border-gray-100 dark:border-gray-700" />
      <div className="text-sm font-semibold text-gray-700 dark:text-gray-300">Участники по умолчанию</div>

      {/* Ответственный */}
      <div>
        <label className="label">Ответственный</label>
        <UserSelect
          value={form.default_executor_id}
          onChange={(v) => setField("default_executor_id", v)}
          placeholder="Не назначен"
        />
      </div>

      <MultiUserField label="Соисполнители" field="default_co_executor_ids" />
      <MultiUserField label="Аудиторы" field="default_auditor_ids" />
      <MultiUserField label="Наблюдатели" field="default_observer_ids" />

      <hr className="border-gray-100 dark:border-gray-700" />
      <div className="text-sm font-semibold text-gray-700 dark:text-gray-300">Чек-лист по умолчанию</div>

      <div className="space-y-1.5">
        {form.checklist_template_items.map((item, idx) => (
          <div key={item._id ?? idx} className="flex items-center gap-2 px-3 py-2 rounded border border-gray-200 dark:border-gray-700">
            <i className="bi bi-grip-vertical text-gray-400 text-sm" />
            <span className="flex-1 text-sm text-gray-700 dark:text-gray-300">{item.text}</span>
            <button type="button" onClick={() => removeChecklist(idx)} className="text-gray-400 hover:text-danger p-0.5">
              <i className="bi bi-x text-sm" />
            </button>
          </div>
        ))}
        <div className="flex gap-2">
          <input
            className="input flex-1 text-sm"
            placeholder="Название пункта..."
            value={checklistInput}
            onChange={(e) => setChecklistInput(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); addChecklist(); } }}
          />
          <button type="button" onClick={addChecklist} className="btn-secondary text-sm">
            + Добавить пункт
          </button>
        </div>
      </div>

      <hr className="border-gray-100 dark:border-gray-700" />
      <div className="text-sm font-semibold text-gray-700 dark:text-gray-300">Ограничения</div>

      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="label">Минимум файлов для закрытия</label>
          <input
            type="number"
            min="0"
            className="input"
            value={form.required_files_count}
            onChange={(e) => setField("required_files_count", e.target.value)}
          />
        </div>
        <div>
          <label className="label">Порядок сортировки</label>
          <input
            type="number"
            min="0"
            className="input"
            value={form.sort_order}
            onChange={(e) => setField("sort_order", e.target.value)}
          />
        </div>
      </div>

      <div className="space-y-2">
        <label className="flex items-center gap-2 cursor-pointer text-sm">
          <input
            type="checkbox"
            checked={form.restrict_close_without_result}
            onChange={(e) => setField("restrict_close_without_result", e.target.checked)}
            className="w-4 h-4"
          />
          Запрещать закрытие без указания результата
        </label>
        <label className="flex items-center gap-2 cursor-pointer text-sm">
          <input
            type="checkbox"
            checked={form.auto_title_from_category}
            onChange={(e) => setField("auto_title_from_category", e.target.checked)}
            className="w-4 h-4"
          />
          Автоматически заполнять название из шаблона
        </label>
        <label className="flex items-center gap-2 cursor-pointer text-sm">
          <input
            type="checkbox"
            checked={form.is_active}
            onChange={(e) => setField("is_active", e.target.checked)}
            className="w-4 h-4"
          />
          Активна
        </label>
      </div>

      {/* Footer */}
      <div className="flex justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-700">
        <button type="button" className="btn-ghost" onClick={onCancel}>
          Отмена
        </button>
        <button
          type="button"
          className="btn-primary"
          disabled={!form.name.trim() || submitting}
          onClick={handleSubmit}
        >
          {submitting ? "Сохранение..." : "Сохранить"}
        </button>
      </div>
    </div>
  );
}
