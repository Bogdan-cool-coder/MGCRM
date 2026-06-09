"use client";

export function Sparkline({ values, width = 84, height = 24 }: { values: number[]; width?: number; height?: number }) {
  if (!values || values.length === 0) return <span className="text-xs text-gray-300">—</span>;
  if (values.length === 1) {
    return (
      <svg width={width} height={height} className="inline-block align-middle">
        <circle cx={width / 2} cy={height / 2} r="2" fill="#172747" />
      </svg>
    );
  }
  const max = Math.max(...values, 1);
  const n = values.length;
  const step = width / (n - 1);
  const y = (v: number) => (height - 2) - (v / max) * (height - 4);
  const pts = values.map((v, i) => `${(i * step).toFixed(1)},${y(v).toFixed(1)}`).join(" ");
  const last = values[n - 1];
  return (
    <svg width={width} height={height} className="inline-block align-middle" aria-hidden>
      <polyline points={pts} fill="none" stroke="#2B4987" strokeWidth="1.5" strokeLinejoin="round" />
      <circle cx={((n - 1) * step).toFixed(1)} cy={y(last).toFixed(1)} r="2" fill={last === 0 ? "#C0392B" : "#172747"} />
    </svg>
  );
}

export function TrendArrow({ pct }: { pct: number | null | undefined }) {
  if (pct == null) return null;
  if (pct > 3) return <span className="text-success text-xs" title={`+${pct}%`}><i className="bi bi-arrow-up-right" /></span>;
  if (pct < -3) return <span className="text-danger text-xs" title={`${pct}%`}><i className="bi bi-arrow-down-right" /></span>;
  return <span className="text-gray-400 text-xs" title={`${pct}%`}><i className="bi bi-arrow-right" /></span>;
}
