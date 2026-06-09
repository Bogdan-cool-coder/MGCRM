"use client";

import { useEffect, useMemo, useState } from "react";
import { Combobox } from "@/components/ui/Combobox";
import type { Channel } from "@/lib/types";
import { CHANNEL_KIND_LABELS } from "@/lib/types";

export type InboxFiltersState = {
  channel_id: string;
  has_deal: string; // "", "true", "false"
  q: string;
};

interface Props {
  channels: Channel[] | undefined;
  filters: InboxFiltersState;
  onChange: (next: InboxFiltersState) => void;
}

const HAS_DEAL_OPTIONS = [
  { value: "true",  label: "Есть сделка" },
  { value: "false", label: "Без сделки" },
];

/** Фильтры списка входящих: канал (Combobox), сделка, поиск (debounced 300ms). */
export function InboxFilters({ channels, filters, onChange }: Props) {
  // Локальное состояние для строки поиска, чтобы дебаунсить onChange-callback.
  const [qLocal, setQLocal] = useState(filters.q);

  // Если родитель сбросил фильтры извне — синхронизируем.
  useEffect(() => {
    setQLocal(filters.q);
  }, [filters.q]);

  useEffect(() => {
    const id = setTimeout(() => {
      if (qLocal !== filters.q) onChange({ ...filters, q: qLocal });
    }, 300);
    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qLocal]);

  const channelOptions = useMemo(
    () =>
      (channels ?? []).map((c) => ({
        value: String(c.id),
        label: c.name,
        hint: CHANNEL_KIND_LABELS[c.kind],
      })),
    [channels],
  );

  const dirty = filters.channel_id !== "" || filters.has_deal !== "" || filters.q !== "";

  return (
    <div className="card p-4 flex flex-wrap items-end gap-3 shadow-elev-1 rounded-2xl">
      {/* Фильтр по каналу — Combobox с поиском */}
      <div className="min-w-[220px]">
        <Combobox
          label="Канал"
          value={filters.channel_id || null}
          onChange={(v) => onChange({ ...filters, channel_id: v ?? "" })}
          options={channelOptions}
          placeholder="Все каналы"
          clearable
          isLoading={channels === undefined}
        />
      </div>

      {/* Фильтр привязки к сделке — Combobox */}
      <div className="min-w-[200px]">
        <Combobox
          label="Сделка"
          value={filters.has_deal || null}
          onChange={(v) => onChange({ ...filters, has_deal: v ?? "" })}
          options={HAS_DEAL_OPTIONS}
          placeholder="Все"
          clearable
        />
      </div>

      {/* Поиск */}
      <div className="flex-1 min-w-[260px]">
        <label className="label">Поиск</label>
        <div className="relative">
          <i className="bi bi-search absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400 pointer-events-none" />
          <input
            className="input pl-9"
            placeholder="имя, email, телефон, тема, текст"
            value={qLocal}
            onChange={(e) => setQLocal(e.target.value)}
          />
          {qLocal && (
            <button
              type="button"
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
              onClick={() => {
                setQLocal("");
                onChange({ ...filters, q: "" });
              }}
              aria-label="Очистить поиск"
            >
              <i className="bi bi-x text-base" />
            </button>
          )}
        </div>
      </div>

      {/* Кнопка сброса всех фильтров */}
      {dirty && (
        <button
          className="btn-ghost self-end"
          onClick={() => {
            setQLocal("");
            onChange({ channel_id: "", has_deal: "", q: "" });
          }}
        >
          <i className="bi bi-x-lg mr-1" />
          Сбросить
        </button>
      )}
    </div>
  );
}
