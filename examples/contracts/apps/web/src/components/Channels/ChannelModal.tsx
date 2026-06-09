"use client";

import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { UserSelect } from "@/components/UserSelect";
import { api, ApiError, fetcher } from "@/lib/api";
import {
  CHANNEL_KIND_OPTIONS,
  type Channel,
  type ChannelKind,
  type Pipeline,
  type PipelineStage,
} from "@/lib/types";

interface Props {
  open: boolean;
  channel: Channel | null;
  onClose: () => void;
  onSaved: (channel: Channel, isNew: boolean) => void;
}

// Список «магических» source-значений, валидируемых backend'ом.
const LEAD_SOURCE_OPTIONS: { value: string; label: string }[] = [
  { value: "tg", label: "Telegram (tg)" },
  { value: "wa", label: "WhatsApp (wa)" },
  { value: "email", label: "Email" },
  { value: "form", label: "Веб-форма (form)" },
  { value: "api", label: "API" },
  { value: "manual", label: "Вручную (manual)" },
  { value: "import", label: "Импорт (import)" },
];

type FormState = {
  name: string;
  kind: ChannelKind;
  is_active: boolean;
  default_lead_source: string;
  default_owner_id: string;
  default_pipeline_id: string;
  default_stage_id: string;
  // config: ввод JSON или поля-конфиги в зависимости от kind
  config_json: string;
};

function fromChannel(ch: Channel): FormState {
  return {
    name: ch.name,
    kind: ch.kind,
    is_active: ch.is_active,
    default_lead_source: ch.default_lead_source,
    default_owner_id: ch.default_owner_id ? String(ch.default_owner_id) : "",
    default_pipeline_id: ch.default_pipeline_id ? String(ch.default_pipeline_id) : "",
    default_stage_id: ch.default_stage_id ? String(ch.default_stage_id) : "",
    config_json: ch.config ? JSON.stringify(ch.config, null, 2) : "{}",
  };
}

function emptyForm(): FormState {
  return {
    name: "",
    kind: "api",
    is_active: true,
    default_lead_source: "api",
    default_owner_id: "",
    default_pipeline_id: "",
    default_stage_id: "",
    config_json: "{}",
  };
}

const KIND_CONFIG_HINT: Record<ChannelKind, string> = {
  tg: '{"bot_token": "...", "chat_id": null}',
  wa: '{"wa_phone_id": "...", "wa_token": "..."}',
  email: '{"imap_host": "...", "imap_user": "...", "imap_password": "..."}',
  web_form: "{}",
  api: "{}",
};

