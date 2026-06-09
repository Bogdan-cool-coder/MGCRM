"use client";

import { useState } from "react";
import clsx from "clsx";
import { api, ApiError } from "@/lib/api";

interface Props {
  automationId: number;
  isActive: boolean;
  onChanged?: (next: boolean) => void;
  /** Если true — кнопка не реагирует (например, во время массовой операции). */
  disabled?: boolean;
}

/** Inline toggle на is_active. PATCH /automations/{id} без перехода. */
export function AutomationStatusToggle({ automationId, isActive, onChanged, disabled }: Props) {
  const [pending, setPending] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function toggle(e: React.MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    if (pending || disabled) return;
    const next = !isActive;
    setPending(true);
    setErr(null);
    try {
      await api(`/automations/${automationId}`, {
        method: "PATCH",
        body: { is_active: next },
      });
      onChanged?.(next);
    } catch (error) {
      setErr(
        error instanceof ApiError
          ? String((error.detail as { detail?: string })?.detail ?? error.message)
          : "Не удалось переключить",
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <div className="flex flex-col items-start gap-0.5">
      <button
        type="button"
        onClick={toggle}
        disabled={pending || disabled}
        className={clsx(
          "relative inline-flex h-5 w-9 items-center rounded-full transition-colors",
          isActive ? "bg-success" : "bg-gray-300",
          (pending || disabled) && "opacity-60 cursor-not-allowed",
        )}
        aria-label={isActive ? "Активна — нажмите чтобы выключить" : "Выключена — нажмите чтобы включить"}
        title={isActive ? "Активна" : "Выключена"}
      >
        <span
          className={clsx(
            "inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform",
            isActive ? "translate-x-[18px]" : "translate-x-0.5",
          )}
        />
      </button>
      {err && <span className="text-xs text-danger">{err}</span>}
    </div>
  );
}
