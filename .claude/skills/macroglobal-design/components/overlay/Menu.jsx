import React from 'react';

/**
 * MACRO Global CRM — Menu
 * Dropdown/context menu. Consolidates the row-menu pattern hand-rolled across
 * Tasks/Contacts/Deal pages. items: [{ icon, label, onClick, danger }] or
 * { sep: true } for a divider. Render inside a position:relative anchor.
 */
export function Menu({ items = [], onClose, align = 'end', top = 'calc(100% + 4px)', width = 200, style }) {
  return (
    <div onMouseLeave={onClose} role="menu" style={{
      position: 'absolute', top, [align === 'end' ? 'insetInlineEnd' : 'insetInlineStart']: 0,
      zIndex: 'var(--mg-z-dropdown, 1000)', minWidth: width,
      background: 'var(--mg-surface-card)', border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-md)', boxShadow: 'var(--mg-shadow-lg)', padding: 5,
      fontFamily: 'var(--mg-font-sans)', animation: 'mg-slide-up .14s ease', ...style,
    }}>
      {items.map((it, i) => it.sep ? (
        <div key={i} style={{ height: 1, background: 'var(--mg-border-default)', margin: '5px 0' }} />
      ) : (
        <button key={i} role="menuitem" onClick={() => { it.onClick && it.onClick(); onClose && onClose(); }}
          onMouseEnter={(e) => e.currentTarget.style.background = 'var(--mg-surface-hover)'}
          onMouseLeave={(e) => e.currentTarget.style.background = 'transparent'}
          style={{ display: 'flex', alignItems: 'center', gap: 10, width: '100%', background: 'transparent', border: 'none',
            cursor: 'pointer', fontFamily: 'inherit', fontSize: 13, fontWeight: 500, padding: '8px 10px', borderRadius: 'var(--mg-radius-sm)',
            textAlign: 'start', color: it.danger ? 'var(--mg-status-danger-text)' : 'var(--mg-text-primary)' }}>
          {it.icon && <i className={'pi ' + it.icon} style={{ fontSize: 14, width: 15, color: it.danger ? 'var(--mg-status-danger-text)' : 'var(--mg-text-muted)' }} />}
          <span style={{ flex: 1 }}>{it.label}</span>
          {it.kbd && <span style={{ fontSize: 11, color: 'var(--mg-text-muted)' }}>{it.kbd}</span>}
        </button>
      ))}
    </div>
  );
}
