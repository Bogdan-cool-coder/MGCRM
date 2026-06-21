// MACRO Global CRM — UI Kit · Sidebar (dark navy, brand-invariant)
function Sidebar({ active, onNavigate }) {
  const { Avatar, Badge } = window.MACROGlobalCRMDesignSystem_2f42e6;

  const main = [
    { key: 'dashboard', icon: 'pi-home', label: 'Дашборд' },
    { key: 'contacts', icon: 'pi-users', label: 'Контакты' },
    { key: 'companies', icon: 'pi-building', label: 'Компании' },
    { key: 'deals', icon: 'pi-briefcase', label: 'Сделки' },
    { key: 'tasks', icon: 'pi-check-square', label: 'Мои задачи', badge: 20 },
    { key: 'cabinet', icon: 'pi-id-card', label: 'Кабинет' },
    { key: 'catalog', icon: 'pi-box', label: 'Каталог' },
    { key: 'documents', icon: 'pi-file-edit', label: 'Документы' },
    { key: 'approvals', icon: 'pi-check-circle', label: 'Мои согласования' },
    { key: 'learning', icon: 'pi-book', label: 'Моё обучение' },
  ];
  const admin = [
    { key: 'pipeline', icon: 'pi-sliders-h', label: 'Настройки воронки' },
    { key: 'templates', icon: 'pi-file', label: 'Шаблоны' },
    { key: 'routes', icon: 'pi-sitemap', label: 'Маршруты' },
    { key: 'hr', icon: 'pi-graduation-cap', label: 'Курсы (HR)' },
    { key: 'progress', icon: 'pi-chart-bar', label: 'Прогресс' },
  ];

  const switchable = new Set(['deals', 'contacts', 'tasks', 'companies']);

  const Item = ({ it }) => {
    const [hover, setHover] = React.useState(false);
    const isActive = it.key === active;
    return (
      <div
        onMouseEnter={() => setHover(true)} onMouseLeave={() => setHover(false)}
        onClick={() => switchable.has(it.key) && onNavigate(it.key)}
        style={{
          position: 'relative', display: 'flex', alignItems: 'center', gap: '10px',
          margin: '2px 8px', borderRadius: '9px', padding: '8px 10px', minHeight: '36px',
          color: isActive ? 'var(--mg-sidebar-text-active)' : 'var(--mg-sidebar-text)',
          background: isActive ? 'var(--mg-sidebar-active-bg)' : hover ? 'rgba(255,255,255,0.05)' : 'transparent',
          cursor: switchable.has(it.key) ? 'pointer' : 'default', whiteSpace: 'nowrap',
          transition: 'background var(--mg-transition-fast), color var(--mg-transition-fast)',
        }}
      >
        {isActive && <span style={{ position: 'absolute', left: '-8px', top: '50%', transform: 'translateY(-50%)', width: '3px', height: '18px', background: 'var(--mg-sidebar-active-bar)', borderRadius: '0 3px 3px 0' }} />}
        <i className={`pi ${it.icon}`} style={{ fontSize: '18px', width: '18px', textAlign: 'center', flexShrink: 0 }} />
        <span style={{ fontSize: '13px', fontWeight: 500, flex: 1, overflow: 'hidden', textOverflow: 'ellipsis' }}>{it.label}</span>
        {it.badge && <Badge value={it.badge} />}
      </div>
    );
  };

  return (
    <aside style={{ display: 'flex', flexDirection: 'column', background: 'var(--mg-sidebar-bg)', width: '240px', height: '100%', flexShrink: 0, overflow: 'hidden' }}>
      <div style={{ display: 'flex', alignItems: 'center', height: '60px', padding: '0 16px', borderBottom: '1px solid var(--mg-sidebar-divider)', flexShrink: 0 }}>
        <img src="../../assets/macroglobal-logo-primary-light.svg" alt="MACRO Global" style={{ height: '28px', filter: 'brightness(0) invert(1)' }} />
      </div>
      <nav style={{ flex: 1, overflowY: 'auto', padding: '8px 0' }} className="mg-sb-scroll">
        {main.map((it) => <Item key={it.key} it={it} />)}
        <div style={{ padding: '14px 16px 4px', fontSize: '10px', fontWeight: 600, letterSpacing: '0.08em', textTransform: 'uppercase', color: 'rgba(255,255,255,0.35)' }}>Администрирование</div>
        {admin.map((it) => <Item key={it.key} it={it} />)}
      </nav>
      <div style={{ flexShrink: 0, borderTop: '1px solid var(--mg-sidebar-divider)', padding: '8px' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', padding: '8px', borderRadius: '6px', cursor: 'pointer' }}>
          <Avatar name="MG CRM Admin" size={32} color="rgba(255,255,255,0.15)" />
          <div style={{ flex: 1, overflow: 'hidden' }}>
            <div style={{ fontSize: '14px', fontWeight: 500, color: '#fff', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>MG CRM Admin</div>
            <div style={{ fontSize: '12px', color: 'rgba(255,255,255,0.55)' }}>Администратор</div>
          </div>
          <i className="pi pi-ellipsis-h" style={{ fontSize: '14px', color: 'rgba(255,255,255,0.4)' }} />
        </div>
      </div>
    </aside>
  );
}
window.Sidebar = Sidebar;
