"use client";

import { useState } from "react";

interface Props {
  data: Array<{ date: string; amount: number }>;
}

export function SalesByDayChart({ data }: Props) {
  const [hoveredIdx, setHoveredIdx] = useState<number | null>(null);

  if (!data || data.length === 0) {
    return (
      <div className="h-32 flex items-center justify-center text-gray-400 text-sm">
        Нет данных
      </div>
    );
  }

  const max = Math.max(...data.map((d) => d.amount), 1);
  const W = 480;
  const H = 120;
  const padL = 40;
  const padR = 10;
  const padT = 10;
  const padB = 24;
  const chartW = W - padL - padR;
  const chartH = H - padT - padB;
  const barW = Math.max(2, (chartW / data.length) * 0.7);

  function yLabel(v: number) {
    if (v >= 1_000_000) return `${Math.round(v / 1_000_000)}M`;
    if (v >= 1_000) return `${Math.round(v / 1_000)}K`;
    return String(v);
  }

  const hovered = hoveredIdx !== null ? data[hoveredIdx] : null;

  return (
    <div className="relative">
      <svg
        width="100%"
        viewBox={`0 0 ${W} ${H}`}
        style={{ display: "block" }}
        onMouseLeave={() => setHoveredIdx(null)}
      >
        {/* Y-axis labels */}
        {[0, 0.5, 1].map((frac) => {
          const y = padT + chartH * (1 - frac);
          const v = max * frac;
          return (
            <g key={frac}>
              <line x1={padL} y1={y} x2={W - padR} y2={y} stroke="#e5e7eb" strokeDasharray="4 2" />
              <text x={padL - 4} y={y + 4} textAnchor="end" fontSize={9} fill="#9ca3af">
                {yLabel(v)}
              </text>
            </g>
          );
        })}

        {/* Bars */}
        {data.map((d, i) => {
          const x = padL + (i / data.length) * chartW + (chartW / data.length - barW) / 2;
          const barH = (d.amount / max) * chartH;
          const y = padT + chartH - barH;
          const isHovered = hoveredIdx === i;

          return (
            <g key={i}>
              <rect
                x={x}
                y={y}
                width={barW}
                height={Math.max(barH, 1)}
                rx={2}
                fill={isHovered ? "#2B4987" : "#172747"}
                opacity={hoveredIdx !== null && !isHovered ? 0.4 : 1}
                onMouseEnter={() => setHoveredIdx(i)}
              />
              {/* X label for every 5th */}
              {i % Math.ceil(data.length / 6) === 0 && (
                <text
                  x={x + barW / 2}
                  y={H - 4}
                  textAnchor="middle"
                  fontSize={9}
                  fill="#9ca3af"
                >
                  {new Date(d.date).getDate().toString().padStart(2, "0")}
                </text>
              )}
            </g>
          );
        })}
      </svg>

      {/* Tooltip */}
      {hovered && (
        <div className="absolute top-0 left-8 bg-gray-900 text-white text-xs px-2 py-1 rounded shadow pointer-events-none">
          {new Date(hovered.date).toLocaleDateString("ru-RU")}
          <br />
          {(hovered.amount ?? 0).toLocaleString("ru-RU")} ₽
        </div>
      )}
    </div>
  );
}
