"use client";

/**
 * LifecycleSegments — мини-сегменты B0-B6 / A1-A6 / C0 в таблице реестра.
 *
 * Логика сегментов:
 *  - "B" → 7 сегментов (B0..B6), код "B2" → current=2, past=[0,1], future=[3..6]
 *  - "A" → 6 сегментов (A1..A6), код "A3" → current=2 (idx), past=[0,1], future=[3..5]
 *  - "C0" → одиночный badge-danger (отвалившийся, не линейная прогрессия)
 *  - null / нераспознанный → прочерк
 *
 * Цвета:
 *  past    → bg-success/60   (выполненные этапы)
 *  current → bg-primary-light (текущий этап)
 *  future  → bg-gray-200      (предстоящие этапы)
 */

interface LifecycleSegmentsProps {
  code: string | null;
}

type SegmentState = "past" | "current" | "future";

function parseLifecycle(code: string | null): {
  kind: "B" | "A" | "C0" | "unknown" | "none";
  idx: number;
  total: number;
} {
  if (!code) return { kind: "none", idx: 0, total: 0 };

  if (code === "C0") return { kind: "C0", idx: 0, total: 0 };

  const matchB = /^B(\d)$/.exec(code);
  if (matchB) {
    const idx = parseInt(matchB[1], 10);
    if (idx >= 0 && idx <= 6) return { kind: "B", idx, total: 7 };
  }

  const matchA = /^A(\d)$/.exec(code);
  if (matchA) {
    const n = parseInt(matchA[1], 10);
    if (n >= 1 && n <= 6) return { kind: "A", idx: n - 1, total: 6 };
  }

  return { kind: "unknown", idx: 0, total: 0 };
}

export function LifecycleSegments({ code }: LifecycleSegmentsProps) {
  const parsed = parseLifecycle(code);

  if (parsed.kind === "none") {
    return <span className="text-xs text-gray-300">—</span>;
  }

  if (parsed.kind === "C0") {
    return (
      <span className="badge badge-danger text-[10px] px-1.5 py-0.5">
        C0
      </span>
    );
  }

  if (parsed.kind === "unknown") {
    return (
      <span className="badge badge-info text-[10px] px-1.5 py-0.5">
        {code}
      </span>
    );
  }

  const { idx, total } = parsed;
  const segments: SegmentState[] = Array.from({ length: total }, (_, i) => {
    if (i < idx) return "past";
    if (i === idx) return "current";
    return "future";
  });

  const tooltipText = parsed.kind === "B"
    ? `B${idx} из ${total - 1}`
    : `A${idx + 1} из ${total}`;

  return (
    <div className="flex items-center gap-0.5" title={tooltipText}>
      {segments.map((state, i) => (
        <div
          key={i}
          className={[
            "w-3 h-1.5 rounded-sm",
            state === "past"
              ? "bg-success/60"
              : state === "current"
              ? "bg-primary-light"
              : "bg-gray-200 dark:bg-gray-700",
          ].join(" ")}
        />
      ))}
      <span className="text-xs tabular-nums text-gray-500 ml-1">{code}</span>
    </div>
  );
}
