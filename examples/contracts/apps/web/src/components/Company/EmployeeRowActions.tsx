"use client";

import { useEffect, useRef, useState } from "react";
import type { EmployeeListItem } from "@/lib/types";

interface Props {
  employee: EmployeeListItem;
  onEdit: (employee: EmployeeListItem) => void;
  onDismiss: (employee: EmployeeListItem) => void;
  onRestore: (employee: EmployeeListItem) => void;
  onTransfer: (employee: EmployeeListItem) => void;
  onSchedule: (employee: EmployeeListItem) => void;
}

export function EmployeeRowActions({
  employee,
  onEdit,
  onDismiss,
  onRestore,
  onTransfer,
  onSchedule,
}: Props) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    if (open) {
      document.addEventListener("mousedown", handleClickOutside);
    }
    return () => {
      document.removeEventListener("mousedown", handleClickOutside);
    };
  }, [open]);

  function handle(fn: () => void) {
    setOpen(false);
    fn();
  }

  const isActive = employee.employment_status === "active";
  const isDismissed = employee.employment_status === "dismissed";

  return (
    <div className="relative" ref={ref}>
      <button
        className="btn-ghost p-1.5"
        onClick={(e) => { e.stopPropagation(); setOpen(!open); }}
        aria-label="Действия"
      >
        <i className="bi bi-three-dots-vertical text-base" />
      </button>

      {open && (
        <div className="absolute right-0 top-8 z-20 bg-white dark:bg-gray-800 shadow-lg rounded-md py-1 min-w-[180px] border border-gray-100 dark:border-gray-700">
          <button
            className="flex items-center gap-2 w-full px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
            onClick={() => handle(() => onEdit(employee))}
          >
            <i className="bi bi-pencil text-sm" />
            Редактировать
          </button>

          {isActive && (
            <button
              className="flex items-center gap-2 w-full px-3 py-2 text-sm text-danger hover:bg-danger/10"
              onClick={() => handle(() => onDismiss(employee))}
            >
              <i className="bi bi-person-x text-sm" />
              Уволить
            </button>
          )}

          {isDismissed && (
            <button
              className="flex items-center gap-2 w-full px-3 py-2 text-sm text-success hover:bg-success/10"
              onClick={() => handle(() => onRestore(employee))}
            >
              <i className="bi bi-person-check text-sm" />
              Восстановить
            </button>
          )}

          {isActive && (
            <button
              className="flex items-center gap-2 w-full px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
              onClick={() => handle(() => onTransfer(employee))}
            >
              <i className="bi bi-arrow-left-right text-sm" />
              Передать права
            </button>
          )}

          <button
            className="flex items-center gap-2 w-full px-3 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
            onClick={() => handle(() => onSchedule(employee))}
          >
            <i className="bi bi-calendar-week text-sm" />
            Расписание
          </button>
        </div>
      )}
    </div>
  );
}
