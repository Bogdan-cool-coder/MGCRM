"use client";

/**
 * DotPattern — точечный фон левой брендовой панели.
 * Адаптирован с magicui.design/docs/components/dot-pattern под TS strict.
 * Реализован через SVG pattern — без зависимостей от motion (чисто CSS).
 * prefers-reduced-motion: компонент рендерится нормально, он статичен.
 */

interface DotPatternProps {
  /** Размер ячейки сетки в px */
  size?: number;
  /** Радиус точки */
  radius?: number;
  /** Цвет точки */
  color?: string;
  className?: string;
}

export function DotPattern({
  size = 22,
  radius = 1,
  color = "rgba(255,255,255,0.10)",
  className,
}: DotPatternProps) {
  const patternId = "dot-pattern";

  return (
    <svg
      aria-hidden="true"
      className={`pointer-events-none absolute inset-0 w-full h-full${className ? ` ${className}` : ""}`}
      style={{
        maskImage: "radial-gradient(ellipse 80% 80% at 50% 40%, #000 40%, transparent 100%)",
        WebkitMaskImage: "radial-gradient(ellipse 80% 80% at 50% 40%, #000 40%, transparent 100%)",
      }}
    >
      <defs>
        <pattern
          id={patternId}
          x="0"
          y="0"
          width={size}
          height={size}
          patternUnits="userSpaceOnUse"
        >
          <circle cx={size / 2} cy={size / 2} r={radius} fill={color} />
        </pattern>
      </defs>
      <rect width="100%" height="100%" fill={`url(#${patternId})`} />
    </svg>
  );
}
