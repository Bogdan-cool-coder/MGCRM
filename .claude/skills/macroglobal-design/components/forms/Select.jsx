import React from 'react';

/**
 * MACRO Global CRM — Select (display-only trigger)
 * Mirrors the PrimeVue Select closed state. For specimen/prototype use.
 */
export function Select({
  value,
  placeholder = 'Выберите',
  size = 'md',
  disabled = false,
  fullWidth = false,
  open = false,
  onClick,
  style,
  ...rest
}) {
  const [hover, setHover] = React.useState(false);
  const sizes = {
    sm: { fontSize: '13px', padding: '6px 10px' },
    md: { fontSize: '14px', padding: '8px 12px' },
    lg: { fontSize: '15px', padding: '10px 14px' },
  };
  const s = sizes[size] || sizes.md;
  return (
    <div
      onClick={disabled ? undefined : onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        boxSizing: 'border-box', display: 'inline-flex', alignItems: 'center', justifyContent: 'space-between',
        gap: '10px', width: fullWidth ? '100%' : undefined, minWidth: '140px',
        fontFamily: 'var(--mg-font-sans)', fontSize: s.fontSize, padding: s.padding,
        background: disabled ? 'var(--mg-input-disabled-bg)' : 'var(--mg-input-bg)',
        color: value ? 'var(--mg-input-text)' : 'var(--mg-input-placeholder)',
        border: `1px solid ${open ? 'var(--mg-input-focus-border)' : hover && !disabled ? 'var(--mg-input-hover-border)' : 'var(--mg-input-border)'}`,
        borderRadius: 'var(--mg-radius-md)', cursor: disabled ? 'not-allowed' : 'pointer',
        boxShadow: open ? '0 0 0 2px var(--mg-primary-100)' : 'none',
        transition: 'border-color var(--mg-transition-fast)', ...style,
      }}
      {...rest}
    >
      <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{value || placeholder}</span>
      <i className="pi pi-chevron-down" style={{ fontSize: '11px', color: 'var(--mg-text-muted)', flexShrink: 0 }} />
    </div>
  );
}
