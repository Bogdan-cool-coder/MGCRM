"use client";

import Link from "next/link";
import useSWR from "swr";
import { useEffect, useRef, useState } from "react";
import { fetcher } from "@/lib/api";
import type { ContractStatusGroupsResponse } from "@/lib/types";

const GROUP_ICONS: Record<string, string> = {
  archived_group:  "bi-archive",
  draft_group:     "bi-file-earmark",
  in_review_group: "bi-hourglass-split",
  approved_group:  "bi-check-circle",
};

const GROUP_SURFACES: Record<string, string> = {
  draft_group:     "bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10",
  in_review_group: "bg-warning-50 dark:bg-warning-500/10 border border-warning-200 dark:border-warning-500/20",
  approved_group:  "bg-success-50 dark:bg-success-500/10 border border-success-200 dark:border-success-500/20",
  archived_group:  "bg-gray-50 dark:bg-white/5 border border-gray-200 dark:border-white/10",
};

const GROUP_NUMBER_COLOR: Record<string, string> = {
  draft_group:     "text-gray-900 dark:text-gray-100",
  in_review_group: "text-warning-700 dark:text-warning-500",
  approved_group:  "text-success-700 dark:text-success-500",
  archived_group:  "text-gray-900 dark:text-gray-100",
};

const GROUP_ICON_BG: Record<string, string> = {
  draft_group:     "bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-400",
  in_review_group: "bg-warning-100 dark:bg-warning-500/20 text-warning-600",
  approved_group:  "bg-success-100 dark:bg-success-500/20 text-success-600",
  archived_group:  "bg-gray-100 dark:bg-white/10 text-gray-600 dark:text-gray-400",
};

/** Простой Number Ticker на RAF (без motion). */
function TickerNumber({ target, colorCls }: { target: number; colorCls: string }) {
  const [current, setCurrent] = useState(0);
  const rafRef = useRef<number | null>(null);

  useEffect(() => {
    const motionOk = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (!motionOk) {
      setCurrent(target);
      return;
    }
    const duration = 900;
    const start = performance.now();

    function step(now: number) {
      const p = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      setCurrent(Math.round(target * eased));
      if (p < 1) {
        rafRef.current = requestAnimationFrame(step);
      }
    }

    rafRef.current = requestAnimationFrame(step);
    return () => {
      if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [target]);

  return (
    <div className={`text-2xl font-bold tabular-nums mt-1 ${colorCls}`}>
      {current}
    </div>
  );
}

interface Props {
  /** Код группы: archived_group | draft_group | in_review_group | approved_group */
  groupCode: string;
  byStatusGroup: Record<string, number>;
  byStatus: Record<string, number>;
}

/**
 * Один тайл статус-группы (Design v2).
 * Загружает метаданные группы самостоятельно через SWR (кешируется).
 */
export function StatusSingleTile({ groupCode, byStatusGroup, byStatus }: Props) {
  const { data } = useSWR<ContractStatusGroupsResponse>("/contracts/status-groups", fetcher);
  const group = data?.groups.find((g) => g.code === groupCode);

  // Skeleton пока нет данных
  if (!group) {
    return (
      <div
        className="rounded-2xl h-full min-h-[110px] animate-pulse bg-gray-100 dark:bg-gray-700"
        aria-busy="true"
        aria-label="Загружаем данные"
      />
    );
  }

  const count = byStatusGroup[group.code] ?? 0;
  const surface = GROUP_SURFACES[group.code] ?? GROUP_SURFACES.draft_group;
  const numColor = GROUP_NUMBER_COLOR[group.code] ?? "text-gray-900 dark:text-gray-100";
  const iconStyle = GROUP_ICON_BG[group.code] ?? GROUP_ICON_BG.draft_group;
  const icon = GROUP_ICONS[group.code] ?? "bi-tag";

  let sub: string | null = null;
  if (group.code === "in_review_group") {
    const nr = byStatus.needs_rework ?? 0;
    const rj = byStatus.rejected ?? 0;
    sub = `на доработке ${nr} · отклонён ${rj}`;
  } else if (group.code === "approved_group") {
    const up = byStatus.uploaded ?? 0;
    sub = `в Drive ${up}`;
  }

  return (
    <Link
      href={`/contracts?status_group=${group.code}`}
      className={`rounded-2xl shadow-elev-1 hover:shadow-elev-2 lift p-5 block h-full transition ${surface}`}
    >
      <div className="flex items-center justify-between mb-2">
        <span className="text-sm font-medium text-gray-700 dark:text-gray-300 leading-tight">{group.label}</span>
        <span className={`h-8 w-8 grid place-items-center rounded-lg shrink-0 ${iconStyle}`}>
          <i className={`bi ${icon} text-sm`} aria-hidden="true" />
        </span>
      </div>
      <TickerNumber target={count} colorCls={numColor} />
      {sub && (
        <div className="text-[11px] text-gray-400 dark:text-gray-500 mt-1 truncate" title={sub}>
          {sub}
        </div>
      )}
    </Link>
  );
}
