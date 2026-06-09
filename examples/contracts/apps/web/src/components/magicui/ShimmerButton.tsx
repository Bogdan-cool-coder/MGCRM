"use client";

/**
 * ShimmerButton — кнопка с анимацией бегущего блика.
 * CSS-реализация без motion (надёжнее, нет overhead).
 * prefers-reduced-motion: shimmer-анимация отключена глобально через globals.css
 * (animation-duration: 0.01ms !important), кнопка рендерится как обычная bg-primary.
 */

import type { ButtonHTMLAttributes, ReactNode } from "react";

interface ShimmerButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  children: ReactNode;
  loading?: boolean;
  loadingText?: string;
}

export function ShimmerButton({
  children,
  loading = false,
  loadingText = "Сохранение...",
  className = "",
  disabled,
  ...rest
}: ShimmerButtonProps) {
  return (
    // shimmer-btn и @keyframes shimmer-slide живут в globals.css (SSR-safe)
    <button
      {...rest}
      disabled={disabled ?? loading}
      aria-busy={loading}
      className={`shimmer-btn w-full text-white font-medium rounded-xl py-2.5 shadow-elev-2 hover:shadow-elev-3 hover:-translate-y-0.5 transition inline-flex items-center justify-center gap-2 ${className}`}
    >
      {loading ? (
        <>
          <i className="bi bi-arrow-clockwise animate-spin" aria-hidden="true" />
          {loadingText}
        </>
      ) : (
        children
      )}
    </button>
  );
}
