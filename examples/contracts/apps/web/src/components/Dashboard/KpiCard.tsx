"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";

interface KpiCardProps {
  label: string;
  value: number | string | undefined;
  suffix?: string;
  trendPct?: number | null;
  /** invertColor=true → отрицательное значение = хорошо (text-success), положительное = плохо (text-danger) */
  invertColor?: boolean;
  sparkline?: number[];
  sparklineColor?: string;
  iconClass?: string;
  iconBg?: string;
  iconColor?: string;
  href?: string;
}

/** Простой Number Ticker на RAF. prefers-reduced-motion → сразу финал. */
function useNumberTicker(target: number, enabled: boolean): number {
  const [current, setCurrent] = useState(0);
  const rafRef = useRef<number | null>(null);

  useEffect(() => {
    if (!enabled) {
      setCurrent(target);
      return;
    }
    // prefers-reduced-motion
    const motionOk = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (!motionOk) {
      setCurrent(target);
      return;
    }
    const duration = 1100;
    const start = performance.now();
    const from = 0;

    function step(now: number) {
      const elapsed = now - start;
      const progress = Math.min(elapsed / duration, 1);
      // ease-out cubic
      const eased = 1 - Math.pow(1 - progress, 3);
      setCurrent(Math.round(from + (target - from) * eased));
      if (progress < 1) {
        rafRef.current = requestAnimationFrame(step);
      }
    }

    rafRef.current = requestAnimationFrame(step);
    return () => {
      if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [target, enabled]);

  return current;
}

/** Мини SVG спарклайн */
function Sparkline({ data, color }: { data: number[]; color: string }) {
  if (data.length < 2) return null;
  const min = Math.min(...data);
  const max = Math.max(...data);
  const range = max - min || 1;
  const w = 100;
  const h = 30;
  const pts = data
    .map((v, i) => {
      const x = (i / (data.length - 1)) * w;
      const y = h - ((v - min) / range) * (h - 4) - 2;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(" ");

  return (
    <svg
      className="mt-3 w-full h-8"
      viewBox={`0 0 ${w} ${h}`}
      preserveAspectRatio="none"
      aria-hidden="true"
    >
      <polyline
        points={pts}
        fill="none"
        stroke={color}
        strokeWidth="2"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  );
}

/** Тренд-чип */
function TrendChip({ pct, invert }: { pct: number; invert: boolean }) {
  // invert=false: + → success, - → danger
  // invert=true:  - → success (цикл короче = хорошо), + → danger
  const isGood = invert ? pct < 0 : pct > 0;
  const absVal = Math.abs(pct).toFixed(0);
  const arrow = pct > 0 ? "bi-arrow-up-short" : "bi-arrow-down-short";
  const colorCls = isGood ? "text-success-600 dark:text-success-500" : "text-danger-600 dark:text-danger-500";

  return (
    <div className={`mt-1 text-xs font-medium inline-flex items-center gap-0.5 ${colorCls}`}>
      <i className={`bi ${arrow}`} aria-hidden="true" />
      {pct > 0 ? "+" : "−"}{absVal}% к прошлому
    </div>
  );
}

export function KpiCard({
  label,
  value,
  suffix = "",
  trendPct,
  invertColor = false,
  sparkline,
  sparklineColor = "#1570EF",
  iconClass = "bi-bar-chart",
  iconBg = "bg-info-50 dark:bg-info-500/10",
  iconColor = "text-info-600",
  href,
}: KpiCardProps) {
  const isLoading = value === undefined;
  const isNumeric = typeof value === "number";
  const tickerTarget = isNumeric ? value : 0;
  const ticked = useNumberTicker(tickerTarget, isNumeric && !isLoading);

  // Magic Card spotlight
  const cardRef = useRef<HTMLDivElement>(null);
  function handleMouseMove(e: React.MouseEvent<HTMLDivElement>) {
    const el = cardRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width) * 100;
    const y = ((e.clientY - rect.top) / rect.height) * 100;
    el.style.setProperty("--x", `${x}%`);
    el.style.setProperty("--y", `${y}%`);
  }

  // Skeleton
  if (isLoading) {
    return (
      <div
        className="rounded-2xl h-[140px] animate-pulse bg-gray-100 dark:bg-gray-700"
        aria-busy="true"
        aria-label="Загружаем данные"
      />
    );
  }

  const displayValue = isNumeric
    ? ticked.toLocaleString("ru-RU") + suffix
    : String(value) + suffix;

  const cardContent = (
    <div
      ref={cardRef}
      onMouseMove={handleMouseMove}
      className="kpi-magic lift rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 hover:shadow-elev-2 p-5 relative overflow-hidden"
    >
      {/* spotlight overlay */}
      <div className="kpi-magic-spotlight pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-300 rounded-2xl" aria-hidden="true" />

      <div className="flex items-center justify-between">
        <span className="text-sm text-gray-500 dark:text-gray-400">{label}</span>
        <span className={`h-8 w-8 grid place-items-center rounded-lg shrink-0 ${iconBg}`}>
          <i className={`bi ${iconClass} text-sm ${iconColor}`} aria-hidden="true" />
        </span>
      </div>

      <div className="mt-3 text-3xl font-bold tabular-nums text-gray-900 dark:text-gray-100">
        {displayValue}
      </div>

      {trendPct != null && (
        <TrendChip pct={trendPct} invert={invertColor} />
      )}

      {sparkline && sparkline.length >= 2 && (
        <Sparkline data={sparkline} color={sparklineColor} />
      )}
    </div>
  );

  if (href) {
    return (
      <Link href={href} className="block">
        {cardContent}
      </Link>
    );
  }
  return cardContent;
}
