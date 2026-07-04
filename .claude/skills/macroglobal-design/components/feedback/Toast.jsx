import React from 'react';

/**
 * MACRO Global CRM — Toast
 * Transient notification with a status-colored inset border. Matches the
 * MSales 2.0 toast pattern (success/danger/warning/info).
 */
const ICONS = {
  success: 'pi-check-circle',
  danger: 'pi-exclamation-triangle',
  warning: 'pi-exclamation-circle',
  info: 'pi-info-circle',
};

export function Toast({ severity = 'success', title, description, icon, onClose, style }) {
  const solid = `var(--mg-status-${severity}-solid)`;
  const iconCls = icon || ICONS[severity] || ICONS.info;
  return (
    <div style={{
      display: 'flex', alignItems: 'flex-start', gap: 12,
      background: 'var(--mg-surface-card)',
      borderInlineStart: `4px solid ${solid}`,
      border: '1px solid var(--mg-border-default)',
      borderRadius: 'var(--mg-radius-md)',
      padding: '13px 15px', boxShadow: 'var(--mg-shadow-md)',
      fontFamily: 'var(--mg-font-sans)', minWidth: 280, maxWidth: 400, ...style,
    }}>
      <i className={`pi ${iconCls}`} style={{ fontSize: 18, color: solid, marginTop: 1 }} />
      <div style={{ flex: 1, minWidth: 0 }}>
        {title && <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--mg-text-primary)' }}>{title}</div>}
        {description && <div style={{ fontSize: 13, color: 'var(--mg-text-muted)', marginTop: title ? 2 : 0 }}>{description}</div>}
      </div>
      {onClose && (
        <i className="pi pi-times" onClick={onClose}
          style={{ fontSize: 13, color: 'var(--mg-text-muted)', cursor: 'pointer', marginTop: 2 }} />
      )}
    </div>
  );
}
