import React from 'react';

/**
 * MACRO Global CRM — Tag
 * Status / category pill. Matches PrimeVue Tag severities + the CRM's
 * pipeline/deal pill colors.
 */
export function Tag({ children, severity = 'secondary', icon, size = 'md', solid = false, style }) {
  const map = {
    success: ['var(--mg-status-success-bg)', 'var(--mg-status-success-text)'],
    danger: ['var(--mg-status-danger-bg)', 'var(--mg-status-danger-text)'],
    warning: ['var(--mg-status-warning-bg)', 'var(--mg-status-warning-text)'],
    warn: ['var(--mg-status-warning-bg)', 'var(--mg-status-warning-text)'],
    info: ['var(--mg-status-info-bg)', 'var(--mg-status-info-text)'],
    primary: ['var(--mg-primary-100)', 'var(--mg-primary-900)'],
    secondary: ['var(--mg-gray-200)', 'var(--mg-gray-800)'],
  };
  let [bg, color] = map[severity] || map.secondary;
  if (solid) { color = '#fff'; bg = `var(--mg-${severity === 'warn' ? 'warning' : severity === 'secondary' ? 'gray-600' : severity})`; }
  const sizes = { sm: ['11px', '1px 6px'], md: ['12px', '2px 8px'], lg: ['13px', '3px 10px'] };
  const [fs, pad] = sizes[size] || sizes.md;
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: '4px',
      fontFamily: 'var(--mg-font-sans)', fontSize: fs, fontWeight: 600, lineHeight: 1.4,
      padding: pad, borderRadius: 'var(--mg-radius-sm)', background: bg, color, whiteSpace: 'nowrap', ...style,
    }}>
      {icon && <i className={`pi ${icon}`} style={{ fontSize: `calc(${fs} - 1px)` }} />}
      {children}
    </span>
  );
}
