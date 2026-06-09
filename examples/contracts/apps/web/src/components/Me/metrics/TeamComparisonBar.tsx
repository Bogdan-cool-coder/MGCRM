"use client";

interface Props {
  personalPct: number;
  teamAvgPct: number;
  rank: number;
  teamSize: number;
}

function Bar({ label, pct, highlight }: { label: string; pct: number; highlight?: boolean }) {
  const width = Math.min(pct, 200);
  return (
    <div className="space-y-1.5">
      <div className="flex justify-between text-xs">
        <span className="text-gray-600 dark:text-gray-400 font-medium">{label}</span>
        <span className={`font-semibold tabular-nums ${highlight ? "text-primary" : "text-gray-600 dark:text-gray-400"}`}>
          {pct}%
        </span>
      </div>
      <div className="h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
        <div
          className={`h-full rounded-full transition-all duration-500 ${highlight ? "bg-primary" : "bg-gray-400 dark:bg-gray-500"}`}
          style={{ width: `${Math.min(width / 2, 100)}%` }}
          role="progressbar"
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={200}
        />
      </div>
    </div>
  );
}

export function TeamComparisonBar({ personalPct, teamAvgPct, rank, teamSize }: Props) {
  const isTop = rank === 1;

  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 space-y-4">
      <h3 className="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
        <i className="bi bi-people text-primary" aria-hidden="true" />
        Сравнение с командой
      </h3>

      <div className="space-y-3">
        <Bar label="Вы" pct={personalPct} highlight />
        <Bar label="Команда (среднее)" pct={teamAvgPct} />
      </div>

      <div className="pt-3 border-t border-gray-100 dark:border-gray-700">
        {isTop ? (
          <p className="text-sm font-semibold text-warning flex items-center gap-1.5">
            <i className="bi bi-trophy-fill" aria-hidden="true" />
            {`#1 из ${teamSize} — лидер команды!`}
          </p>
        ) : (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Ранг в команде:{" "}
            <span className="font-bold text-primary">#{rank}</span>{" "}
            из {teamSize} менеджеров
          </p>
        )}
      </div>
    </div>
  );
}
