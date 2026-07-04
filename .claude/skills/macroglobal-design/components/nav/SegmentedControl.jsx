import React from 'react';

/**
 * MACRO Global CRM — SegmentedControl
 * Pill segmented switch (Kanban/List view toggle, scope switch). Options are
 * strings or [{ key, label, icon }]. Icon-only when label omitted.
 */
export function SegmentedControl({ options = [], value, onChange, size = 'md', style }) {
  const opts = options.map((o) => typeof o === 'string' ? { key: o, label: o } : o);
  const h = size === 'sm' ? 25 : size === 'lg' ? 34 : 30;
  return (
    <div style={{ display: 'inline-flex', gap: 2, background: 'var(--mg-surface-muted)', borderRadius: 7, padding: 3, fontFamily: 'var(--mg-font-sans)', ...style }}>
      {opts.map((o) => {
        const on = o.key === value;
        const iconOnly = o.icon && !o.label;
        return (
          <button key={o.key} onClick={() => onChange && onChange(o.key)} title={o.title || o.label}
            style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6, height: h,
              width: iconOnly ? h + 2 : undefined, padding: iconOnly ? 0 : '0 12px', border: 'none', borderRadius: 5, cursor: 'pointer',
              fontFamily: 'inherit', fontSize: 12, fontWeight: 600, background: on ? 'var(--mg-surface-card)' : 'transparent',
              color: on ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)', boxShadow: on ? 'var(--mg-shadow-sm)' : 'none' }}>
            {o.icon && <i className={'pi ' + o.icon} style={{ fontSize: 14 }} />}{o.label}
          </button>
        );
      })}
    </div>
  );
}
