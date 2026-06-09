"use client";

import { useState } from "react";
import { Modal } from "@/components/Modal";
import { api } from "@/lib/api";
import type { NotificationTemplate } from "@/lib/types";

const CHANNEL_LABELS: Record<string, string> = {
  in_app: "В приложении",
  tg: "Telegram",
  email: "Email",
};

interface Props {
  template: NotificationTemplate;
  kindLabel: string;
  onClose: () => void;
  onSaved: () => void;
}

export function TemplateEditModal({ template, kindLabel, onClose, onSaved }: Props) {
  const [subject, setSubject] = useState(template.subject ?? "");
  const [body, setBody] = useState(template.body_template);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");
  const [preview, setPreview] = useState("");
  const [previewLoading, setPreviewLoading] = useState(false);

  const isDirty = subject !== (template.subject ?? "") || body !== template.body_template;

  async function handleSave(): Promise<boolean> {
    setSaving(true);
    setSaveError("");
    try {
      await api(`/admin/notification-templates/${template.id}`, {
        method: "PATCH",
        body: { subject, body_template: body },
      });
      onSaved();
      return true;
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Не удалось сохранить";
      setSaveError(msg);
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function handlePreview() {
    setPreviewLoading(true);
    setPreview("");
    try {
      const res = await api<{ rendered: string }>(
        `/admin/notification-templates/${template.id}/preview`,
        { method: "POST", body: {} },
      );
      setPreview(res.rendered);
    } catch {
      setPreview("Не удалось получить предпросмотр");
    } finally {
      setPreviewLoading(false);
    }
  }

  return (
    <Modal
      open
      title="Редактировать шаблон"
      onClose={onClose}
      onTrySave={handleSave}
      isDirty={isDirty}
      width="lg"
      footer={
        <>
          <button type="button" className="btn-ghost" onClick={onClose}>
            Отмена
          </button>
          <button
            type="button"
            className="btn-primary"
            disabled={saving}
            onClick={() => void handleSave().then((ok) => ok && onClose())}
          >
            {saving ? "Сохраняем…" : "Сохранить"}
          </button>
        </>
      }
    >
      <div className="space-y-4">
        {/* Read-only meta */}
        <div className="flex flex-wrap gap-2">
          <span className="badge bg-gray-100 dark:bg-gray-700 text-xs">{kindLabel}</span>
          <span className="badge bg-gray-100 dark:bg-gray-700 text-xs">
            {CHANNEL_LABELS[template.channel] ?? template.channel}
          </span>
          <span className="badge bg-gray-100 dark:bg-gray-700 text-xs uppercase">
            {template.locale}
          </span>
        </div>

        {/* Subject */}
        <div>
          <label className="label">Заголовок / тема</label>
          <textarea
            rows={2}
            className="input w-full"
            value={subject}
            onChange={(e) => setSubject(e.target.value)}
          />
        </div>

        {/* Body */}
        <div>
          <label className="label">Тело шаблона (Jinja2)</label>
          <textarea
            rows={10}
            className="input w-full font-mono text-sm resize-y"
            placeholder="Используй {{ variable_name }} для подстановки переменных"
            value={body}
            onChange={(e) => setBody(e.target.value)}
          />
        </div>

        {/* Variables */}
        {template.variables.length > 0 && (
          <div>
            <p className="label mb-2">Доступные переменные:</p>
            <div className="flex flex-wrap gap-1.5">
              {template.variables.map((v) => (
                <span
                  key={v}
                  className="bg-gray-100 dark:bg-gray-700 text-xs font-mono px-1.5 py-0.5 rounded"
                >
                  {`{{ ${v} }}`}
                </span>
              ))}
            </div>
          </div>
        )}

        {/* Preview */}
        <div>
          <button
            type="button"
            className="btn-secondary text-sm"
            disabled={previewLoading}
            onClick={() => void handlePreview()}
          >
            {previewLoading ? (
              <><i className="bi-arrow-clockwise animate-spin mr-1" />Загрузка…</>
            ) : (
              "Предпросмотр"
            )}
          </button>
          {preview && (
            <pre className="bg-gray-50 dark:bg-gray-800 rounded p-3 text-xs overflow-auto max-h-40 mt-3 whitespace-pre-wrap">
              {preview}
            </pre>
          )}
        </div>

        {saveError && <p className="text-danger text-xs">{saveError}</p>}
      </div>
    </Modal>
  );
}
