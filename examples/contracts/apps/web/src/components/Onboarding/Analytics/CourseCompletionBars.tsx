"use client";

interface CompletionEntry {
  course_id: number;
  title: string;
  completed: number;
}

interface Props {
  data: CompletionEntry[] | undefined;
  isLoading: boolean;
}

export function CourseCompletionBars({ data, isLoading }: Props) {
  if (isLoading) {
    return (
      <div className="card p-5">
        <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded animate-pulse w-48 mb-4" />
        {Array.from({ length: 10 }).map((_, i) => (
          <div key={i} className="h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse mb-2" />
        ))}
      </div>
    );
  }

  const entries = data?.slice(0, 10) ?? [];
  const maxVal = entries.length > 0 ? Math.max(...entries.map((e) => e.completed), 1) : 1;

  return (
    <div className="card p-5">
      <h3 className="text-h5 mb-1">Прохождения по курсам</h3>
      <p className="text-xs text-gray-400 dark:text-gray-500 mb-3">Топ-10 курсов по количеству завершений</p>

      {entries.length === 0 ? (
        <p className="text-sm text-gray-400 dark:text-gray-500 py-4 text-center">Нет данных о прохождениях</p>
      ) : (
        <div className="flex flex-col gap-2">
          {entries.map((entry) => {
            const pct = maxVal > 0 ? (entry.completed / maxVal) * 100 : 0;
            return (
              <div key={entry.course_id} className="flex items-center gap-3">
                <span className="text-sm text-gray-700 dark:text-gray-300 w-48 truncate flex-shrink-0" title={entry.title}>
                  {entry.title}
                </span>
                <div className="flex-1 h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                  <div
                    className="h-full bg-primary-light rounded-full transition-all duration-300"
                    style={{ width: `${pct}%` }}
                  />
                </div>
                <span className="text-sm tabular-nums text-gray-600 dark:text-gray-400 w-8 text-right flex-shrink-0">
                  {entry.completed}
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
