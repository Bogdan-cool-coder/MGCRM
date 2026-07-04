import React from 'react';

/**
 * MACRO Global CRM — Skeleton
 * Shimmer placeholder for loading states. variant: text | circle | rect.
 * Use `lines` with variant="text" for multi-line paragraph skeletons.
 */
if (typeof document !== 'undefined' && !document.getElementById('mg-skeleton-kf')) {
  const s = document.createElement('style');
  s.id = 'mg-skeleton-kf';
  s.textContent = '@keyframes mg-shimmer{0%{background-position:-320px 0}100%{background-position:320px 0}}';
  document.head.appendChild(s);
}

const shimmer = {
  background: 'linear-gradient(90deg, var(--mg-surface-muted) 25%, var(--mg-surface-hover) 37%, var(--mg-surface-muted) 63%)',
  backgroundSize: '640px 100%',
  animation: 'mg-shimmer 1.4s infinite linear',
};

export function Skeleton({ variant = 'text', width, height, lines = 1, radius, style }) {
  if (variant === 'circle') {
    const d = width || height || 44;
    return <span style={{ ...shimmer, width: d, height: d, borderRadius: '50%', display: 'inline-block', flexShrink: 0, ...style }} />;
  }
  if (variant === 'rect') {
    return <span style={{ ...shimmer, width: width || '100%', height: height || 80, borderRadius: radius || 'var(--mg-radius-md)', display: 'block', ...style }} />;
  }
  // text (single or multi-line)
  const rows = Array.from({ length: lines });
  return (
    <span style={{ display: 'flex', flexDirection: 'column', gap: 8, width: width || '100%', ...style }}>
      {rows.map((_, i) => (
        <span key={i} style={{ ...shimmer, height: height || 12, borderRadius: 6, width: i === rows.length - 1 && lines > 1 ? '70%' : '100%' }} />
      ))}
    </span>
  );
}
