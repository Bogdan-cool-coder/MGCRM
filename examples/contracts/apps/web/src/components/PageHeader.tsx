export function PageHeader({
  title,
  description,
  eyebrow,
  actions,
  sticky = false,
}: {
  title: string;
  description?: string;
  /** Опциональный eyebrow над заголовком (Design v2). Не ломает существующие вызовы. */
  eyebrow?: string;
  actions?: React.ReactNode;
  /** Прилипающий заголовок (sticky top-0 z-10 + backdrop-blur). По умолчанию false —
   *  поведение как до Design v2, безопасно для всех ~110 страниц. Передавать явно только
   *  там, где sticky нужен (например, дашборд без собственного action-bar). */
  sticky?: boolean;
}) {
  return (
    <div
      className={[
        "border-b border-gray-200 dark:border-gray-700 px-8 h-[68px] flex items-center justify-between",
        sticky
          ? "sticky top-0 z-10 bg-white/90 dark:bg-gray-900/80 backdrop-blur"
          : "bg-white dark:bg-gray-900",
      ].join(" ")}
    >
      <div className="flex flex-col justify-center min-w-0">
        {eyebrow && (
          <div className="text-[11px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold mb-0.5 leading-none">
            {eyebrow}
          </div>
        )}
        <div className="flex items-baseline gap-3 min-w-0">
          <h1 className="text-xl font-bold leading-tight dark:text-gray-100 shrink-0">{title}</h1>
          {description && !eyebrow && (
            <p className="text-gray-500 dark:text-gray-400 text-sm truncate hidden md:inline">
              <span className="mr-2 text-gray-300 dark:text-gray-600">·</span>
              {description}
            </p>
          )}
        </div>
      </div>
      {actions && <div className="flex items-center gap-2 shrink-0">{actions}</div>}
    </div>
  );
}
