import React from 'react';

/**
 * MACRO Global CRM — NotificationBadge
 * Wraps any icon/element and pins a count (or dot) at its top-inline-end.
 * RTL-safe via logical inset. Used on the topbar bell / nav items.
 */
export function NotificationBadge({ value, variant = 'danger', dot = false, max = 99, children, style }) {
  const bg = variant === 'primary' ? 'var(--mg-primary-900)' : variant === 'warning' ? 'var(--mg-orange-600)' : 'var(--mg-danger)';
  const display = typeof value === 'number' && value > max ? `${max}+` : value;
  return (
    <span style={{ position: 'relative', display: 'inline-flex', ...style }}>
      {children}
      {dot ? (
        <span style={{ position: 'absolute', top: -1, insetInlineEnd: -1, width: 9, height: 9, borderRadius: '50%', background: bg, border: '2px solid var(--mg-surface-card)' }} />
      ) : (value != null && (
        <span style={{
          position: 'absolute', top: -6, insetInlineEnd: -8,
          minWidth: 18, height: 18, padding: '0 5px', borderRadius: 999,
          background: bg, color: '#fff', fontFamily: 'var(--mg-font-sans)',
          fontSize: 10, fontWeight: 700, lineHeight: 1,
          display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
          border: '2px solid var(--mg-surface-card)',
        }}>{display}</span>
      ))}
    </span>
  );
}
