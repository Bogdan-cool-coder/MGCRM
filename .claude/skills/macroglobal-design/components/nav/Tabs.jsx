import React from 'react';

/**
 * MACRO Global CRM — Tabs
 * Underline tab bar (deal card, entity card). items: [{ key, label, count, icon }].
 */
export function Tabs({ items = [], value, onChange, style }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 4, borderBottom: '1px solid var(--mg-border-default)', fontFamily: 'var(--mg-font-sans)', ...style }}>
      {items.map((it) => {
        const on = it.key === value;
        return (
          <button key={it.key} onClick={() => onChange && onChange(it.key)} style={{
            display: 'inline-flex', alignItems: 'center', gap: 7, background: 'transparent', border: 'none', cursor: 'pointer',
            padding: '10px 14px', marginBottom: -1, fontFamily: 'inherit', fontSize: 14, fontWeight: on ? 600 : 500,
            color: on ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)',
            borderBottom: '2px solid ' + (on ? 'var(--mg-primary-900)' : 'transparent'),
          }}>
            {it.icon && <i className={'pi ' + it.icon} style={{ fontSize: 14 }} />}{it.label}
            {it.count != null && <span style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', minWidth: 20, height: 20, padding: '0 6px',
              borderRadius: 999, fontSize: 11, fontWeight: 700, background: on ? 'var(--mg-primary-100)' : 'var(--mg-surface-hover)', color: on ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)' }}>{it.count}</span>}
          </button>
        );
      })}
    </div>
  );
}
