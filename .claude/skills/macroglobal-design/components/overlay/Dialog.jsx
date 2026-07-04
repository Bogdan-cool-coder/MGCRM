import React from 'react';

/**
 * MACRO Global CRM — Dialog
 * Centered modal with scrim, title bar, body and footer actions. Consolidates
 * the confirm/edit dialogs hand-rolled in the deal card.
 */
export function Dialog({ open = true, title, icon, children, footer, onClose, width = 420, style }) {
  if (!open) return null;
  return (
    <div onClick={onClose} style={{
      position: 'fixed', inset: 0, zIndex: 'var(--mg-z-modal, 1300)', background: 'rgba(9,16,32,.5)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, fontFamily: 'var(--mg-font-sans)',
    }}>
      <div onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" style={{
        width: '100%', maxWidth: width, background: 'var(--mg-surface-card)', borderRadius: 'var(--mg-radius-lg)',
        boxShadow: 'var(--mg-shadow-lg)', overflow: 'hidden', animation: 'mg-scale-in .16s ease', ...style,
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--mg-border-default)' }}>
          {icon && <i className={'pi ' + icon} style={{ fontSize: 17, color: 'var(--mg-primary-900)' }} />}
          <span style={{ flex: 1, fontSize: 16, fontWeight: 600, color: 'var(--mg-text-primary)' }}>{title}</span>
          {onClose && <i className="pi pi-times" onClick={onClose} style={{ fontSize: 14, color: 'var(--mg-text-muted)', cursor: 'pointer' }} />}
        </div>
        <div style={{ padding: '18px 20px', fontSize: 14, color: 'var(--mg-text-secondary)', lineHeight: 1.55 }}>{children}</div>
        {footer && <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, padding: '0 20px 18px' }}>{footer}</div>}
      </div>
    </div>
  );
}
