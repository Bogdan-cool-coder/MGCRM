"use client";

/**
 * LordIcon — SSR-безопасная обёртка над @lordicon/react Player.
 *
 * SSR: На сервере рендерит fallback Bootstrap-иконку — нет гидрационного конфликта.
 * После hydration: динамически подгружает @lordicon/react и рендерит анимацию.
 *
 * prefers-reduced-motion: trigger "loop" → "hover" (статичный кадр до наведения).
 * Dark mode: colors берутся из CSS-переменных темы если не переданы явно.
 * Graceful fallback: если icon не задан — всегда показываем bi-иконку.
 *
 * Использование:
 *   import trashIcon from "@/lib/lordicon/trash.json";
 *   <LordIcon icon={trashIcon} trigger="hover" size={48} fallbackIcon="bi-trash" />
 *
 * Доступные иконки (free MIT, из lordicondev/lordicon и CDN):
 *   @/lib/lordicon/trash.json      — 39-trash-outline
 *   @/lib/lordicon/lock.json       — 94-lock-unlock-outline
 *   @/lib/lordicon/coins.json      — 298-coins-outline
 *   @/lib/lordicon/puzzle.json     — 186-puzzle-outline
 *   @/lib/lordicon/legacy-home.json — 63-home-outline
 *   @/lib/lordicon/background.json  — 352-animated-background
 *   @/lib/lordicon/confetti.json    — 1103-confetti-outline (успех)
 */

import { useEffect, useMemo, useRef, useState } from "react";

// ----- Типы ----------------------------------------------------------------

export type LordIconTrigger = "hover" | "loop" | "in" | "click";

export interface LordIconProps {
  /**
   * Lottie JSON данные иконки.
   * Импортируйте через: import iconData from "@/lib/lordicon/trash.json"
   * Тип object — JSON-импорт TypeScript совместим.
   */
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  icon?: any;
  /** Триггер анимации */
  trigger?: LordIconTrigger;
  /** Размер в пикселях */
  size?: number;
  /**
   * Цвета иконки: "primary:#172747,secondary:#2B4987"
   * По умолчанию берётся из CSS-токенов текущей темы.
   */
  colors?: string;
  /** Bootstrap-иконка для SSR и fallback (без bi- префикса не нужен, передаём полный класс) */
  fallbackIcon?: string;
  /** Дополнительные классы на обёртке */
  className?: string;
}

// ----- Вспомогательные хуки -----------------------------------------------

function usePrefersReducedMotion(): boolean {
  const [reduced, setReduced] = useState(false);
  useEffect(() => {
    if (typeof window === "undefined") return;
    const mq = window.matchMedia("(prefers-reduced-motion: reduce)");
    setReduced(mq.matches);
    const handler = (e: MediaQueryListEvent) => setReduced(e.matches);
    mq.addEventListener("change", handler);
    return () => mq.removeEventListener("change", handler);
  }, []);
  return reduced;
}

function useCSSVar(varName: string, fallback: string): string {
  const [value, setValue] = useState(fallback);
  useEffect(() => {
    if (typeof window === "undefined") return;
    const raw = getComputedStyle(document.documentElement)
      .getPropertyValue(varName)
      .trim();
    if (raw) setValue(raw.startsWith("#") ? raw : raw);
  }, [varName]);
  return value;
}

// ----- PlayerWrapper: рендерится только на клиенте -------------------------

interface PlayerWrapperProps {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  icon: any;
  trigger: LordIconTrigger;
  size: number;
  colors: string;
}

function PlayerWrapper({ icon, trigger, size, colors }: PlayerWrapperProps) {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const [Player, setPlayer] = useState<React.ComponentType<any> | null>(null);
  const playerRef = useRef<{
    playFromBeginning: () => void;
    play: () => void;
  }>(null);

  useEffect(() => {
    import("@lordicon/react")
      .then((mod) => setPlayer(() => mod.Player))
      .catch(() => setPlayer(null));
  }, []);

  useEffect(() => {
    if (!Player || !playerRef.current) return;
    if (trigger === "in") {
      playerRef.current.playFromBeginning();
    }
  }, [Player, trigger]);

  if (!Player) return null;

  function handleMouseEnter() {
    if (trigger === "hover" && playerRef.current) {
      playerRef.current.playFromBeginning();
    }
  }

  const playerNode = (
    <Player
      ref={playerRef}
      icon={icon}
      size={size}
      colors={colors}
      onReady={() => {
        if ((trigger === "loop" || trigger === "in") && playerRef.current) {
          playerRef.current.playFromBeginning();
        }
      }}
      onComplete={() => {
        if (trigger === "loop" && playerRef.current) {
          playerRef.current.playFromBeginning();
        }
      }}
    />
  );

  // Для trigger="hover" оборачиваем в span-перехватчик mouseenter —
  // Player от @lordicon/react не обрабатывает этот проп сам.
  if (trigger === "hover") {
    return (
      <span
        style={{ display: "contents" }}
        onMouseEnter={handleMouseEnter}
      >
        {playerNode}
      </span>
    );
  }

  return playerNode;
}

// ----- Публичный компонент -------------------------------------------------

export function LordIcon({
  icon,
  trigger = "hover",
  size = 40,
  colors,
  fallbackIcon = "bi-star",
  className = "",
}: LordIconProps) {
  const [mounted, setMounted] = useState(false);
  const reducedMotion = usePrefersReducedMotion();

  // Получаем токены темы для цветов по умолчанию
  const primaryColor = useCSSVar("--color-primary", "#172747");
  const secondaryColor = useCSSVar("--color-primary-light", "#2B4987");

  useEffect(() => {
    setMounted(true);
  }, []);

  // При reduced-motion не зацикливаем анимацию
  const effectiveTrigger: LordIconTrigger = useMemo(() => {
    if (reducedMotion && trigger === "loop") return "hover";
    return trigger;
  }, [reducedMotion, trigger]);

  const effectiveColors = useMemo(() => {
    if (colors) return colors;
    return `primary:${primaryColor},secondary:${secondaryColor}`;
  }, [colors, primaryColor, secondaryColor]);

  // SSR или icon не задан → fallback Bootstrap иконка
  if (!mounted || !icon) {
    return (
      <span
        className={`inline-flex items-center justify-center ${className}`}
        style={{ width: size, height: size }}
        aria-hidden="true"
      >
        <i className={`bi ${fallbackIcon}`} style={{ fontSize: size * 0.6 }} />
      </span>
    );
  }

  return (
    <span
      className={`inline-flex items-center justify-center ${className}`}
      style={{ width: size, height: size }}
      aria-hidden="true"
    >
      <PlayerWrapper
        icon={icon}
        trigger={effectiveTrigger}
        size={size}
        colors={effectiveColors}
      />
    </span>
  );
}
