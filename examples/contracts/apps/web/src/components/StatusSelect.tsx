"use client";

import { useEffect, useRef, useState } from "react";
import useSWR from "swr";
import clsx from "clsx";
import { fetcher } from "@/lib/api";
import { StatusLabels, type ContractStatus, type ContractStatusGroupsResponse } from "@/lib/types";

/**
 * Выбор статуса/группы статусов договора (Wave 2b).
 * Источник истины — `GET /api/contracts/status-groups`.
 * value: { group } → фильтр по группе (?status_group=); { status } → по подстатусу (?status=).
 * Пустой объект — «Все статусы».
 */
export interface StatusSelectValue {
  group?: string;
  status?: ContractStatus | string;
}

const GROUP_DOTS: Record<string, string> = {
  archived_group: "bg-gray-600",
  draft_group: "bg-gray-500",
  in_review_group: "bg-warning",
  approved_group: "bg-green-600",
};

export function StatusSelect({
  value, onChange,
}: {
  value: StatusSelectValue;
  onChange: (v: StatusSelectValue) => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const { data } = useSWR<ContractStatusGroupsResponse>("/contracts/status-groups", fetcher);

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    if (open) document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [open]);

  const groups = [...(data?.groups ?? [])].sort((a, b) => a.order - b.order);

  // Подпись текущего выбора
  let selectedLabel: { text: string; dot: string } | null = null;
  if (value.group) {
    const g = groups.find((x) => x.code === value.group);
    if (g) selectedLabel = { text: g.label, dot: GROUP_DOTS[g.code] ?? "bg-gray-500" };
  } else if (value.status) {
    const meta = StatusLabels[value.status as ContractStatus];
    selectedLabel = meta
      ? { text: meta.label, dot: meta.dot }
      : { text: String(value.status), dot: "bg-gray-500" };
  }

  function pickAll() { onChange({}); setOpen(false); }
  function pickGroup(code: string) { onChange({ group: code }); setOpen(false); }
  function pickStatus(status: string) { onChange({ status }); setOpen(false); }

  const isAll = !value.group && !value.status;

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="input flex items-center justify-between text-left"
      >
        {selectedLabel ? (
          <span className="inline-flex items-center gap-1.5 text-sm text-gray-700 dark:text-gray-200">
            <span className={clsx("w-1.5 h-1.5 rounded-full", selectedLabel.dot)} />
            {selectedLabel.text}
          </span>
        ) : (
          <span className="text-gray-700 dark:text-gray-300">Все статусы</span>
        )}
        <i className="bi bi-chevron-down text-gray-500" />
      </button>
      {open && (
        <div className="absolute z-30 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg overflow-hidden max-h-80 overflow-y-auto">
          <button
            type="button"
            onClick={pickAll}
            className={clsx(
              "w-full text-left px-3 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-700",
              isAll && "bg-gray-100 dark:bg-gray-700",
            )}
          >
            Все статусы
          </button>
          {groups.map((g) => (
            <div key={g.code} className="border-t border-gray-100 dark:border-gray-700">
              <button
                type="button"
                onClick={() => pickGroup(g.code)}
                className={clsx(
                  "w-full text-left px-3 py-2 text-sm font-medium hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center gap-2",
                  value.group === g.code && "bg-gray-100 dark:bg-gray-700",
                )}
              >
                <span className={clsx("w-1.5 h-1.5 rounded-full", GROUP_DOTS[g.code] ?? "bg-gray-500")} />
                {g.label}
              </button>
              {g.substatuses.map((s) => {
                const meta = StatusLabels[s.status as ContractStatus];
                return (
                  <button
                    key={s.status}
                    type="button"
                    onClick={() => pickStatus(s.status)}
                    className={clsx(
                      "w-full text-left pl-8 pr-3 py-1.5 text-xs hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center gap-2 text-gray-600 dark:text-gray-400",
                      value.status === s.status && "bg-gray-100 dark:bg-gray-700",
                    )}
                  >
                    <span className={clsx("w-1.5 h-1.5 rounded-full", meta?.dot ?? "bg-gray-400")} />
                    {s.label}
                  </button>
                );
              })}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
