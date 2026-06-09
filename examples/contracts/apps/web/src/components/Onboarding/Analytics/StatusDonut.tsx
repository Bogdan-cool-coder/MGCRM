"use client";

interface StatusDistribution {
  assigned: number;
  in_progress: number;
  completed: number;
  overdue: number;
}

interface Props {
  data: StatusDistribution | undefined;
  isLoading: boolean;
}

const SEGMENTS = [
  { key: "assigned" as const, label: "Назначено", color: "#2B4987" },
  { key: "in_progress" as const, label: "В процессе", color: "#F59E0B" },
  { key: "completed" as const, label: "Завершено", color: "#1F9D55" },
  { key: "overdue" as const, label: "Просрочено", color: "#C0392B" },
];

// SVG donut: viewBox="0 0 36 36", r=15.915 → circumference ≈ 100
const RADIUS = 15.915;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS; // ≈ 100

export function StatusDonut({ data, isLoading }: Props) {
  if (isLoading) {
    return (
      <div className="card p-5">
        <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded w-52 mb-3 animate-pulse" />
        <div className="flex items-center gap-6">
          <div className="animate-pulse rounded-full w-28 h-28 bg-gray-100 dark:bg-gray-700 flex-shrink-0" />
          <div className="flex flex-col gap-2 flex-1">
            {SEGMENTS.map((s) => (
              <div key={s.key} className="h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
            ))}
          </div>
        </div>
      </div>
    );
  }

  const total = data
    ? data.assigned + data.in_progress + data.completed + data.overdue
    : 0;

  const isEmpty = total === 0;

  // Build dash segments
  let offset = 0;
  const dashSegments = SEGMENTS.map((seg) => {
    const val = data ? data[seg.key] : 0;
    const pct = total > 0 ? (val / total) * CIRCUMFERENCE : 0;
    const dashArray = `${pct.toFixed(2)} ${(CIRCUMFERENCE - pct).toFixed(2)}`;
    const dashOffset = -offset;
    offset += pct;
    return { ...seg, val, dashArray, dashOffset };
  });

  return (
    <div className="card p-5">
      <h3 className="text-h5 mb-3">Распределение статусов</h3>

      {isEmpty ? (
        <p className="text-sm text-gray-400 dark:text-gray-500 py-4 text-center">Нет назначений</p>
      ) : (
        <div className="flex items-center gap-6">
          {/* SVG Donut */}
          <svg
            viewBox="0 0 36 36"
            className="w-28 h-28 flex-shrink-0 -rotate-90"
            aria-hidden
          >
            {/* Background track */}
            <circle
              cx="18"
              cy="18"
              r={RADIUS}
              fill="none"
              stroke="#F3F4F6"
              strokeWidth="3"
            />
            {dashSegments.map((seg) => (
              <circle
                key={seg.key}
                cx="18"
                cy="18"
                r={RADIUS}
                fill="none"
                stroke={seg.color}
                strokeWidth="3"
                strokeDasharray={seg.dashArray}
                strokeDashoffset={seg.dashOffset}
                strokeLinecap="butt"
              />
            ))}
          </svg>

          {/* Legend */}
          <div className="flex flex-col gap-1.5">
            {dashSegments.map((seg) => (
              <div key={seg.key} className="flex items-center gap-2 text-sm">
                <span
                  className="w-3 h-3 rounded-full flex-shrink-0"
                  style={{ backgroundColor: seg.color }}
                />
                <span className="text-gray-600 dark:text-gray-400">{seg.label}</span>
                <span className="ml-auto font-semibold tabular-nums text-gray-900 dark:text-gray-100 pl-4">
                  {seg.val}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
