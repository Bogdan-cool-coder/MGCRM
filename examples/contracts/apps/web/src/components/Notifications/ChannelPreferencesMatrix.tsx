"use client";

import { useState } from "react";
import type { NotificationChannelPreference, NotificationChannel } from "@/lib/types";

// ── Группировка kind-ов по доменам ──────────────────────────────────────

interface KindGroup {
  label: string;
  kinds: Array<{ kind: string; label: string }>;
}

const GROUPS: KindGroup[] = [
  {
    label: "Задачи",
    kinds: [
      { kind: "task_assigned", label: "Назначена задача" },
      { kind: "task_status_changed", label: "Изменён статус задачи" },
      { kind: "task_extend_requested", label: "Запрос продления срока" },
    ],
  },
  {
    label: "Сделки",
    kinds: [
      { kind: "deal_won", label: "Выиграна сделка" },
      { kind: "deal_stage_changed", label: "Изменился этап сделки" },
    ],
  },
  {
    label: "Согласования",
    kinds: [{ kind: "approval_needed", label: "Требуется согласование" }],
  },
  {
    label: "SLA",
    kinds: [{ kind: "sla_breach", label: "Нарушен SLA" }],
  },
  {
    label: "Обучение",
    kinds: [
      { kind: "course_assigned", label: "Назначен курс" },
      { kind: "course_completed", label: "Курс завершён" },
    ],
  },
  {
    label: "Договоры",
    kinds: [{ kind: "contract_signed", label: "Подписан договор" }],
  },
  {
    label: "Социальное",
    kinds: [{ kind: "mention", label: "Упоминание" }],
  },
  {
    label: "Системные",
    kinds: [{ kind: "system", label: "Системное сообщение" }],
  },
];

// Группы открытые по умолчанию
const DEFAULT_EXPANDED = new Set(["Задачи", "Сделки"]);

const CHANNELS: { key: NotificationChannel; label: string }[] = [
  { key: "in_app", label: "В приложении" },
  { key: "tg", label: "Telegram" },
  { key: "email", label: "Email" },
];

interface Props {
  preferences: NotificationChannelPreference[];
  onChange: (updated: NotificationChannelPreference[]) => void;
  hasTg: boolean;
  hasEmail: boolean;
}

export function ChannelPreferencesMatrix({ preferences, onChange, hasTg, hasEmail }: Props) {
  const [expanded, setExpanded] = useState<Set<string>>(new Set(DEFAULT_EXPANDED));

  // Нормализуем preferences в удобную Map: "kind:channel" -> is_enabled
  function getEnabled(kind: string, channel: NotificationChannel): boolean {
    const found = preferences.find((p) => p.kind === kind && p.channel === channel);
    return found?.is_enabled ?? true; // default: включено
  }

  function setEnabled(kind: string, channel: NotificationChannel, value: boolean) {
    const next = preferences.filter((p) => !(p.kind === kind && p.channel === channel));
    next.push({ kind, channel, is_enabled: value });
    onChange(next);
  }

  function isChannelAvailable(channel: NotificationChannel): boolean {
    if (channel === "tg") return hasTg;
    if (channel === "email") return hasEmail;
    return true;
  }

  function getChannelTooltip(channel: NotificationChannel): string {
    if (channel === "tg") return "Сначала подключи Telegram";
    if (channel === "email") return "Сначала добавь email в профиле";
    return "";
  }

  function handleBulkEnable(channel: NotificationChannel, enabled: boolean) {
    const allKinds = GROUPS.flatMap((g) => g.kinds.map((k) => k.kind));
    const next = [...preferences];
    for (const kind of allKinds) {
      const idx = next.findIndex((p) => p.kind === kind && p.channel === channel);
      if (idx >= 0) {
        next[idx] = { ...next[idx], is_enabled: enabled };
      } else {
        next.push({ kind, channel, is_enabled: enabled });
      }
    }
    onChange(next);
  }

  function handleBulkDisableAll() {
    const allKinds = GROUPS.flatMap((g) => g.kinds.map((k) => k.kind));
    const allChannels: NotificationChannel[] = ["in_app", "tg", "email"];
    const next = [...preferences];
    for (const kind of allKinds) {
      for (const channel of allChannels) {
        const idx = next.findIndex((p) => p.kind === kind && p.channel === channel);
        if (idx >= 0) {
          next[idx] = { ...next[idx], is_enabled: false };
        } else {
          next.push({ kind, channel, is_enabled: false });
        }
      }
    }
    onChange(next);
  }

  function toggleGroup(label: string) {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(label)) {
        next.delete(label);
      } else {
        next.add(label);
      }
      return next;
    });
  }

  return (
    <div>
      {/* Bulk actions */}
      <div className="flex gap-2 flex-wrap mb-3">
        {CHANNELS.map((ch) => (
          <button
            key={ch.key}
            type="button"
            className="btn-ghost text-xs py-1"
            disabled={!isChannelAvailable(ch.key)}
            title={!isChannelAvailable(ch.key) ? getChannelTooltip(ch.key) : undefined}
            onClick={() => handleBulkEnable(ch.key, true)}
          >
            Вкл. всё {ch.label}
          </button>
        ))}
        <button
          type="button"
          className="btn-ghost text-xs py-1 text-danger hover:text-danger"
          onClick={handleBulkDisableAll}
        >
          Выкл. всё
        </button>
      </div>

      {/* Table */}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[480px] text-sm">
          <thead>
            <tr className="border-b border-gray-200 dark:border-gray-700">
              <th className="text-left py-2 px-4 font-medium text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wide">
                Уведомление
              </th>
              {CHANNELS.map((ch) => (
                <th
                  key={ch.key}
                  className="w-20 text-center py-2 px-2 font-medium text-gray-500 dark:text-gray-400 text-xs uppercase tracking-wide"
                >
                  {ch.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {GROUPS.map((group) => {
              const isOpen = expanded.has(group.label);
              return (
                <>
                  {/* Group header */}
                  <tr key={`group-${group.label}`}>
                    <td
                      colSpan={4}
                      className="py-0"
                    >
                      <button
                        type="button"
                        className="flex items-center justify-between w-full py-2 px-4 bg-gray-50 dark:bg-gray-800 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300 transition-colors"
                        onClick={() => toggleGroup(group.label)}
                      >
                        <span>{group.label}</span>
                        <i
                          className={`bi-chevron-down transition-transform duration-200 ${
                            isOpen ? "rotate-180" : ""
                          }`}
                        />
                      </button>
                    </td>
                  </tr>

                  {/* Group rows */}
                  {isOpen &&
                    group.kinds.map((item) => (
                      <tr
                        key={`${group.label}-${item.kind}`}
                        className="border-b border-gray-100 dark:border-gray-700/50"
                      >
                        <td className="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                          {item.label}
                        </td>
                        {CHANNELS.map((ch) => {
                          const available = isChannelAvailable(ch.key);
                          const checked = getEnabled(item.kind, ch.key);
                          return (
                            <td key={ch.key} className="w-20 text-center py-2.5 px-2">
                              <input
                                type="checkbox"
                                className={`accent-primary w-4 h-4 ${
                                  available
                                    ? "cursor-pointer"
                                    : "opacity-40 cursor-not-allowed"
                                }`}
                                checked={checked}
                                disabled={!available}
                                title={!available ? getChannelTooltip(ch.key) : undefined}
                                onChange={(e) =>
                                  setEnabled(item.kind, ch.key, e.target.checked)
                                }
                              />
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                </>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
