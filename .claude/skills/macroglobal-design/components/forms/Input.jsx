import React from 'react';

/**
 * MACRO Global CRM — Input
 * Single-line text field matching the PrimeVue InputText used in CRM forms.
 */
export function Input({
  value,
  placeholder,
  icon,
  type = 'text',
  size = 'md',
  invalid = false,
  disabled = false,
  fullWidth = false,
  onChange,
  style,
  ...rest
}) {
  const [focus, setFocus] = React.useState(false);
  const sizes = {
    sm: { fontSize: '13px', padding: '6px 10px', icon: '13px' },
    md: { fontSize: '14px', padding: '8px 12px', icon: '14px' },
    lg: { fontSize: '15px', padding: '10px 14px', icon: '15px' },
  };
  const s = sizes[size] || sizes.md;
  const border = invalid
    ? 'var(--mg-red-500)'
    : focus ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)';

  return (
    <div style={{ position: 'relative', display: fullWidth ? 'block' : 'inline-block', width: fullWidth ? '100%' : undefined }}>
      {icon && (
        <i className={`pi ${icon}`} style={{
          position: 'absolute', left: '11px', top: '50%', transform: 'translateY(-50%)',
          fontSize: s.icon, color: 'var(--mg-input-placeholder)', pointerEvents: 'none',
        }} />
      )}
      <input
        type={type}
        value={value}
        placeholder={placeholder}
        disabled={disabled}
        onChange={onChange}
        onFocus={() => setFocus(true)}
        onBlur={() => setFocus(false)}
        style={{
          boxSizing: 'border-box', width: fullWidth ? '100%' : undefined,
          fontFamily: 'var(--mg-font-sans)', fontSize: s.fontSize,
          padding: s.padding, paddingLeft: icon ? '34px' : undefined,
          color: 'var(--mg-input-text)',
          background: disabled ? 'var(--mg-input-disabled-bg)' : 'var(--mg-input-bg)',
          border: `1px solid ${border}`, borderRadius: 'var(--mg-radius-md)',
          outline: 'none',
          boxShadow: focus && !invalid ? '0 0 0 2px var(--mg-primary-100)' : 'none',
          transition: 'border-color var(--mg-transition-fast), box-shadow var(--mg-transition-fast)',
          ...style,
        }}
        {...rest}
      />
    </div>
  );
}
