import React from 'react';

/**
 * MACRO Global CRM — Badge
 * Small count indicator (nav badges, tab counts). Warning amber by default,
 * danger red variant — matches the sidebar nav badges.
 */
export function Badge({ value, variant = 'warning', dot = false, style }) {
  const bg = variant === 'danger' ? 'var(--mg-danger)' : variant === 'primary' ? 'var(--mg-primary-900)' : '#E8821E';
  if (dot) {
    return <span style={{ width: 7, height: 7, borderRadius: '50%', background: bg, display: 'inline-block', ...style }} />;
  }
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
      minWidth: 18, height: 18, padding: '0 5px', borderRadius: 9,
      background: bg, color: '#fff', fontFamily: 'var(--mg-font-sans)',
      fontSize: 10, fontWeight: 700, lineHeight: 1, ...style,
    }}>
      {value}
    </span>
  );
}