export function ChannelModal({ open, channel, onClose, onSaved }: Props) {
  const isEdit = !!channel;
  const [form, setForm] = useState<FormState>(emptyForm());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // На create — после успешного POST показываем secret_token явно
  const [createdSecret, setCreatedSecret] = useState<string | null>(null);

  const { data: pipelines } = useSWR<Pipeline[]>(open ? "/pipelines" : null, fetcher);
  const { data: stages } = useSWR<PipelineStage[]>(
    open && form.default_pipeline_id
      ? `/pipelines/${form.default_pipeline_id}/stages`
      : null,
    fetcher,
  );

  useEffect(() => {
    if (!open) return;
    setError(null);
    setSaving(false);
    setCreatedSecret(null);
    setForm(channel ? fromChannel(channel) : emptyForm());
  }, [open, channel]);

  // При смене pipeline — сбрасываем stage
  function setPipeline(pid: string) {
    setForm((prev) => ({
      ...prev,
      default_pipeline_id: pid,
      default_stage_id: "",
    }));
  }

  const visibleStages = useMemo(
    () => (stages ?? []).filter((s) => s.is_active).sort((a, b) => a.sort_order - b.sort_order),
    [stages],
  );

  function validateConfigJson(): { ok: true; value: Record<string, unknown> } | { ok: false; error: string } {
    const raw = form.config_json.trim();
    if (!raw) return { ok: true, value: {} };
    try {
      const parsed = JSON.parse(raw);
      if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) {
        return { ok: false, error: "Конфиг должен быть JSON-объектом" };
      }
      return { ok: true, value: parsed as Record<string, unknown> };
    } catch (e) {
      return { ok: false, error: `Ошибка JSON: ${(e as Error).message}` };
    }
  }

  async function save() {
    if (!form.name.trim()) {
      setError("Укажите название канала");
      return;
    }
    const cfg = validateConfigJson();
    if (!cfg.ok) {
      setError(cfg.error);
      return;
    }
    setSaving(true);
    setError(null);

    const body = {
      name: form.name.trim(),
      kind: form.kind,
      config: cfg.value,
      default_lead_source: form.default_lead_source || null,
      default_owner_id: form.default_owner_id ? Number(form.default_owner_id) : null,
      default_pipeline_id: form.default_pipeline_id ? Number(form.default_pipeline_id) : null,
      default_stage_id: form.default_stage_id ? Number(form.default_stage_id) : null,
      is_active: form.is_active,
    };

    try {
      if (channel) {
        const updated = await api<Channel>(`/channels/${channel.id}`, {
          method: "PATCH",
          body,
        });
        onSaved(updated, false);
        onClose();
      } else {
        const created = await api<Channel>("/channels", { method: "POST", body });
        // НЕ закрываем сразу — показываем полный secret_token (приходит только
        // на create, C4 CRIT-3). Фолбэк на маску маловероятен, но типобезопасен.
        setCreatedSecret(created.secret_token ?? created.secret_token_preview);
        onSaved(created, true);
      }
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось сохранить",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? "Редактирование канала" : "Новый канал"}
      width="lg"
      footer={
        createdSecret ? (
          <button className="btn-primary" onClick={onClose}>Готово</button>
        ) : (
          <>
            <button className="btn-secondary" onClick={onClose}>Отмена</button>
            <button className="btn-primary" onClick={save} disabled={saving}>
              {saving ? "Сохранение…" : isEdit ? "Сохранить" : "Создать"}
            </button>
          </>
        )
      }
    >
      <div className="space-y-3">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        {createdSecret && (
          <div className="bg-success/15 border border-success/40 rounded-md p-4 space-y-2">
            <div className="text-sm font-semibold text-gray-900">
              <i className="bi bi-check-circle text-success mr-1" /> Канал создан
            </div>
            <div className="text-sm text-gray-700">
              Сохраните этот токен — он понадобится для отправки webhook'ов (заголовок
              <code className="mx-1 bg-white px-1 rounded">X-Channel-Token</code>):
            </div>
            <div className="flex items-center gap-2">
              <code className="font-mono bg-white border border-gray-300 px-2 py-1 rounded text-xs break-all flex-1">
                {createdSecret}
              </code>
              <button
                className="btn-ghost text-xs"
                onClick={async () => {
                  try {
                    await navigator.clipboard.writeText(createdSecret);
                  } catch {
                    alert(createdSecret);
                  }
                }}
              >
                <i className="bi bi-copy" /> Копировать
              </button>
            </div>
            <div className="text-xs text-gray-600">
              Токен также всегда доступен в таблице каналов и может быть перегенерирован.
            </div>
          </div>
        )}

        {!createdSecret && (
          <>
            <Field
              label="Название"
              value={form.name}
              onChange={(v) => setForm({ ...form, name: v })}
              required
              placeholder="Например: TG-бот «Продажи»"
            />

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Тип канала <span className="text-danger">*</span></label>
                <select
                  className="input"
                  value={form.kind}
                  onChange={(e) => setForm({ ...form, kind: e.target.value as ChannelKind })}
                  disabled={isEdit}
                >
                  {CHANNEL_KIND_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                  ))}
                </select>
                {isEdit && (
                  <div className="text-xs text-gray-500 mt-1">Тип нельзя поменять у созданного канала</div>
                )}
              </div>
              <div className="flex items-end">
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={form.is_active}
                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                  />
                  Активен
                </label>
              </div>
            </div>

            <div>
              <label className="label">Источник лида (default_lead_source)</label>
              <select
                className="input"
                value={form.default_lead_source}
                onChange={(e) => setForm({ ...form, default_lead_source: e.target.value })}
              >
                {LEAD_SOURCE_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
              <div className="text-xs text-gray-500 mt-1">
                Используется в <code>Lead.source</code> при автогенерации.
              </div>
            </div>

            <div>
              <label className="label">Ответственный по умолчанию</label>
              <UserSelect
                value={form.default_owner_id}
                onChange={(v) => setForm({ ...form, default_owner_id: v })}
                placeholder="— не задан —"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Воронка по умолчанию</label>
                <select
                  className="input"
                  value={form.default_pipeline_id}
                  onChange={(e) => setPipeline(e.target.value)}
                >
                  <option value="">— автоопределение (Lead pipeline) —</option>
                  {(pipelines ?? []).map((p) => (
                    <option key={p.id} value={p.id}>
                      {p.name} ({p.kind})
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label className="label">Этап по умолчанию</label>
                <select
                  className="input"
                  value={form.default_stage_id}
                  onChange={(e) => setForm({ ...form, default_stage_id: e.target.value })}
                  disabled={!form.default_pipeline_id}
                >
                  <option value="">— первый активный —</option>
                  {visibleStages.map((s) => (
                    <option key={s.id} value={s.id}>{s.name}</option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label className="label">
                Конфиг канала (JSON)
              </label>
              <textarea
                className="input min-h-[120px] font-mono text-xs"
                value={form.config_json}
                onChange={(e) => setForm({ ...form, config_json: e.target.value })}
                placeholder={KIND_CONFIG_HINT[form.kind]}
              />
              <div className="text-xs text-gray-500 mt-1">
                Произвольный JSON-объект с настройками канала.
                Пример для типа «{form.kind}»: <code className="text-[10px]">{KIND_CONFIG_HINT[form.kind]}</code>
              </div>
            </div>

            {isEdit && channel && (
              <div className="text-xs text-gray-500 border-t border-gray-200 pt-3 mt-3">
                Создан: {new Date(channel.created_at).toLocaleString("ru-RU")}
                {" · "}
                Обновлён: {new Date(channel.updated_at).toLocaleString("ru-RU")}
                {" · "}
                ID: #{channel.id}
              </div>
            )}
          </>
        )}
      </div>
    </Modal>
  );
}
