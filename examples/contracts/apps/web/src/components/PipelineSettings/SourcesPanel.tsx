"use client";

import { useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { Modal } from "@/components/Modal";
import type { PipelineSettings, PipelineChannel } from "@/lib/types";

const DUP_FIELD_OPTIONS: { value: string; label: string }[] = [
  { value: "name", label: "Название компании" },
  { value: "phone", label: "Телефон" },
  { value: "email", label: "Email" },
  { value: "tax_id", label: "ИНН / Tax ID" },
  { value: "website", label: "Сайт" },
];

interface SourcesPanelProps {
  pipelineId: number;
  onOpenLostReasons: () => void;
  onOpenMeetingQuestions: () => void;
  onOpenStageTasks: () => void;
}

export function SourcesPanel({
  pipelineId,
  onOpenLostReasons,
  onOpenMeetingQuestions,
  onOpenStageTasks,
}: SourcesPanelProps) {
  const settingsKey = `/pipelines/${pipelineId}/settings`;
  const channelsKey = `/pipelines/${pipelineId}/channels`;

  const { data: settings, mutate: mutateSettings } = useSWR<PipelineSettings>(settingsKey, fetcher);
  const { data: channels, mutate: mutateChannels } = useSWR<PipelineChannel[]>(channelsKey, fetcher);

  const [dupModalOpen, setDupModalOpen] = useState(false);
  const [savingSettings, setSavingSettings] = useState(false);
  const [togglingChannel, setTogglingChannel] = useState<number | null>(null);

  async function patchSettings(patch: Partial<PipelineSettings>) {
    if (!settings) return;
    const optimistic = { ...settings, ...patch };
    await mutateSettings(optimistic, false);
    setSavingSettings(true);
    try {
      const updated = await api<PipelineSettings>(settingsKey, {
        method: "PATCH",
        body: patch,
      });
      await mutateSettings(updated, false);
    } catch {
      await mutateSettings();
    } finally {
      setSavingSettings(false);
    }
  }

  async function toggleChannel(channelId: number, isActive: boolean) {
    setTogglingChannel(channelId);
    try {
      await api(`${channelsKey}`, {
        method: "PATCH",
        body: { channel_id: channelId, is_active: isActive },
      });
      await mutateChannels();
    } catch {
      // ignore
    } finally {
      setTogglingChannel(null);
    }
  }

  return (
    <aside className="w-64 shrink-0 border-r border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 flex flex-col overflow-y-auto">
      <div className="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
        <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">
          Источники и настройки
        </h3>
      </div>

      {/* Загрузка */}
      {!settings && (
        <div className="px-4 py-4 space-y-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-8 bg-gray-200 dark:bg-gray-700 rounded animate-pulse" />
          ))}
        </div>
      )}

      {settings && (
        <div className="flex flex-col gap-0 divide-y divide-gray-200 dark:divide-gray-700">
          {/* Auto-assign */}
          <div className="px-4 py-3">
            <div className="flex items-center justify-between gap-2">
              <div>
                <div className="text-sm font-medium text-gray-800 dark:text-gray-200">Неразобранное</div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  Авто-назначение ответственного
                </div>
              </div>
              <Toggle
                checked={settings.auto_assign}
                disabled={savingSettings}
                onChange={(v) => void patchSettings({ auto_assign: v })}
              />
            </div>
          </div>

          {/* Duplicate check */}
          <div className="px-4 py-3">
            <div className="flex items-center justify-between gap-2 mb-2">
              <div>
                <div className="text-sm font-medium text-gray-800 dark:text-gray-200">Контроль дублей</div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  Проверять при создании
                </div>
              </div>
              <Toggle
                checked={settings.duplicate_check_enabled}
                disabled={savingSettings}
                onChange={(v) => void patchSettings({ duplicate_check_enabled: v })}
              />
            </div>
            {settings.duplicate_check_enabled && (
              <button
                className="text-xs text-primary hover:underline"
                onClick={() => setDupModalOpen(true)}
              >
                <i className="bi bi-gear mr-1" />
                Настроить правила
              </button>
            )}
          </div>

          {/* Channels */}
          <div className="px-4 py-3">
            <div className="text-sm font-medium text-gray-800 dark:text-gray-200 mb-2">Каналы</div>
            {!channels && (
              <div className="space-y-1.5">
                {[1, 2].map((i) => (
                  <div key={i} className="h-6 bg-gray-200 dark:bg-gray-700 rounded animate-pulse" />
                ))}
              </div>
            )}
            {channels && channels.length === 0 && (
              <p className="text-xs text-gray-400">
                Нет каналов.{" "}
                <a href="/admin/channels" className="text-primary hover:underline">
                  Создать →
                </a>
              </p>
            )}
            {channels && channels.length > 0 && (
              <div className="space-y-1.5">
                {channels.map((ch) => (
                  <div key={ch.id} className="flex items-center justify-between gap-2">
                    <div className="flex items-center gap-1.5 min-w-0">
                      <ChannelIcon kind={ch.kind} />
                      <span className="text-xs text-gray-700 dark:text-gray-300 truncate">{ch.name}</span>
                    </div>
                    <Toggle
                      checked={ch.linked}
                      disabled={togglingChannel === ch.id}
                      onChange={(v) => void toggleChannel(ch.id, v)}
                      size="sm"
                    />
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Registry links */}
          <div className="px-4 py-3">
            <div className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">
              Реестры
            </div>
            <div className="flex flex-col gap-1.5">
              <button
                className="text-xs text-left text-primary hover:underline flex items-center gap-1"
                onClick={onOpenLostReasons}
              >
                <i className="bi bi-x-circle" />
                Причины отказа
              </button>
              <button
                className="text-xs text-left text-primary hover:underline flex items-center gap-1"
                onClick={onOpenMeetingQuestions}
              >
                <i className="bi bi-chat-square-text" />
                Вопросы встречи
              </button>
              <button
                className="text-xs text-left text-primary hover:underline flex items-center gap-1"
                onClick={onOpenStageTasks}
              >
                <i className="bi bi-clipboard-check" />
                Задачи этапов
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Дублирование — настроить поля */}
      {dupModalOpen && settings && (
        <DupFieldsModal
          fields={settings.duplicate_check_fields}
          onClose={() => setDupModalOpen(false)}
          onSave={async (fields) => {
            await patchSettings({ duplicate_check_fields: fields });
            setDupModalOpen(false);
            void globalMutate(settingsKey);
          }}
        />
      )}
    </aside>
  );
}

// ── Toggle ────────────────────────────────────────────────────────────────────
function Toggle({
  checked,
  onChange,
  disabled,
  size = "md",
}: {
  checked: boolean;
  onChange: (v: boolean) => void;
  disabled?: boolean;
  size?: "sm" | "md";
}) {
  const track = size === "sm" ? "w-8 h-4" : "w-10 h-5";
  const thumb = size === "sm" ? "w-3 h-3 top-0.5 left-0.5" : "w-4 h-4 top-0.5 left-0.5";
  const translate = size === "sm" ? "translate-x-4" : "translate-x-5";

  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={`relative inline-flex shrink-0 rounded-full transition-colors focus:outline-none disabled:opacity-50 ${track} ${checked ? "bg-primary" : "bg-gray-300 dark:bg-gray-600"}`}
    >
      <span
        className={`absolute bg-white rounded-full shadow transition-transform ${thumb} ${checked ? translate : "translate-x-0"}`}
      />
    </button>
  );
}

// ── ChannelIcon ───────────────────────────────────────────────────────────────
function ChannelIcon({ kind }: { kind: string }) {
  const icons: Record<string, string> = {
    telegram: "bi-telegram",
    whatsapp: "bi-whatsapp",
    email: "bi-envelope",
    form: "bi-ui-checks-grid",
    api: "bi-code-slash",
    phone: "bi-telephone",
  };
  return <i className={`bi ${icons[kind] ?? "bi-circle"} text-xs text-gray-500`} />;
}

// ── DupFieldsModal ────────────────────────────────────────────────────────────
function DupFieldsModal({
  fields,
  onClose,
  onSave,
}: {
  fields: string[];
  onClose: () => void;
  onSave: (fields: string[]) => Promise<void>;
}) {
  const [selected, setSelected] = useState<string[]>(fields);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function toggle(value: string) {
    setSelected((prev) =>
      prev.includes(value) ? prev.filter((x) => x !== value) : [...prev, value]
    );
  }

  async function handleSave() {
    if (selected.length === 0) {
      setError("Выберите хотя бы одно поле");
      return;
    }
    setSaving(true);
    setError(null);
    try {
      await onSave(selected);
    } catch (err) {
      setError(err instanceof ApiError ? String((err.detail as { detail?: string })?.detail ?? err.message) : "Ошибка сохранения");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open
      title="Правила контроля дублей"
      onClose={onClose}
      width="sm"
      footer={
        <>
          <button className="btn-ghost" onClick={onClose}>Отмена</button>
          <button className="btn-primary disabled:opacity-50" onClick={handleSave} disabled={saving}>
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </>
      }
    >
      <div className="space-y-3">
        <p className="text-sm text-gray-600 dark:text-gray-400">
          При создании сделки система проверит совпадение по выбранным полям.
        </p>
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}
        <div className="space-y-2">
          {DUP_FIELD_OPTIONS.map((opt) => (
            <label
              key={opt.value}
              className="flex items-center gap-3 cursor-pointer rounded-lg px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700"
            >
              <input
                type="checkbox"
                checked={selected.includes(opt.value)}
                onChange={() => toggle(opt.value)}
                className="w-4 h-4 rounded accent-primary"
              />
              <span className="text-sm text-gray-800 dark:text-gray-200">{opt.label}</span>
            </label>
          ))}
        </div>
      </div>
    </Modal>
  );
}
