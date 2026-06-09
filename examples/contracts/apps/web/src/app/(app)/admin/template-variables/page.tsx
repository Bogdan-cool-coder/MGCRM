"use client";

import { useMemo, useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { FloatingInput, FloatingTextarea } from "@/components/ui/FloatingInput";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  VAR_TYPE_LABELS, VAR_TYPE_OPTIONS,
  type TemplateVariable, type TemplateVariableType,
} from "@/lib/types";

type Form = {
  key: string;
  label: string;
  help_text: string;
  var_type: TemplateVariableType;
  optionsText: string;
  default_value: string;
  required: boolean;
  group: string;
  sort_order: number;
  is_active: boolean;
};

const EMPTY: Form = {
  key: "", label: "", help_text: "", var_type: "text", optionsText: "",
  default_value: "", required: false, group: "", sort_order: 0, is_active: true,
};

const RU_LAT: Record<string, string> = {
  а: "a", б: "b", в: "v", г: "g", д: "d", е: "e", ё: "e", ж: "zh", з: "z", и: "i",
  й: "y", к: "k", л: "l", м: "m", н: "n", о: "o", п: "p", р: "r", с: "s", т: "t",
  у: "u", ф: "f", х: "h", ц: "ts", ч: "ch", ш: "sh", щ: "sch", ъ: "", ы: "y", ь: "",
  э: "e", ю: "yu", я: "ya",
};

function slugifyKey(s: string): string {
  let out = "";
  for (const ch of s.toLowerCase()) {
    if (RU_LAT[ch] !== undefined) out += RU_LAT[ch];
    else if (/[a-z0-9]/.test(ch)) out += ch;
    else out += "_";
  }
  out = out.replace(/_+/g, "_").replace(/^_+|_+$/g, "");
  if (out && /^[0-9]/.test(out)) out = "f_" + out;
  return out.slice(0, 64);
}

function toPayload(f: Form) {
  return {
    key: f.key,
    label: f.label.trim(),
    help_text: f.help_text.trim() || null,
    var_type: f.var_type,
    options: f.var_type === "select"
      ? f.optionsText.split("\n").map((s) => s.trim()).filter(Boolean)
      : [],
    default_value: f.default_value.trim() || null,
    required: f.required,
    group: f.group.trim() || null,
    sort_order: Number(f.sort_order) || 0,
    is_active: f.is_active,
  };
}

// Soft-бейджи
const VAR_TYPE_BADGE: Record<string, string> = {
  text:     "bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300",
  textarea: "bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300",
  number:   "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400",
  date:     "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
  select:   "bg-primary-light/10 text-primary dark:bg-primary/20 dark:text-blue-300",
  checkbox: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
};

