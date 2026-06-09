export function SidebarTooltip({ label }: { label: string }) {
  return (
    <span className="absolute left-full ml-2 top-1/2 -translate-y-1/2 z-50 whitespace-nowrap rounded-md bg-primary dark:bg-gray-900 text-white text-xs px-2 py-1 shadow-lg opacity-0 group-hover:opacity-100 pointer-events-none transition-opacity">
      {label}
    </span>
  );
}
