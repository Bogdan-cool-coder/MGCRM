// MACRO Global CRM — UI Kit · TopBar + PageHeader
function TopBar() {
  const IconBtn = ({ icon, label }) => {
    const [h, setH] = React.useState(false);
    return (
      <button onMouseEnter={() => setH(true)} onMouseLeave={() => setH(false)}
        style={{ display: 'inline-flex', alignItems: 'center', gap: '6px', background: h ? 'var(--mg-gray-100)' : 'transparent', border: 'none', borderRadius: '6px', padding: '8px', cursor: 'pointer', color: 'var(--mg-text-secondary)', fontFamily: 'var(--mg-font-sans)', fontSize: '13px' }}>
        <i className={`pi ${icon}`} style={{ fontSize: '17px' }} />{label && <span>{label}</span>}
      </button>
    );
  };
  return (
    <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '4px', height: '60px', padding: '0 20px', background: 'var(--mg-surface-card)', borderBottom: '1px solid var(--mg-border-default)', flexShrink: 0 }}>
      <IconBtn icon="pi-moon" />
      <IconBtn icon="pi-globe" label="RU" />
      <IconBtn icon="pi-user" />
      <IconBtn icon="pi-sign-out" />
    </header>
  );
}

function PageHeader({ icon, title, subtitle, actions }) {
  return (
    <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '16px', padding: '16px 24px', background: 'var(--mg-surface-card)', borderBottom: '1px solid var(--mg-border-default)', minHeight: '56px', flexShrink: 0 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '12px', minWidth: 0 }}>
        {icon && <i className={`pi ${icon}`} style={{ fontSize: '20px', color: 'var(--mg-primary-900)', flexShrink: 0 }} />}
        <div style={{ minWidth: 0 }}>
          <h1 style={{ margin: 0, fontSize: '20px', fontWeight: 600, color: 'var(--mg-gray-900)', lineHeight: 1.25, fontFamily: 'var(--mg-font-sans)' }}>{title}</h1>
          {subtitle && <p style={{ margin: '2px 0 0', fontSize: '14px', color: 'var(--mg-gray-600)', fontFamily: 'var(--mg-font-sans)' }}>{subtitle}</p>}
        </div>
      </div>
      {actions && <div style={{ display: 'flex', alignItems: 'center', gap: '8px', flexShrink: 0 }}>{actions}</div>}
    </header>
  );
}

// Segmented view switcher (Kanban / List), matches the deals toolbar
function ViewSwitch({ value, onChange, options }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '2px', border: '1px solid var(--mg-border-default)', borderRadius: '6px', padding: '2px' }}>
      {options.map((o) => {
        const active = o.key === value;
        return (
          <button key={o.key} onClick={() => onChange(o.key)} title={o.label}
            style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: '32px', height: '28px', border: 'none', borderRadius: '4px', cursor: 'pointer', background: active ? 'var(--mg-primary-50)' : 'transparent', color: active ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)' }}>
            <i className={`pi ${o.icon}`} style={{ fontSize: '15px' }} />
          </button>
        );
      })}
    </div>
  );
}

window.TopBar = TopBar;
window.PageHeader = PageHeader;
window.ViewSwitch = ViewSwitch;