function VarTypeBadge({ varType }: { varType: string }) {
  const cls = VAR_TYPE_BADGE[varType] ?? VAR_TYPE_BADGE.text;
  return (
    <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${cls}`}>
      {VAR_TYPE_LABELS[varType as TemplateVariableType] ?? varType}
    </span>
  );
}

export default function TemplateVariablesPage() {
  const { data, mutate } = useSWR<TemplateVariable[]>("/template-variables", fetcher);
  const { toast } = useToast();

  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<TemplateVariable | null>(null);
  const [form, setForm] = useState<Form>(EMPTY);
  const [initial, setInitial] = useState<Form>(EMPTY);
  const [keyDirty, setKeyDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState<string | null>(null);

  const isDirty = useMemo(() => JSON.stringify(form) !== JSON.stringify(initial), [form, initial]);
  const keyValid = /^[a-z][a-z0-9_]*$/.test(form.key);

  function openCreate() {
    setEditing(null);
    setForm(EMPTY);
    setInitial(EMPTY);
    setKeyDirty(false);
    setError(null);
    setOpen(true);
  }

  function openEdit(v: TemplateVariable) {
    const f: Form = {
      key: v.key,
      label: v.label,
      help_text: v.help_text ?? "",
      var_type: v.var_type,
      optionsText: (v.options ?? []).join("\n"),
      default_value: v.default_value ?? "",
      required: v.required,
      group: v.group ?? "",
      sort_order: v.sort_order,
      is_active: v.is_active,
    };
    setEditing(v);
    setForm(f);
    setInitial(f);
    setKeyDirty(true);
    setError(null);
    setOpen(true);
  }

  function setLabel(label: string) {
    setForm((f) => ({
      ...f,
      label,
      key: !editing && !keyDirty ? slugifyKey(label) : f.key,
    }));
  }

  async function save(): Promise<boolean> {
    setSaving(true);
    setError(null);
    try {
      const payload = toPayload(form);
      if (editing) {
        const { key: _key, ...patch } = payload;
        void _key;
        await api(`/template-variables/${editing.id}`, { method: "PATCH", body: patch });
        toast.success("Переменная обновлена");
      } else {
        await api("/template-variables", { method: "POST", body: payload });
        toast.success("Переменная создана");
      }
      await mutate();
      setInitial(form);
      setOpen(false);
      return true;
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка сохранения";
      setError(msg);
      toast.error(msg);
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function remove(v: TemplateVariable) {
    if (!confirm(`Удалить переменную «${v.label}»? Тег {{ custom.${v.key} }} в шаблоне перестанет заполняться.`)) return;
    await api(`/template-variables/${v.id}`, { method: "DELETE" });
    await mutate();
    toast.success(`Переменная «${v.label}» удалена`);
  }

  function copyTag(key: string) {
    const tag = `{{ custom.${key} }}`;
    navigator.clipboard?.writeText(tag);
    setCopied(key);
    setTimeout(() => setCopied((c) => (c === key ? null : c)), 1500);
  }

  const columns: DataTableColumn<TemplateVariable>[] = [
    {
      key: "label",
      header: "Название",
      skeletonWidth: "60%",
      render: (v) => (
        <div>
          <p className="font-medium text-gray-900 dark:text-gray-100">{v.label}</p>
          {v.help_text && (
            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{v.help_text}</p>
          )}
        </div>
      ),
    },
    {
      key: "key",
      header: "Тег для шаблона",
      width: "16rem",
      skeletonWidth: "75%",
      render: (v) => (
        <button
          onClick={(e) => { e.stopPropagation(); copyTag(v.key); }}
          className="font-mono text-xs bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 rounded-lg px-2 py-1 transition-colors"
          title="Скопировать тег"
        >
          {copied === v.key ? (
            <span className="text-success-600 dark:text-success-400">
              <i className="bi bi-check-lg mr-1" />скопировано
            </span>
          ) : (
            <>{`{{ custom.${v.key} }}`}</>
          )}
        </button>
      ),
    },
    {
      key: "var_type",
      header: "Тип",
      width: "8rem",
      skeletonWidth: "60%",
      render: (v) => <VarTypeBadge varType={v.var_type} />,
    },
    {
      key: "group",
      header: "Группа",
      width: "9rem",
      skeletonWidth: "50%",
      render: (v) => (
        <span className="text-sm text-gray-600 dark:text-gray-400">{v.group || "—"}</span>
      ),
    },
    {
      key: "required",
      header: "Обяз.",
      width: "5rem",
      align: "center",
      skeletonWidth: "30%",
      render: (v) =>
        v.required ? (
          <i className="bi bi-check-lg text-success-600 dark:text-success-400" />
        ) : (
          <span className="text-gray-400">—</span>
        ),
    },
    {
      key: "is_active",
      header: "Активна",
      width: "6rem",
      align: "center",
      skeletonWidth: "30%",
      render: (v) =>
        v.is_active ? (
          <span className="inline-flex items-center gap-1 text-xs text-success-600 dark:text-success-400 font-medium">
            <i className="bi bi-circle-fill text-[6px]" /> да
          </span>
        ) : (
          <span className="text-xs text-gray-400">нет</span>
        ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Переменные шаблона"
        description="Произвольные поля, которые юрист добавляет в документ. Появляются в карточке и подставляются в .docx как {{ custom.ключ }}."
        actions={
          <button onClick={openCreate} className="btn-primary">
            <i className="bi bi-plus-lg mr-1" /> Новая переменная
          </button>
        }
      />

      <div className="p-8 space-y-6">
        {/* Информационная плашка */}
        <div className="flex items-start gap-3 rounded-xl border border-info/30 bg-info-50 dark:bg-info-500/10 px-4 py-3 text-sm text-info-700 dark:text-info-400">
          <i className="bi bi-info-circle mt-0.5 shrink-0" />
          <p>
            Как это работает: создайте переменную → скопируйте тег{" "}
            <code className="font-mono bg-info-100 dark:bg-info-500/20 px-1 rounded text-xs">
              {"{{ custom.ключ }}"}
            </code>{" "}
            → вставьте в нужное место в <strong>master_skeleton.docx</strong>{" "}
            (страница «Шаблоны» → скачать/загрузить). В карточке договора появится поле для заполнения.
          </p>
        </div>

        <DataTable
          columns={columns}
          rows={data}
          getRowKey={(v) => v.id}
          onRowClick={openEdit}
          rowActions={(v) => (
            <button
              type="button"
              onClick={(e) => { e.stopPropagation(); remove(v); }}
              className="btn-ghost text-sm text-danger"
              title="Удалить"
            >
              <i className="bi bi-trash" />
            </button>
          )}
          emptyIcon="bi-braces"
          emptyTitle="Переменных пока нет"
          emptyText="Добавьте первое произвольное поле для подстановки в договор."
          emptyCta={
            <button onClick={openCreate} className="btn-primary">
              <i className="bi bi-plus-lg mr-1" /> Новая переменная
            </button>
          }
          ariaLabel="Переменные шаблона"
        />
      </div>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        onTrySave={save}
        isDirty={isDirty}
        title={editing ? `Переменная: ${editing.label}` : "Новая переменная"}
        description={
          editing
            ? `Тег: {{ custom.${editing.key} }} • ключ менять нельзя`
            : "Юрист задаёт поле, менеджер заполняет в карточке договора"
        }
        width="lg"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setOpen(false)}>Отмена</button>
            <button
              onClick={save}
              disabled={saving || !form.label.trim() || !keyValid || !isDirty}
              className="btn-primary"
            >
              {saving ? "Сохранение…" : editing ? "Сохранить" : "Создать"}
            </button>
          </>
        }
      >
        {error && (
          <div className="text-danger text-sm bg-danger/10 dark:bg-danger-500/10 px-3 py-2 rounded-lg mb-4 flex items-center gap-2">
            <i className="bi bi-exclamation-triangle shrink-0" />
            {error}
          </div>
        )}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <FloatingInput
            label="Название поля"
            required
            value={form.label}
            onChange={(e) => setLabel(e.target.value)}
          />
          <div>
            <FloatingInput
              label="Ключ (тег в шаблоне)"
              required
              value={form.key}
              onChange={(e) => { setKeyDirty(true); setForm((f) => ({ ...f, key: e.target.value })); }}
              disabled={!!editing}
              error={form.key && !keyValid ? "Латиница, цифры, _, начинается с буквы" : undefined}
              hint={
                !editing
                  ? "Подставится из названия автоматически"
                  : "Ключ нельзя менять после создания"
              }
            />
            {form.key && keyValid && (
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-1">
                В шаблон:{" "}
                <code className="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">
                  {`{{ custom.${form.key} }}`}
                </code>
              </p>
            )}
          </div>

          <div>
            <label className="label">Тип поля</label>
            <select
              className="input"
              value={form.var_type}
              onChange={(e) => setForm((f) => ({ ...f, var_type: e.target.value as TemplateVariableType }))}
            >
              {VAR_TYPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>

          <FloatingInput
            label="Группа (секция в форме)"
            value={form.group}
            onChange={(e) => setForm((f) => ({ ...f, group: e.target.value }))}
            hint="Поля с одинаковой группой объединяются в блок"
          />

          {form.var_type === "select" && (
            <div className="md:col-span-2">
              <FloatingTextarea
                label="Варианты списка (по одному в строке)"
                value={form.optionsText}
                onChange={(e) => setForm((f) => ({ ...f, optionsText: e.target.value }))}
                rows={4}
              />
            </div>
          )}

          {form.var_type !== "checkbox" && (
            <FloatingInput
              label="Значение по умолчанию"
              value={form.default_value}
              onChange={(e) => setForm((f) => ({ ...f, default_value: e.target.value }))}
              hint="Подставится, если поле оставят пустым"
            />
          )}
          {form.var_type === "checkbox" && (
            <div>
              <label className="label">Значение по умолчанию</label>
              <select
                className="input"
                value={form.default_value === "true" ? "true" : "false"}
                onChange={(e) => setForm((f) => ({ ...f, default_value: e.target.value }))}
              >
                <option value="false">Нет (не отмечено)</option>
                <option value="true">Да (отмечено)</option>
              </select>
            </div>
          )}

          <FloatingInput
            label="Порядок сортировки"
            type="number"
            inputMode="numeric"
            value={String(form.sort_order)}
            onChange={(e) => setForm((f) => ({ ...f, sort_order: Number(e.target.value) || 0 }))}
            hint="Меньше — выше в форме"
          />

          <div className="md:col-span-2">
            <FloatingInput
              label="Подсказка для менеджера"
              value={form.help_text}
              onChange={(e) => setForm((f) => ({ ...f, help_text: e.target.value }))}
            />
          </div>

          <label className="flex items-center gap-2.5 text-sm text-gray-800 dark:text-gray-200 cursor-pointer py-1">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700"
              checked={form.required}
              onChange={(e) => setForm((f) => ({ ...f, required: e.target.checked }))}
            />
            Обязательное (без него нельзя сгенерировать)
          </label>
          <label className="flex items-center gap-2.5 text-sm text-gray-800 dark:text-gray-200 cursor-pointer py-1">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700"
              checked={form.is_active}
              onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
            />
            Активна (показывать в карточках договоров)
          </label>
        </div>
      </Modal>
    </>
  );
}
