"use client";

/**
 * Radix Toast — глобальная система уведомлений.
 *
 * API:
 *   import { useToast } from "@/components/ui/Toast";
 *   const { toast } = useToast();
 *   toast.success("Настройки сохранены");
 *   toast.error("Не удалось сохранить");
 *   toast.info("Операция запущена");
 *   toast.warning("Требуется действие");
 *
 * Монтирование провайдера:
 *   <ToastProvider> в (app)/layout.tsx и при необходимости в (auth)/layout.tsx
 */

import {
  createContext,
  useCallback,
  useContext,
  useRef,
  useState,
} from "react";
import * as RadixToast from "@radix-ui/react-toast";
import clsx from "clsx";

// ─── Типы ────────────────────────────────────────────────────────────────────

type ToastVariant = "success" | "error" | "info" | "warning";

interface ToastItem {
  id: number;
  variant: ToastVariant;
  message: string;
  description?: string;
  duration?: number;
}

interface ToastActions {
  success: (message: string, description?: string) => void;
  error: (message: string, description?: string) => void;
  info: (message: string, description?: string) => void;
  warning: (message: string, description?: string) => void;
}

interface ToastContextValue {
  toast: ToastActions;
}

// ─── Context ─────────────────────────────────────────────────────────────────

const ToastContext = createContext<ToastContextValue | null>(null);

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (!ctx) {
    throw new Error("useToast must be used inside <ToastProvider>");
  }
  return ctx;
}

// ─── Стили вариантов ──────────────────────────────────────────────────────────

const variantStyles: Record<ToastVariant, { root: string; icon: string; iconClass: string }> = {
  success: {
    root: "border-success-500/30 bg-success-50 dark:bg-success-500/10 dark:border-success-500/20",
    icon: "bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-500",
    iconClass: "bi-check-circle-fill",
  },
  error: {
    root: "border-danger-500/30 bg-danger-50 dark:bg-danger-500/10 dark:border-danger-500/20",
    icon: "bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-500",
    iconClass: "bi-x-circle-fill",
  },
  info: {
    root: "border-info-500/30 bg-info-50 dark:bg-info-500/10 dark:border-info-500/20",
    icon: "bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-500",
    iconClass: "bi-info-circle-fill",
  },
  warning: {
    root: "border-warning-500/30 bg-warning-50 dark:bg-warning-500/10 dark:border-warning-500/20",
    icon: "bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-500",
    iconClass: "bi-exclamation-triangle-fill",
  },
};

const titleStyles: Record<ToastVariant, string> = {
  success: "text-success-700 dark:text-success-500",
  error: "text-danger-700 dark:text-danger-500",
  info: "text-info-700 dark:text-info-500",
  warning: "text-warning-700 dark:text-warning-500",
};

// ─── Отдельный Toast-элемент ──────────────────────────────────────────────────

function ToastItem({ item, onRemove }: { item: ToastItem; onRemove: (id: number) => void }) {
  const styles = variantStyles[item.variant];

  return (
    <RadixToast.Root
      duration={item.duration ?? 4500}
      onOpenChange={(open) => { if (!open) onRemove(item.id); }}
      className={clsx(
        // Layout
        "group relative flex items-start gap-3 rounded-lg border px-4 py-3 shadow-elev-3",
        // Цвет схемы
        styles.root,
        // Анимация открытия/закрытия через data-state (Radix SSR-safe)
        "data-[state=open]:animate-toast-in",
        "data-[state=closed]:animate-toast-out",
        "data-[swipe=move]:translate-x-[var(--radix-toast-swipe-move-x)]",
        "data-[swipe=cancel]:translate-x-0 data-[swipe=cancel]:transition-transform",
        "data-[swipe=end]:animate-toast-swipe-out",
      )}
    >
      {/* Иконка */}
      <div className={clsx("mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md", styles.icon)}>
        <i className={clsx("bi", styles.iconClass, "text-base leading-none")} aria-hidden="true" />
      </div>

      {/* Текст */}
      <div className="flex-1 min-w-0 pt-0.5">
        <RadixToast.Title
          className={clsx("text-sm font-semibold leading-snug", titleStyles[item.variant])}
        >
          {item.message}
        </RadixToast.Title>
        {item.description && (
          <RadixToast.Description className="mt-0.5 text-xs text-gray-600 dark:text-gray-400 leading-snug">
            {item.description}
          </RadixToast.Description>
        )}
      </div>

      {/* Закрыть */}
      <RadixToast.Action altText="Закрыть уведомление" asChild>
        <button
          type="button"
          onClick={() => onRemove(item.id)}
          className="shrink-0 mt-0.5 rounded p-1 text-gray-500 hover:text-gray-700 hover:bg-black/5 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-white/10 transition-colors"
          aria-label="Закрыть"
        >
          <i className="bi bi-x text-base leading-none" aria-hidden="true" />
        </button>
      </RadixToast.Action>
    </RadixToast.Root>
  );
}

// ─── Провайдер ────────────────────────────────────────────────────────────────

export function ToastProvider({ children }: { children: React.ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([]);
  const counterRef = useRef(0);

  const add = useCallback((variant: ToastVariant, message: string, description?: string) => {
    const id = ++counterRef.current;
    setToasts((prev) => [...prev, { id, variant, message, description }]);
  }, []);

  const remove = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
  }, []);

  const toast: ToastActions = {
    success: (msg, desc) => add("success", msg, desc),
    error:   (msg, desc) => add("error",   msg, desc),
    info:    (msg, desc) => add("info",    msg, desc),
    warning: (msg, desc) => add("warning", msg, desc),
  };

  return (
    <ToastContext.Provider value={{ toast }}>
      <RadixToast.Provider swipeDirection="right">
        {children}
        {toasts.map((item) => (
          <ToastItem key={item.id} item={item} onRemove={remove} />
        ))}
        <RadixToast.Viewport
          className={clsx(
            "fixed bottom-4 right-4 z-[9999]",
            "flex flex-col gap-2",
            "w-[360px] max-w-[calc(100vw-32px)]",
            // Не мешает кликам в пустой зоне
            "pointer-events-none [&>*]:pointer-events-auto",
            // Фокус-менеджмент доступности
            "focus:outline-none",
          )}
        />
      </RadixToast.Provider>
    </ToastContext.Provider>
  );
}
