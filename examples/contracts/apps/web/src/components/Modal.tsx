"use client";

/**
 * Общий модал на Radix Dialog.
 *
 * Публичный API ПОЛНОСТЬЮ сохранён (166 вызовов не требуют правок):
 *   open, title, description, onClose, onTrySave, isDirty, width, children, footer
 *
 * Внутри: Radix Dialog даёт focus-trap, ESC, scroll-lock, ARIA автоматически.
 * Оверлей с backdrop-blur. Анимации через data-state + CSS keyframes из globals.css.
 * prefers-reduced-motion: гасится глобальным правилом (animation-duration: 0.01ms).
 *
 * Вложенный «confirmOpen» диалог (несохранённые изменения) теперь тоже Radix Dialog
 * поверх родительского — поддерживается через radix nested portals.
 */

import { useState } from "react";
import * as Dialog from "@radix-ui/react-dialog";
import clsx from "clsx";

export interface ModalProps {
  open: boolean;
  title: string;
  description?: string;
  onClose: () => void;
  /** Если возвращает true — закрытие разрешено */
  onTrySave?: () => Promise<boolean> | boolean;
  isDirty?: boolean;
  width?: "sm" | "md" | "lg" | "xl";
  children: React.ReactNode;
  footer?: React.ReactNode;
}

const widths = {
  sm: "max-w-md",
  md: "max-w-2xl",
  lg: "max-w-4xl",
  xl: "max-w-6xl",
};

export function Modal({
  open, title, description, onClose, onTrySave,
  isDirty = false, width = "md", children, footer,
}: ModalProps) {
  const [confirmOpen, setConfirmOpen] = useState(false);

  function tryClose() {
    if (isDirty) {
      setConfirmOpen(true);
    } else {
      onClose();
    }
  }

  async function handleConfirmSave() {
    setConfirmOpen(false);
    if (onTrySave) {
      const ok = await onTrySave();
      if (ok) onClose();
    }
  }

  function handleConfirmDiscard() {
    setConfirmOpen(false);
    onClose();
  }

  return (
    <>
      {/* ─── Основной диалог ───────────────────────────────────────────── */}
      <Dialog.Root
        open={open}
        onOpenChange={(isOpen) => { if (!isOpen) tryClose(); }}
      >
        <Dialog.Portal>
          {/* Overlay */}
          <Dialog.Overlay
            className={clsx(
              "fixed inset-0 z-40 bg-black/40 backdrop-blur-[2px]",
              "data-[state=open]:dialog-overlay-in",
              "data-[state=closed]:dialog-overlay-out",
            )}
          />

          {/* Контейнер скролла — внешний div, кликаем backdrop на нём */}
          <div
            className="fixed inset-0 z-40 flex items-start justify-center p-4 overflow-y-auto"
            onClick={(e) => {
              // Закрывать только если клик прямо по backdrop, не по содержимому
              if (e.target === e.currentTarget) tryClose();
            }}
          >
            <Dialog.Content
              onEscapeKeyDown={(e) => {
                e.preventDefault(); // перехватываем до Radix, чтобы учесть isDirty
                tryClose();
              }}
              onPointerDownOutside={(e) => {
                // Клик по backdrop обрабатываем выше (через onClick на контейнере)
                // Здесь предотвращаем двойную обработку Radix
                e.preventDefault();
              }}
              onInteractOutside={(e) => {
                e.preventDefault();
              }}
              className={clsx(
                "relative bg-white dark:bg-gray-800 rounded-lg shadow-elev-4 w-full my-8 flex flex-col max-h-[90vh]",
                "focus:outline-none",
                widths[width],
                "data-[state=open]:dialog-content-in",
                "data-[state=closed]:dialog-content-out",
              )}
              // Radix добавляет aria-labelledby / aria-describedby автоматически
            >
              {/* Sticky header */}
              <div className="flex items-start justify-between gap-4 px-6 py-4 border-b border-gray-200 dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 rounded-t-lg z-10">
                <div className="min-w-0">
                  <Dialog.Title className="text-h4 leading-tight truncate dark:text-gray-100">
                    {title}
                  </Dialog.Title>
                  {description && (
                    <Dialog.Description className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                      {description}
                    </Dialog.Description>
                  )}
                </div>
                <Dialog.Close asChild>
                  <button
                    type="button"
                    onClick={tryClose}
                    className="shrink-0 -mr-2 p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-gray-100 transition-colors"
                    aria-label="Закрыть"
                  >
                    <i className="bi bi-x-lg text-xl" />
                  </button>
                </Dialog.Close>
              </div>

              {/* Body */}
              <div className="px-6 py-5 overflow-y-auto flex-1">
                {children}
              </div>

              {/* Sticky footer */}
              {footer && (
                <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 rounded-b-lg flex items-center justify-end gap-2 sticky bottom-0">
                  {footer}
                </div>
              )}
            </Dialog.Content>
          </div>
        </Dialog.Portal>
      </Dialog.Root>

      {/* ─── Confirm-диалог несохранённых изменений ──────────────────────── */}
      {/* Монтируется поверх основного (z-50) через отдельный Radix Portal */}
      <Dialog.Root open={confirmOpen} onOpenChange={setConfirmOpen}>
        <Dialog.Portal>
          <Dialog.Overlay className="fixed inset-0 z-50 bg-black/50 data-[state=open]:dialog-overlay-in data-[state=closed]:dialog-overlay-out" />
          <Dialog.Content
            className={clsx(
              "fixed left-1/2 top-1/2 z-50 -translate-x-1/2 -translate-y-1/2",
              "bg-white dark:bg-gray-800 rounded-lg shadow-elev-4 max-w-md w-full p-6",
              "focus:outline-none",
              "data-[state=open]:dialog-content-in data-[state=closed]:dialog-content-out",
            )}
          >
            <Dialog.Title className="text-h5 mb-2 dark:text-gray-100">
              Несохранённые изменения
            </Dialog.Title>
            <Dialog.Description className="text-sm text-gray-700 dark:text-gray-300 mb-5">
              Вы внесли изменения. Сохранить их перед закрытием?
            </Dialog.Description>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmOpen(false)} className="btn-ghost">Отмена</button>
              <button onClick={handleConfirmDiscard} className="btn-secondary">
                <i className="bi bi-trash" /> Отклонить
              </button>
              {onTrySave && (
                <button onClick={handleConfirmSave} className="btn-primary">
                  <i className="bi bi-check-lg" /> Сохранить
                </button>
              )}
            </div>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
    </>
  );
}
