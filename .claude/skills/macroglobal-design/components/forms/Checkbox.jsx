import React from 'react';

/**
 * MACRO Global CRM — Checkbox
 * Matches the PrimeVue Checkbox: navy fill when checked, 4px radius.
 */
export function Checkbox({ checked = false, label, disabled = false, onChange, style }) {
  return (
    <label style={{
      display: 'inline-flex', alignItems: 'center', gap: '8px',
      fontFamily: 'var(--mg-font-sans)', fontSize: '14px', color: 'var(--mg-text-primary)',
      cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.55 : 1, ...style,
    }}>
      <span
        onClick={() => !disabled && onChange && onChange(!checked)}
        style={{
          width: '18px', height: '18px', flexShrink: 0, borderRadius: 'var(--mg-radius-sm)',
          display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
          background: checked ? 'var(--mg-primary-900)' : 'var(--mg-input-bg)',
          border: `1px solid ${checked ? 'var(--mg-primary-900)' : 'var(--mg-border-strong)'}`,
          transition: 'background var(--mg-transition-fast), border-color var(--mg-transition-fast)',
        }}
      >
        {checked && <i className="pi pi-check" style={{ fontSize: '11px', color: '#fff', fontWeight: 700 }} />}
      </span>
      {label && <span>{label}</span>}
    </label>
  );
}
