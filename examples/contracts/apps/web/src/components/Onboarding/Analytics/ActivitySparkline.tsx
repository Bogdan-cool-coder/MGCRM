"use client";

interface ActivityEntry {
  date: string;
  count: number;
}

interface Props {
  data: ActivityEntry[] | undefined;
  isLoading: boolean;
}

export function ActivitySparkline({ data, isLoading }: Props) {
  if (isLoading) {
    return (
      <div className="card p-5 animate-pulse">
        <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded w-64 mb-3" />
        <div className="h-16 bg-gray-100 dark:bg-gray-700 rounded" />
      </div>
    );
  }

  const points = data ?? [];
  const allZero = points.every((p) => p.count === 0);

  const W = 900;
  const H = 60;

  const maxVal = allZero ? 1 : Math.max(...points.map((p) => p.count));

  function computePolyline(): string {
    if (points.length < 2) return "";
    return points
      .map((p, i) => {
        const x = (i / (points.length - 1)) * W;
        const y = H - (p.count / maxVal) * H;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
      })
      .join(" ");
  }

  const firstDate = points.length > 0 ? points[0].date : "";
  const lastDate = points.length > 0 ? points[points.length - 1].date : "";

  function formatDate(d: string): string {
    if (!d) return "";
    const dt = new Date(d);
    return dt.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
  }

  return (
    <div className="card p-5">
      <h3 className="text-h5 mb-1">Активность учеников</h3>
      <p className="text-xs text-gray-400 dark:text-gray-500 mb-3">Уникальные попытки за последние 90 дней</p>

      {allZero ? (
        <p className="text-sm text-gray-400 dark:text-gray-500 py-4 text-center">
          Нет активности за последние 90 дней
        </p>
      ) : (
        <>
          <svg
            viewBox={`0 0 ${W} ${H}`}
            className="w-full h-16 overflow-visible"
            aria-hidden
          >
            {/* Baseline */}
            <line
              x1="0"
              y1={H}
              x2={W}
              y2={H}
              stroke="#E5E7EB"
              strokeWidth="1"
            />
            {/* Area fill */}
            {points.length >= 2 && (
              <polyline
                points={`0,${H} ${computePolyline()} ${W},${H}`}
                fill="rgba(43,73,135,0.08)"
                stroke="none"
              />
            )}
            {/* Line */}
            {points.length >= 2 && (
              <polyline
                points={computePolyline()}
                fill="none"
                stroke="#2B4987"
                strokeWidth="2"
                strokeLinejoin="round"
                strokeLinecap="round"
              />
            )}
          </svg>

          {/* Date labels */}
          {(firstDate || lastDate) && (
            <div className="flex justify-between mt-1">
              <span className="text-xs text-gray-400">{formatDate(firstDate)}</span>
              <span className="text-xs text-gray-400">{formatDate(lastDate)}</span>
            </div>
          )}
        </>
      )}
    </div>
  );
}
