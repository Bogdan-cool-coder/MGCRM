"use client";

export interface HistoryEntry {
  method: string;
  url: string;
  statusCode: number | null;
  headers: { key: string; value: string }[];
  body: string;
  timestamp: number;
}

interface Props {
  history: HistoryEntry[];
  onSelect: (entry: HistoryEntry) => void;
  onClear: () => void;
}

const METHOD_COLORS: Record<string, string> = {
  GET: "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400",
  POST: "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400",
  PUT: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
  PATCH: "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
  DELETE: "bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400",
};

export function RequestHistorySidebar({ history, onSelect, onClear }: Props) {
  return (
    <div className="w-60 shrink-0">
      <div className="card rounded-2xl shadow-elev-1 border border-gray-100 dark:border-gray-800 overflow-hidden">
        <div className="px-4 py-3 border-b border-gray-100 dark:border-gray-700">
          <span className="text-sm font-semibold text-gray-800 dark:text-gray-200">История запросов</span>
        </div>

        {history.length === 0 ? (
          <p className="text-gray-400 dark:text-gray-500 text-xs text-center py-6">Нет запросов</p>
        ) : (
          <div className="divide-y divide-gray-100 dark:divide-gray-800 max-h-96 overflow-y-auto">
            {history.map((entry, idx) => (
              <button
                key={idx}
                onClick={() => onSelect(entry)}
                className="w-full text-left px-4 py-3 hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors"
              >
                <div className="flex items-center gap-1.5 mb-1">
                  <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-medium ${METHOD_COLORS[entry.method] ?? "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400"}`}>
                    {entry.method}
                  </span>
                  {entry.statusCode !== null && (
                    <span className={`text-[10px] font-mono font-semibold tabular-nums ${entry.statusCode >= 400 ? "text-danger-600 dark:text-danger-400" : "text-success-600 dark:text-success-400"}`}>
                      {entry.statusCode}
                    </span>
                  )}
                </div>
                <div className="text-[11px] text-gray-500 dark:text-gray-400 font-mono truncate">
                  {entry.url || "/"}
                </div>
              </button>
            ))}
          </div>
        )}

        {history.length > 0 && (
          <div className="px-4 py-3 border-t border-gray-100 dark:border-gray-700">
            <button
              onClick={onClear}
              className="btn-ghost text-danger text-xs w-full"
            >
              <i className="bi bi-trash mr-1" />
              Очистить историю
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
