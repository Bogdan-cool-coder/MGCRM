import React from 'react';

/**
 * MACRO Global CRM — Button
 * Faithful to the PrimeVue 4 Aura-derived button used across the CRM.
 * Primary = brand navy; secondary = outlined neutral; danger = red.
 */
export function Button({
  children,
  variant = 'primary',
  size = 'md',
  icon,
  iconRight,
  text = false,
  outlined = false,
  disabled = false,
  loading = false,
  fullWidth = false,
  onClick,
  style,
  ...rest
}) {
  const sizes = {
    sm: { fontSize: '12px', padding: '5px 10px', gap: '5px', icon: '12px' },
    md: { fontSize: '14px', padding: '8px 14px', gap: '7px', icon: '14px' },
    lg: { fontSize: '15px', padding: '11px 18px', gap: '8px', icon: '16px' },
  };
  const s = sizes[size] || sizes.md;

  const palette = {
    primary: { bg: 'var(--mg-action-primary-bg)', color: '#fff', border: 'var(--mg-action-primary-bg)' },
    secondary: { bg: 'var(--mg-action-secondary-bg)', color: 'var(--mg-action-secondary-text)', border: 'var(--mg-action-secondary-border)' },
    danger: { bg: 'var(--mg-action-danger-bg)', color: '#fff', border: 'var(--mg-action-danger-bg)' },
  }[variant] || {};

  let bg = palette.bg, color = palette.color, border = palette.border;
  if (text) { bg = 'transparent'; border = 'transparent'; color = variant === 'primary' ? 'var(--mg-primary-900)' : variant === 'danger' ? 'var(--mg-red-600)' : 'var(--mg-text-secondary)'; }
  else if (outlined) { bg = 'transparent'; color = variant === 'danger' ? 'var(--mg-red-600)' : 'var(--mg-primary-900)'; border = variant === 'danger' ? 'var(--mg-red-300)' : 'var(--mg-border-strong)'; }

  const [hover, setHover] = React.useState(false);
  let renderBg = bg;
  if (hover && !disabled && !loading) {
    if (text || outlined) renderBg = variant === 'danger' ? 'var(--mg-red-50)' : 'var(--mg-primary-50)';
    else if (variant === 'primary') renderBg = 'var(--mg-action-primary-hover)';
    else if (variant === 'danger') renderBg = 'var(--mg-action-danger-hover)';
    else renderBg = 'var(--mg-action-secondary-hover)';
  }

  return (
    <button
      type="button"
      disabled={disabled || loading}
      onClick={onClick}
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: s.gap,
        fontFamily: 'var(--mg-font-sans)', fontSize: s.fontSize, fontWeight: 600,
        lineHeight: 1.2, padding: s.padding, borderRadius: 'var(--mg-radius-md)',
        background: renderBg, color, border: `1px solid ${border}`,
        cursor: disabled || loading ? 'not-allowed' : 'pointer', opacity: disabled ? 0.55 : 1,
        width: fullWidth ? '100%' : undefined, whiteSpace: 'nowrap',
        transition: 'background var(--mg-transition-fast), border-color var(--mg-transition-fast)',
        ...style,
      }}
      {...rest}
    >
      {loading && <i className="pi pi-spinner pi-spin" style={{ fontSize: s.icon }} />}
      {!loading && icon && <i className={`pi ${icon}`} style={{ fontSize: s.icon }} />}
      {children && <span>{children}</span>}
      {!loading && iconRight && <i className={`pi ${iconRight}`} style={{ fontSize: s.icon }} />}
    </button>
  );
}
