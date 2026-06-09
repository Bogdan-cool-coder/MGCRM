/**
 * BlurFade — stagger-появление элементов (fade + blur + translateY).
 * Реализован на чистой CSS-анимации (без motion/react) для надёжной работы
 * при SSR/гидрации в Next.js 14 app router.
 *
 * Анимация определена в globals.css (.blur-fade + @keyframes blur-fade-in).
 * fill-mode: both гарантирует, что после анимации элемент остаётся видимым.
 *
 * prefers-reduced-motion: глобальное правило в globals.css ставит
 * animation-duration: 0.01ms !important — при fill-mode:both это мгновенно
 * переводит элемент в конечное состояние (opacity:1).
 */

import type { CSSProperties, ReactNode } from "react";

interface BlurFadeProps {
  children: ReactNode;
  /** Задержка перед началом анимации в секундах */
  delay?: number;
  /** Длительность анимации в секундах */
  duration?: number;
  className?: string;
}

export function BlurFade({
  children,
  delay = 0,
  duration,
  className,
}: BlurFadeProps) {
  // CSS variables безопасны для SSR — это атрибуты элемента, не инлайновый <style>-тег.
  // animationDelay — инлайн style, это тоже атрибут элемента (не тег), SSR-безопасен.
  const style: CSSProperties & Record<string, string> = {
    animationDelay: `${delay}s`,
  };

  // Если передана явная длительность — переопределяем CSS-переменную
  if (duration !== undefined) {
    style["--blur-fade-duration"] = `${duration}s`;
  }

  const cls = ["blur-fade", className].filter(Boolean).join(" ");

  return (
    <div className={cls} style={style}>
      {children}
    </div>
  );
}
