"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { UserSelect } from "@/components/UserSelect";
import { DateTimePicker } from "@/components/ui/DateTimePicker";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  ACTIVITY_KIND_LABELS,
  type Activity,
  type ActivityKind,
  type ActivityTargetType,
  type User,
} from "@/lib/types";
import { useMe } from "@/lib/auth";

interface ActivityFormProps {
  targetType: ActivityTargetType;
  targetId: number;
  /** Если задан — форма редактирует существующую активность */
  editingActivity?: Activity | null;
  onSaved: () => void;
  /** Только для режима редактирования: кнопка «Отмена» */
  onCancel?: () => void;
}

const KIND_ORDER: ActivityKind[] = ["task", "note", "meeting", "call"];

type FormState = {
  kind: ActivityKind;
  title: string;
  body: string;
  due_at_local: string; // datetime-local строка ("YYYY-MM-DDTHH:mm")
  responsible_id: string;
};

/** ISO UTC → "YYYY-MM-DDTHH:mm" для <input type=datetime-local> */
function isoToLocal(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** "YYYY-MM-DDTHH:mm" (local) → ISO UTC, либо null */
function localToIso(local: string): string | null {
  if (!local) return null;
  const d = new Date(local);
  if (isNaN(d.getTime())) return null;
  return d.toISOString();
}

function buildEmpty(me: User | undefined): FormState {
  return {
    kind: "task",
    title: "",
    body: "",
    due_at_local: "",
    responsible_id: me ? String(me.id) : "",
  };
}

function buildFromActivity(a: Activity): FormState {
  return {
    kind: a.kind,
    title: a.title,
    body: a.body ?? "",
    due_at_local: isoToLocal(a.due_at),
    responsible_id: a.responsible_id ? String(a.responsible_id) : "",
  };
}

export function ActivityForm({
  targetType, targetId, editingActivity, onSaved, onCancel,
}: ActivityFormProps) {
  const { user: me } = useMe();
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const isEdit = !!editingActivity;
  const [form, setForm] = useState<FormState>(() => editingActivity ? buildFromActivity(editingActivity) : buildEmpty(me));
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  /** В режиме создания форма «компактная»: только сегмент-выбор kind + title + кнопка.
   *  По фокусу/клику разворачивается полная. В режиме edit — всегда развёрнутая. */
  const [expanded, setExpanded] = useState(isEdit);

  // При смене editingActivity (например, переключение между редактируемыми записями) — пересобираем форму
  useEffect(() => {
    if (editingActivity) {
      setForm(buildFromActivity(editingActivity));
      setExpanded(true);
    }
  }, [editingActivity]);

  // Если в режиме создания и сменился me (загрузился позже) — подставим себя в responsible
  useEffect(() => {
    if (!isEdit && me && !form.responsible_id) {
      setForm((f) => ({ ...f, responsible_id: String(me.id) }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [me]);

  const showDueAt = form.kind === "task" || form.kind === "meeting";
  const showResponsible = form.kind === "task";

  function reset() {
    setForm(buildEmpty(me));
    setError(null);
    setExpanded(false);
  }

  async function submit() {
    if (!form.title.trim()) {
      setError("Укажите заголовок");
      return;
    }
    setSubmitting(true);
    setError(null);
    const body: Record<string, unknown> = {
      title: form.title.trim(),
      body: form.body.trim() || null,
      due_at: showDueAt ? localToIso(form.due_at_local) : null,
      responsible_id: showResponsible && form.responsible_id ? Number(form.responsible_id) : null,
    };
    try {
      if (isEdit && editingActivity) {
        await api(`/activities/${editingActivity.id}`, { method: "PATCH", body });
      } else {
        await api("/activities", {
          method: "POST",
          body: {
            kind: form.kind,
            target_type: targetType,
            target_id: targetId,
            ...body,
          },
        });
        reset();
      }
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить");
    } finally {
      setSubmitting(false);
    }
  }

  function handleCancel() {
    if (isEdit) {
      onCancel?.();
    } else {
      reset();
    }
  }

  return (
    <div className="border border-gray-200 rounded-lg p-3 bg-white space-y-3">
      {error && (
        <div className="text-xs text-danger bg-danger/10 px-2 py-1.5 rounded">
          {error}
        </div>
      )}

      {/* Сегментный switcher по типу */}
      <div className="inline-flex rounded-lg border border-gray-200 overflow-hidden text-sm">
        {KIND_ORDER.map((k) => (
          <button
            key={k}
            type="button"
            onClick={() => setForm({ ...form, kind: k })}
            className={
              form.kind === k
                ? "px-3 py-1.5 bg-primary text-white"
                : "px-3 py-1.5 text-gray-600 hover:bg-gray-50"
            }
          >
            {ACTIVITY_KIND_LABELS[k]}
          </button>
        ))}
      </div>

      <input
        className="input"
        placeholder={
          form.kind === "note" ? "Заметка…" :
          form.kind === "call" ? "О чём звонок…" :
          form.kind === "meeting" ? "Тема встречи…" :
          "Что сделать…"
        }
        value={form.title}
        onChange={(e) => setForm({ ...form, title: e.target.value })}
        onFocus={() => setExpanded(true)}
      />

      {expanded && (
        <>
          <textarea
            className="input min-h-[60px]"
            placeholder="Комментарий, контекст…"
            value={form.body}
            onChange={(e) => setForm({ ...form, body: e.target.value })}
          />

          {(showDueAt || showResponsible) && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
              {showDueAt && (
                <div>
                  <label className="label">Срок</label>
                  <DateTimePicker
                    value={form.due_at_local}
                    onChange={(v) => setForm({ ...form, due_at_local: v })}
                  />
                </div>
              )}
              {showResponsible && (
                <div>
                  <label className="label">Исполнитель</label>
                  <UserSelect
                    value={form.responsible_id}
                    onChange={(v) => setForm({ ...form, responsible_id: v })}
                    users={users}
                    placeholder="—"
                  />
                </div>
              )}
            </div>
          )}
        </>
      )}

      <div className="flex items-center justify-end gap-2">
        {(expanded || isEdit) && (
          <button
            type="button"
            onClick={handleCancel}
            className="btn-ghost text-sm"
            disabled={submitting}
          >
            Отмена
          </button>
        )}
        <button
          type="button"
          onClick={submit}
          className="btn-primary text-sm disabled:opacity-50"
          disabled={submitting || !form.title.trim()}
        >
          {submitting
            ? (isEdit ? "Сохранение…" : "Создание…")
            : (isEdit ? "Сохранить" : "Добавить")}
        </button>
      </div>
    </div>
  );
}
