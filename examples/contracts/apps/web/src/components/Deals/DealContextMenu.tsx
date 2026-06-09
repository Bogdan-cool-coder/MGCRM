"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import type { UserRole } from "@/lib/types";

interface Props {
  userRole: UserRole;
  onBulkMode: () => void;
  onFindDuplicates: () => void;
}

export function DealContextMenu({ userRole, onBulkMode, onFindDuplicates }: Props) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const router = useRouter();

  const canSettings = userRole === "admin" || userRole === "director";

  useEffect(() => {
    if (!open) return;
    function handler(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [open]);

  return (
    <div ref={ref} className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        className="btn-secondary text-sm flex items-center gap-1.5"
        title="Действия"
      >
        <i className="bi bi-three-dots-vertical" />
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-1 z-30 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1 w-52 text-sm">
          <button
            className="w-full text-left px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-2 text-gray-700 dark:text-gray-300"
            onClick={() => { setOpen(false); onBulkMode(); }}
          >
            <i className="bi bi-check2-square text-gray-400" />
            Выбрать несколько
          </button>
          <button
            className="w-full text-left px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-2 text-gray-700 dark:text-gray-300"
            onClick={() => { setOpen(false); onFindDuplicates(); }}
          >
            <i className="bi bi-people-fill text-gray-400" />
            Найти дубли
          </button>
          {canSettings && (
            <>
              <div className="border-t border-gray-100 dark:border-gray-700 my-1" />
              <button
                className="w-full text-left px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 flex items-center gap-2 text-gray-700 dark:text-gray-300"
                onClick={() => { setOpen(false); router.push("/admin/pipelines"); }}
              >
                <i className="bi bi-gear text-gray-400" />
                Настройки воронки
              </button>
            </>
          )}
        </div>
      )}
    </div>
  );
}
