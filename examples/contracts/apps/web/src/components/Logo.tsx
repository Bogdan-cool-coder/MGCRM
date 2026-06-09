export function Logo({ className, collapsed }: { className?: string; collapsed?: boolean }) {
  return (
    <div
      className={
        (collapsed ? "flex items-center justify-center" : "flex items-center gap-2.5") +
        ` ${className ?? ""}`
      }
    >
      <div className="flex items-center justify-center h-9 w-9 rounded-lg bg-primary dark:bg-primary-light shrink-0">
        <span className="text-white text-[15px] font-bold tracking-tight leading-none">MG</span>
      </div>
      {!collapsed && (
        <span className="text-[18px] font-semibold tracking-wide text-primary dark:text-gray-100 leading-none whitespace-nowrap">
          CRM
        </span>
      )}
    </div>
  );
}
