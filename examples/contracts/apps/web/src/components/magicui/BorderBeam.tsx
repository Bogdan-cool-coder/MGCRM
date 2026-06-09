"use client";

import type { CSSProperties } from "react";

/**
 * BorderBeam — анимированный контур карточки через conic-gradient + @property --a.
 * Адаптирован с magicui.design/docs/components/border-beam под TS strict + наши токены.
 * prefers-reduced-motion: анимация отключается (через CSS media в globals.css).
 * @keyframes beam-spin и @property --beam-angle живут в globals.css (SSR-safe).
 */

interface BorderBeamProps {
  /** Радиус скругления карточки, по умолчанию 1rem (совпадает с rounded-2xl) */
  borderRadius?: string;
  /** Толщина луча в px */
  size?: number;
  /** Длительность оборота в секундах */
  duration?: number;
  /** Начальный цвет луча */
  colorFrom?: string;
  /** Конечный цвет луча */
  colorTo?: string;
  /** z-index обёртки */
  zIndex?: number;
}

export function BorderBeam({
  borderRadius = "1rem",
  size = 2,
  duration = 6,
  colorFrom = "#4f86ff",
  colorTo = "#9cc2ff",
  zIndex = 0,
}: BorderBeamProps) {
  return (
    <span
      aria-hidden="true"
      className="border-beam-inner pointer-events-none absolute inset-0"
      style={{
        // Параметризация через CSS custom property — безопасно для SSR/hydration
        "--beam-duration": `${duration}s`,
        borderRadius,
        zIndex,
        padding: `${size}px`,
        background: `conic-gradient(from var(--beam-angle, 0deg), transparent 0 70%, ${colorFrom} 85%, ${colorTo} 92%, transparent 100%)`,
        WebkitMask: "linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0)",
        WebkitMaskComposite: "xor",
        maskComposite: "exclude",
      } as CSSProperties}
    />
  );
}
