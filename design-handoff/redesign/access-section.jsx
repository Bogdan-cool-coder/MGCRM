/* Доступ и оргструктура — Настройки → Система.
   Mirrors front/src/pages/AccessControlPage (index + DepartmentsTab, DepartmentTree,
   DepartmentSidePanel, OrgChartView, RolesPermissionsTab, PermissionMatrix,
   VisibilityScopeTab) + entities/accessControl + ru.json. Redesigned look.
   Exposes window.AccessTab. */
(function () {
const { useState, useRef, useEffect } = React;

/* ── i18n (ru.json → accessControl) ── */
const A_T = {
  title: 'Доступ и оргструктура', subtitle: 'Отделы, роли и видимость записей',
  tabs: { departments: 'Отделы', roles: 'Роли и права', visibility: 'Видимость' },
  dep: { add: 'Добавить отдел', edit: 'Редактировать отдел', del: 'Удалить отдел',
    delConfirm: (n) => 'Удалить отдел «' + n + '»? Дочерние отделы и сотрудники останутся без родителя.',
    name: 'Название', parent: 'Родительский отдел', root: '— Корень —', manager: 'Руководитель',
    members: 'Сотрудники отдела', addMember: 'Добавить сотрудника', removeMember: 'Убрать из отдела',
    viewTree: 'Дерево', viewChart: 'Схема', empty: 'Отделы не созданы',
    emptyHint: 'Добавьте первый отдел, чтобы настроить оргструктуру', noMembers: 'Нет участников', saved: 'Отдел сохранён', deleted: 'Отдел удалён' },
  roles: { title: 'Матрица прав', adminNote: 'Роль admin всегда получает все права и не может быть ограничена.',
    permissionLabel: 'Право', save: 'Сохранить', reset: 'Сбросить', saved: 'Права ролей сохранены' },
  vis: { title: 'Видимость записей', warning: 'Настройки видимости влияют на то, какие записи (сделки, контакты, задачи) видит каждая роль. Изменяйте с осторожностью.',
    scopeAll: 'Все', scopeDepartment: 'Отдел (+подотделы)', scopeOwn: 'Свои', roleColumn: 'Роль', scopeColumn: 'Видимость записей',
    departmentHint: 'Значение «Отдел» работает только если у пользователя указан отдел.', save: 'Сохранить', reset: 'Сбросить', saved: 'Настройки видимости сохранены' }
};
const A_ROLES = ['admin', 'director', 'lawyer', 'manager', 'accountant', 'cfo'];
const A_ROLE_LABEL = { admin: 'Администратор', director: 'Директор', lawyer: 'Юрист', manager: 'Менеджер', accountant: 'Бухгалтер', cfo: 'Финансовый директор' };
const A_ROLE_SHORT = { admin: 'Админ', director: 'Директор', lawyer: 'Юрист', manager: 'Менеджер', accountant: 'Бухгалтер', cfo: 'Фин. дир.' };
const A_ROLE_SEV = { admin: 'danger', director: 'warn', lawyer: 'info', manager: 'success', accountant: 'secondary', cfo: 'secondary' };

const A_GROUPS = [
  { key: 'crm', label: 'CRM', perms: ['crm.view', 'crm.manage'] },
  { key: 'sales', label: 'Продажи', perms: ['sales.view', 'sales.manage'] },
  { key: 'contracts', label: 'Договоры', perms: ['contracts.view', 'contracts.manage'] },
  { key: 'users', label: 'Пользователи', perms: ['users.view', 'users.manage'] },
  { key: 'automation', label: 'Автоматизации', perms: ['automation.manage'] },
  { key: 'analytics', label: 'Аналитика', perms: ['analytics.view', 'settings.manage'] },
  { key: 'finance', label: 'Финансы', perms: ['finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management'] },
  { key: 'system', label: 'Системные права', perms: ['admin-write', 'dedup-scan-all', 'view-manager-cabinet', 'system-reset'] }
];
const A_PERM_LABEL = {
  'crm.view': 'CRM — просмотр', 'crm.manage': 'CRM — управление', 'sales.view': 'Продажи — просмотр', 'sales.manage': 'Продажи — управление',
  'contracts.view': 'Договоры — просмотр', 'contracts.manage': 'Договоры — управление', 'users.view': 'Пользователи — просмотр', 'users.manage': 'Пользователи — управление',
  'automation.manage': 'Автоматизации — управление', 'analytics.view': 'Аналитика — просмотр', 'settings.manage': 'Настройки системы — управление',
  'finance.view': 'Финансы — просмотр', 'finance.entry': 'Финансы — ввод операций', 'finance.posting': 'Финансы — проводки', 'finance.journals.manual': 'Финансы — ручные журналы',
  'finance.payments.approve': 'Финансы — согласование платежей', 'finance.period.close': 'Финансы — закрытие периода', 'finance.settings.manage': 'Финансы — настройки', 'finance.reports.management': 'Финансы — управленческие отчёты',
  'admin-write': 'Системные изменения (админ)', 'dedup-scan-all': 'Дедупликация — полное сканирование', 'view-manager-cabinet': 'Кабинет менеджера — доступ', 'system-reset': 'Сброс системы'
};
/* default role → granted permissions */
const A_DEFAULT_PERMS = {
  admin: A_GROUPS.flatMap((g) => g.perms),
  director: ['crm.view', 'crm.manage', 'sales.view', 'sales.manage', 'contracts.view', 'contracts.manage', 'users.view', 'automation.manage', 'analytics.view', 'settings.manage', 'finance.view', 'view-manager-cabinet'],
  lawyer: ['crm.view', 'contracts.view', 'contracts.manage'],
  manager: ['crm.view', 'sales.view', 'sales.manage', 'contracts.view'],
  accountant: ['crm.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.journals.manual'],
  cfo: ['crm.view', 'analytics.view', 'finance.view', 'finance.entry', 'finance.posting', 'finance.payments.approve', 'finance.period.close', 'finance.settings.manage', 'finance.reports.management']
};
const A_DEFAULT_VIS = { admin: 'all', director: 'all', lawyer: 'department', manager: 'own', accountant: 'department', cfo: 'all' };

const A_M = (id, full_name, email, role) => ({ id, full_name, email, role });
const A_TREE0 = [
  { id: 1, name: 'Руководство', manager: 'Директор Петров П.П.', members: [A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin')], children: [
    { id: 2, name: 'Отдел продаж', manager: 'Иванов Алексей Сергеевич', members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager')], children: [
      { id: 5, name: 'Группа B2B', manager: 'Петрова Мария Сергеевна', members: [A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager')], children: [] }
    ] },
    { id: 3, name: 'Финансы', manager: 'Сергей Казначеев', members: [A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')], children: [
      { id: 6, name: 'Бухгалтерия', manager: 'Анна Счётова', members: [A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant')], children: [] }
    ] },
    { id: 4, name: 'Юридический отдел', manager: 'Lawyer Test', members: [A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer')], children: [] }
  ] }
];
const A_ALL_USERS = [A_M(1, 'Admin', 'svkv42@gmail.com', 'admin'), A_M(2, 'Bogdan Yadykin', 'b.yadykin@macroglobaltech.com', 'admin'), A_M(3, 'Lawyer Test', 'lawyer@mgcrm.test', 'lawyer'), A_M(5, 'Георгий Некрасов', 'g.nekrasov@macroglobaltech.com', 'manager'), A_M(6, 'Директор Петров П.П.', 'director@mgcrm.test', 'director'), A_M(7, 'Иванов Алексей Сергеевич', 'manager1@mgcrm.test', 'manager'), A_M(8, 'Илья Рогов', 'ilyarogov.mera@gmail.com', 'manager'), A_M(9, 'Клим Федорин', 'k.fedorin@macroglobaltech.com', 'manager'), A_M(10, 'Олеся Моисеева', 'o.moiseeva@macroglobaltech.com', 'manager'), A_M(11, 'Петрова Мария Сергеевна', 'manager2@mgcrm.test', 'manager'), A_M(12, 'Анна Счётова', 'accountant@mgcrm.test', 'accountant'), A_M(13, 'Сергей Казначеев', 'cfo@mgcrm.test', 'cfo')];

/* ── shared primitives ── */
const A_SEV = {
  success: { bg: 'var(--mg-status-success-bg)', fg: 'var(--mg-status-success-text)' }, danger: { bg: 'var(--mg-status-danger-bg)', fg: 'var(--mg-status-danger-text)' },
  warn: { bg: 'var(--mg-status-warning-bg)', fg: 'var(--mg-status-warning-text)' }, info: { bg: 'var(--mg-status-info-bg)', fg: 'var(--mg-status-info-text)' }, secondary: { bg: 'var(--c-muted2)', fg: 'var(--c-text2)' }
};
function A_btn(kind) {
  const base = { display: 'inline-flex', alignItems: 'center', gap: 7, height: 38, padding: '0 15px', borderRadius: 'var(--mg-radius-md)', fontFamily: 'var(--mg-font-sans)', fontSize: 13, fontWeight: 600, cursor: 'pointer', border: 'none', whiteSpace: 'nowrap' };
  if (kind === 'primary') return { ...base, background: 'var(--mg-primary-900)', color: '#fff' };
  if (kind === 'danger') return { ...base, background: 'var(--mg-status-danger-solid)', color: '#fff' };
  if (kind === 'text') return { ...base, background: 'transparent', color: 'var(--c-text2)' };
  return { ...base, background: 'transparent', color: 'var(--c-text2)', border: '1px solid var(--c-border2)' };
}
function A_sbtn(kind, active) { const b = A_btn(kind === 'p' ? 'primary' : 'ghost'); return { ...b, height: 34, padding: '0 12px', fontSize: 12.5, ...(active ? {} : {}) }; }
function A_Tag({ value, severity }) { const c = A_SEV[severity] || A_SEV.secondary; return <span style={{ display: 'inline-flex', alignItems: 'center', fontSize: 11.5, fontWeight: 600, padding: '3px 9px', borderRadius: 6, background: c.bg, color: c.fg, whiteSpace: 'nowrap' }}>{value}</span>; }
function A_Msg({ severity, children }) {
  const map = { warn: { bg: 'var(--mg-status-warning-bg)', bd: 'var(--mg-status-warning-border)', fg: 'var(--mg-status-warning-text)', ic: 'pi-exclamation-triangle' }, secondary: { bg: 'var(--c-hover)', bd: 'var(--c-border)', fg: 'var(--c-text2)', ic: 'pi-info-circle' } };
  const c = map[severity] || map.secondary;
  return <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: '11px 14px', background: c.bg, border: '1px solid ' + c.bd, borderRadius: 'var(--mg-radius-md)' }}><i className={'pi ' + c.ic} style={{ fontSize: 14, color: c.fg, marginTop: 1, flexShrink: 0 }} /><span style={{ fontSize: 13, color: c.fg, lineHeight: 1.5 }}>{children}</span></div>;
}
function A_Check({ checked, disabled, onChange }) {
  return <span onClick={() => !disabled && onChange(!checked)} style={{ display: 'inline-flex', width: 20, height: 20, borderRadius: 5, border: '1.5px solid ' + (checked ? 'var(--mg-primary-900)' : 'var(--c-border2)'), background: checked ? 'var(--mg-primary-900)' : 'var(--c-card)', alignItems: 'center', justifyContent: 'center', cursor: disabled ? 'not-allowed' : 'pointer', opacity: disabled ? 0.5 : 1 }}>{checked && <i className="pi pi-check" style={{ fontSize: 11, color: '#fff', fontWeight: 700 }} />}</span>;
}
function A_Dropdown({ value, onChange, options, placeholder, width, filter }) {
  const [open, setOpen] = useState(false); const [q, setQ] = useState(''); const ref = useRef(null);
  useEffect(() => { const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); }; document.addEventListener('mousedown', h); return () => document.removeEventListener('mousedown', h); }, []);
  const sel = options.find((o) => o.value === value);
  const list = filter && q ? options.filter((o) => o.label.toLowerCase().includes(q.toLowerCase())) : options;
  return <div ref={ref} style={{ position: 'relative', width: width || '100%' }}>
    <div onClick={() => setOpen(!open)} style={{ display: 'flex', alignItems: 'center', gap: 8, height: 40, padding: '0 12px', border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'), borderRadius: 'var(--mg-radius-md)', background: 'var(--c-card)', cursor: 'pointer', boxShadow: open ? 'var(--mg-focus-ring)' : 'none' }}>
      <span style={{ flex: 1, fontSize: 14, color: sel ? 'var(--c-text)' : 'var(--c-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{sel ? sel.label : placeholder}</span>
      <i className={'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down')} style={{ fontSize: 12, color: 'var(--c-muted)' }} />
    </div>
    {open && <div style={{ position: 'absolute', top: 44, insetInlineStart: 0, zIndex: 40, width: '100%', minWidth: 180, background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-md)', boxShadow: 'var(--mg-shadow-lg)', padding: 5, maxHeight: 260, overflowY: 'auto' }}>
      {filter && <div style={{ display: 'flex', alignItems: 'center', gap: 7, height: 34, padding: '0 10px', margin: '2px 2px 6px', border: '1px solid var(--c-border2)', borderRadius: 'var(--mg-radius-sm)', background: 'var(--c-page)' }}><i className="pi pi-search" style={{ fontSize: 12, color: 'var(--c-muted)' }} /><input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Поиск…" style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontFamily: 'var(--mg-font-sans)', fontSize: 13, color: 'var(--c-text)' }} /></div>}
      {list.map((o) => { const on = o.value === value; return <div key={String(o.value)} onClick={() => { onChange(o.value); setOpen(false); setQ(''); }} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderRadius: 7, cursor: 'pointer', fontSize: 13.5, fontWeight: on ? 600 : 500, color: on ? 'var(--mg-primary-900)' : 'var(--c-text)', background: on ? 'var(--mg-primary-100)' : 'transparent' }}>{o.label}{on && <i className="pi pi-check" style={{ marginInlineStart: 'auto', fontSize: 12 }} />}</div>; })}
    </div>}
  </div>;
}
function A_avatar(name, role, size) {
  const grad = { admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)', director: 'linear-gradient(135deg,#E6A46E,#C67E3C)', cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)', lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)', manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)', accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)' };
  const w = name.trim().split(/\s+/); const ini = (w.length >= 2 ? w[0][0] + w[1][0] : w[0].slice(0, 2)).toUpperCase();
  return <span style={{ width: size, height: size, borderRadius: '50%', flexShrink: 0, background: grad[role] || grad.accountant, color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: size * 0.36, fontWeight: 600 }}>{ini}</span>;
}

/* ── tree helpers ── */
function A_walkUpdate(nodes, id, fn) { return nodes.map((n) => n.id === id ? fn(n) : { ...n, children: A_walkUpdate(n.children, id, fn) }); }
function A_flatten(nodes, depth, acc) { acc = acc || []; nodes.forEach((n) => { acc.push({ id: n.id, name: n.name, depth: depth || 0 }); A_flatten(n.children, (depth || 0) + 1, acc); }); return acc; }
function A_removeNode(nodes, id) { const kept = []; let orphans = []; nodes.forEach((n) => { if (n.id === id) { orphans = orphans.concat(n.children); } else { const r = A_removeNode(n.children, id); n = { ...n, children: r.nodes }; orphans = orphans.concat(r.orphans); kept.push(n); } }); return { nodes: kept, orphans }; }
function A_countAll(n) { return 1; }

/* ── Department tree (redesigned) ── */
function A_TreeNode({ node, depth, selectedId, onSelect, onEdit, onDelete, expanded, toggle, editing }) {
  const isOpen = expanded[node.id] !== false;
  const on = selectedId === node.id;
  return <React.Fragment>
    <div className="atnode" onClick={() => onSelect(node)} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', paddingInlineStart: 12 + depth * 22, borderRadius: 'var(--mg-radius-md)', cursor: 'pointer', background: on ? 'var(--mg-primary-100)' : 'transparent', transition: 'background .12s' }}>
      {node.children.length > 0 ? <i className={'pi ' + (isOpen ? 'pi-chevron-down' : 'pi-chevron-right')} onClick={(e) => { e.stopPropagation(); toggle(node.id); }} style={{ fontSize: 11, color: 'var(--c-muted)', width: 14, cursor: 'pointer' }} /> : <span style={{ width: 14 }} />}
      <i className="pi pi-building" style={{ fontSize: 14, color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)' }} />
      <span style={{ flex: 1, fontSize: 13.5, fontWeight: on ? 600 : 500, color: on ? 'var(--mg-primary-900)' : 'var(--c-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{node.name}</span>
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, color: 'var(--c-muted)' }}><i className="pi pi-users" style={{ fontSize: 11 }} />{node.members.length}</span>
      {editing && <span className="atacts" style={{ display: 'inline-flex', gap: 2, opacity: 0, transition: 'opacity .12s' }}>
        <button className="uicon" onClick={(e) => { e.stopPropagation(); onEdit(node); }} title="Редактировать" style={{ width: 28, height: 28, borderRadius: 7, border: 'none', background: 'transparent', cursor: 'pointer' }}><i className="pi pi-pencil" style={{ fontSize: 12, color: 'var(--c-text2)' }} /></button>
        <button className="uicon" onClick={(e) => { e.stopPropagation(); onDelete(node); }} title="Удалить" style={{ width: 28, height: 28, borderRadius: 7, border: 'none', background: 'transparent', cursor: 'pointer' }}><i className="pi pi-trash" style={{ fontSize: 12, color: 'var(--mg-status-danger-text)' }} /></button>
      </span>}
    </div>
    {isOpen && node.children.map((c) => <A_TreeNode key={c.id} node={c} depth={depth + 1} selectedId={selectedId} onSelect={onSelect} onEdit={onEdit} onDelete={onDelete} expanded={expanded} toggle={toggle} editing={editing} />)}
  </React.Fragment>;
}

/* ── Org chart (Схема) ── */
function A_ChartNode({ node }) {
  const [open, setOpen] = useState(false);
  return <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
    <div onClick={() => setOpen((o) => !o)} style={{ width: 210, background: 'var(--c-card)', border: '1px solid ' + (open ? 'var(--mg-primary-900)' : 'var(--c-border2)'), borderRadius: 'var(--mg-radius-md)', boxShadow: 'var(--mg-shadow-sm)', padding: '11px 14px', cursor: 'pointer', textAlign: 'center' }}>
      <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--c-text)' }}>{node.name}</div>
      <div style={{ fontSize: 12, color: 'var(--c-muted)', marginTop: 3 }}>{node.manager}</div>
      <div style={{ display: 'inline-flex', alignItems: 'center', gap: 5, marginTop: 7, fontSize: 11.5, fontWeight: 600, padding: '2px 9px', borderRadius: 999, background: 'var(--mg-primary-100)', color: 'var(--mg-primary-900)' }}><i className="pi pi-users" style={{ fontSize: 10 }} />{node.members.length}<i className={'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down')} style={{ fontSize: 9, marginInlineStart: 1 }} /></div>
      {open && <div style={{ marginTop: 11, paddingTop: 11, borderTop: '1px solid var(--c-border)', display: 'flex', flexDirection: 'column', gap: 8, textAlign: 'start' }}>
        {node.members.length ? node.members.map((m) => <div key={m.id} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>{A_avatar(m.full_name, m.role, 24)}<span style={{ flex: 1, minWidth: 0, fontSize: 12, fontWeight: 500, color: 'var(--c-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.full_name}</span></div>) : <span style={{ fontSize: 12, color: 'var(--c-muted)' }}>{A_T.dep.noMembers}</span>}
      </div>}
    </div>
    {node.children.length > 0 && <React.Fragment>
      <div style={{ width: 1, height: 20, background: 'var(--c-border2)' }} />
      <div style={{ display: 'flex', gap: 26, position: 'relative', paddingTop: 20, borderTop: node.children.length > 1 ? '1px solid var(--c-border2)' : 'none', alignItems: 'flex-start' }}>
        {node.children.map((c) => <div key={c.id} style={{ position: 'relative' }}><div style={{ position: 'absolute', top: -20, insetInlineStart: '50%', width: 1, height: 20, background: 'var(--c-border2)' }} /><A_ChartNode node={c} /></div>)}
      </div>
    </React.Fragment>}
  </div>;
}

/* ── Department side panel (drawer) ── */
function A_SidePanel({ mode, form, setForm, parentOptions, userOptions, onClose, onSave, onOpenPicker, onRemoveMember }) {
  const set = (k, v) => setForm((s) => ({ ...s, [k]: v }));
  return <div onClick={onClose} style={{ position: 'fixed', inset: 0, zIndex: 1300, background: 'rgba(9,16,32,0.45)', display: 'flex', justifyContent: 'flex-end' }}>
    <div onClick={(e) => e.stopPropagation()} style={{ width: 440, maxWidth: '92vw', height: '100%', background: 'var(--c-card)', borderInlineStart: '1px solid var(--c-border)', boxShadow: 'var(--mg-shadow-lg)', display: 'flex', flexDirection: 'column' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--c-border)' }}>
        <span style={{ flex: 1, fontSize: 16, fontWeight: 600 }}>{mode === 'create' ? A_T.dep.add : A_T.dep.edit}</span>
        <i className="pi pi-times" onClick={onClose} style={{ fontSize: 14, color: 'var(--c-muted)', cursor: 'pointer' }} />
      </div>
      <div style={{ flex: 1, overflowY: 'auto', padding: 20, display: 'flex', flexDirection: 'column', gap: 16 }}>
        <div><label style={A_lbl}>{A_T.dep.name}</label><input className="inp" autoFocus value={form.name} onChange={(e) => set('name', e.target.value)} placeholder={A_T.dep.name} style={A_inp} /></div>
        <div><label style={A_lbl}>{A_T.dep.parent}</label><A_Dropdown value={form.parentId} onChange={(v) => set('parentId', v)} options={[{ value: null, label: A_T.dep.root }].concat(parentOptions)} placeholder={A_T.dep.root} /></div>
        <div><label style={A_lbl}>{A_T.dep.manager}</label><A_Dropdown value={form.managerId} onChange={(v) => set('managerId', v)} options={userOptions} placeholder="—" filter /></div>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', marginBottom: 8 }}><label style={{ ...A_lbl, margin: 0, flex: 1 }}>{A_T.dep.members}</label><button style={{ ...A_btn('text'), height: 30, padding: '0 8px', fontSize: 12.5, color: 'var(--mg-primary-900)' }} onClick={onOpenPicker}><i className="pi pi-plus" style={{ fontSize: 11 }} />{A_T.dep.addMember}</button></div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            {form.members.length === 0 && <div style={{ fontSize: 13, color: 'var(--c-muted)', padding: '10px 0' }}>{A_T.dep.noMembers}</div>}
            {form.members.map((m) => <div key={m.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '7px 10px', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-md)' }}>
              {A_avatar(m.full_name, m.role, 28)}
              <div style={{ flex: 1, minWidth: 0 }}><div style={{ fontSize: 13, fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.full_name}</div></div>
              <i className="pi pi-times" onClick={() => onRemoveMember(m.id)} title={A_T.dep.removeMember} style={{ fontSize: 12, color: 'var(--c-muted)', cursor: 'pointer' }} />
            </div>)}
          </div>
        </div>
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, padding: '14px 20px', borderTop: '1px solid var(--c-border)' }}>
        <button style={A_btn('ghost')} onClick={onClose}>Отмена</button>
        <button style={A_btn('primary')} onClick={onSave}>Сохранить</button>
      </div>
    </div>
  </div>;
}
const A_lbl = { display: 'block', fontSize: 13, fontWeight: 500, color: 'var(--c-text)', marginBottom: 6 };
const A_inp = { width: '100%', height: 40, padding: '0 13px', borderRadius: 'var(--mg-radius-md)', border: '1px solid var(--mg-input-border)', background: 'var(--c-card)', color: 'var(--c-text)', fontFamily: 'var(--mg-font-sans)', fontSize: 14, boxSizing: 'border-box' };

/* ── member picker dialog ── */
function A_MemberPicker({ available, close, onAdd }) {
  const [sel, setSel] = useState([]); const [q, setQ] = useState('');
  const list = available.filter((u) => u.full_name.toLowerCase().includes(q.toLowerCase()));
  const toggle = (id) => setSel((s) => s.includes(id) ? s.filter((x) => x !== id) : [...s, id]);
  return <div onClick={close} style={{ position: 'fixed', inset: 0, zIndex: 1400, background: 'rgba(9,16,32,0.55)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
    <div onClick={(e) => e.stopPropagation()} style={{ width: 440, maxWidth: '92vw', maxHeight: 'calc(100vh - 40px)', background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-lg)', display: 'flex', flexDirection: 'column' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--c-border)' }}><span style={{ flex: 1, fontSize: 16, fontWeight: 600 }}>{A_T.dep.addMember}</span><i className="pi pi-times" onClick={close} style={{ fontSize: 14, color: 'var(--c-muted)', cursor: 'pointer' }} /></div>
      <div style={{ padding: '14px 20px' }}><div style={{ display: 'flex', alignItems: 'center', gap: 8, height: 40, padding: '0 12px', border: '1px solid var(--mg-input-border)', borderRadius: 'var(--mg-radius-md)', background: 'var(--c-page)' }}><i className="pi pi-search" style={{ fontSize: 13, color: 'var(--c-muted)' }} /><input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Поиск…" style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontFamily: 'var(--mg-font-sans)', fontSize: 14, color: 'var(--c-text)' }} /></div></div>
      <div style={{ flex: 1, overflowY: 'auto', padding: '0 12px 8px' }}>
        {list.map((u) => { const on = sel.includes(u.id); return <div key={u.id} className="urow" onClick={() => toggle(u.id)} style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '8px 10px', borderRadius: 8, cursor: 'pointer' }}><A_Check checked={on} onChange={() => toggle(u.id)} />{A_avatar(u.full_name, u.role, 30)}<div style={{ flex: 1, minWidth: 0 }}><div style={{ fontSize: 13.5, fontWeight: 500 }}>{u.full_name}</div><div style={{ fontSize: 12, color: 'var(--c-muted)' }}>{u.email}</div></div></div>; })}
        {list.length === 0 && <div style={{ padding: '20px 10px', fontSize: 13, color: 'var(--c-muted)', textAlign: 'center' }}>Ничего не найдено</div>}
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, padding: '14px 20px', borderTop: '1px solid var(--c-border)' }}><button style={A_btn('ghost')} onClick={close}>Отмена</button><button style={{ ...A_btn('primary'), opacity: sel.length ? 1 : 0.5, pointerEvents: sel.length ? 'auto' : 'none' }} onClick={() => onAdd(sel)}>Добавить</button></div>
    </div>
  </div>;
}

/* ── confirm delete ── */
function A_Confirm({ message, onAccept, close }) {
  return <div onClick={close} style={{ position: 'fixed', inset: 0, zIndex: 1500, background: 'rgba(9,16,32,0.55)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
    <div onClick={(e) => e.stopPropagation()} style={{ width: 430, maxWidth: '92vw', background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-lg)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--c-border)' }}><span style={{ flex: 1, fontSize: 16, fontWeight: 600 }}>{A_T.dep.del}</span><i className="pi pi-times" onClick={close} style={{ fontSize: 14, color: 'var(--c-muted)', cursor: 'pointer' }} /></div>
      <div style={{ padding: 20, display: 'flex', gap: 14, alignItems: 'flex-start' }}><span style={{ width: 40, height: 40, borderRadius: '50%', background: 'var(--mg-status-danger-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><i className="pi pi-exclamation-triangle" style={{ fontSize: 17, color: 'var(--mg-status-danger-text)' }} /></span><div style={{ fontSize: 14, color: 'var(--c-text2)', lineHeight: 1.55, paddingTop: 3 }}>{message}</div></div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, padding: '14px 20px', borderTop: '1px solid var(--c-border)' }}><button style={A_btn('ghost')} onClick={close}>Отмена</button><button style={A_btn('danger')} onClick={onAccept}>Удалить</button></div>
    </div>
  </div>;
}

function A_Toasts({ items }) {
  return <div style={{ position: 'fixed', insetBlockEnd: 20, insetInlineEnd: 20, zIndex: 2000, display: 'flex', flexDirection: 'column', gap: 10 }}>
    {items.map((t) => <div key={t.id} style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 240, maxWidth: 360, padding: '12px 14px', background: 'var(--c-card)', border: '1px solid var(--c-border)', borderInlineStart: '3px solid var(--mg-status-success-text)', borderRadius: 'var(--mg-radius-md)', boxShadow: 'var(--mg-shadow-lg)', animation: 'toastIn .2s ease' }}><i className="pi pi-check-circle" style={{ fontSize: 16, color: 'var(--mg-status-success-text)' }} /><span style={{ fontSize: 13.5, fontWeight: 500 }}>{t.summary}</span></div>)}
  </div>;
}

/* ═══════════════ TABS ═══════════════ */
function A_DepartmentsTab({ pushToast }) {
  const [tree, setTree] = useState(A_TREE0);
  const [search, setSearch] = useState(''); const [viewMode, setViewMode] = useState('tree');
  const [selectedId, setSelectedId] = useState(2);
  const [expanded, setExpanded] = useState({});
  const [panel, setPanel] = useState(null); const [form, setForm] = useState({ name: '', parentId: null, managerId: null, members: [] });
  const [picker, setPicker] = useState(false); const [confirm, setConfirm] = useState(null);
  const [editing, setEditing] = useState(false);
  const toggle = (id) => setExpanded((e) => ({ ...e, [id]: e[id] === false ? true : false }));

  const findNode = (nodes, id) => { for (const n of nodes) { if (n.id === id) return n; const f = findNode(n.children, id); if (f) return f; } return null; };
  const selected = findNode(tree, selectedId);
  const parentOptions = A_flatten(tree).map((d) => ({ value: d.id, label: '— '.repeat(d.depth) + d.name }));
  const userOptions = A_ALL_USERS.map((u) => ({ value: u.id, label: u.full_name }));

  function openCreate() { setForm({ name: '', parentId: selectedId || null, managerId: null, members: [] }); setPanel('create'); }
  function openEdit(n) { setForm({ id: n.id, name: n.name, parentId: null, managerId: (A_ALL_USERS.find((u) => u.full_name === n.manager) || {}).id ?? null, members: n.members.slice() }); setPanel('edit'); }
  function savePanel() {
    if (!form.name.trim()) return;
    const mgr = (A_ALL_USERS.find((u) => u.id === form.managerId) || {}).full_name || '—';
    if (panel === 'edit') { setTree((t) => A_walkUpdate(t, form.id, (n) => ({ ...n, name: form.name.trim(), manager: mgr, members: form.members }))); }
    else { const id = Date.now(); const node = { id, name: form.name.trim(), manager: mgr, members: form.members, children: [] };
      setTree((t) => form.parentId ? A_walkUpdate(t, form.parentId, (n) => ({ ...n, children: [...n.children, node] })) : [...t, node]); if (form.parentId) setExpanded((e) => ({ ...e, [form.parentId]: false })); }
    pushToast(A_T.dep.saved); setPanel(null);
  }
  function doDelete(n) { setTree((t) => A_removeNode(t, n.id).nodes); if (selectedId === n.id) setSelectedId(null); pushToast(A_T.dep.deleted); setConfirm(null); }
  function addMembers(ids) { const add = A_ALL_USERS.filter((u) => ids.includes(u.id) && !form.members.some((m) => m.id === u.id)); setForm((f) => ({ ...f, members: [...f.members, ...add] })); setPicker(false); }
  function removeMember(id) { setForm((f) => ({ ...f, members: f.members.filter((m) => m.id !== id) })); }

  const q = search.trim().toLowerCase();
  const filterTree = (nodes) => nodes.map((n) => ({ ...n, children: filterTree(n.children) })).filter((n) => !q || n.name.toLowerCase().includes(q) || n.children.length);
  const shown = filterTree(tree);

  return <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
    {/* toolbar */}
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, height: 40, padding: '0 12px', flex: 1, minWidth: 180, maxWidth: 280, border: '1px solid var(--mg-input-border)', borderRadius: 'var(--mg-radius-md)', background: 'var(--c-card)' }}><i className="pi pi-search" style={{ fontSize: 13, color: 'var(--c-muted)' }} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Поиск…" style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontFamily: 'var(--mg-font-sans)', fontSize: 14, color: 'var(--c-text)' }} /></div>
      <div style={{ display: 'inline-flex', padding: 3, gap: 2, background: 'var(--c-muted2)', borderRadius: 'var(--mg-radius-md)' }}>
        {[['tree', A_T.dep.viewTree, 'pi-sitemap'], ['chart', A_T.dep.viewChart, 'pi-share-alt']].map(([k, l, ic]) => <button key={k} onClick={() => setViewMode(k)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, height: 32, padding: '0 12px', border: 'none', borderRadius: 'var(--mg-radius-sm)', cursor: 'pointer', fontFamily: 'var(--mg-font-sans)', fontSize: 12.5, fontWeight: 600, background: viewMode === k ? 'var(--c-card)' : 'transparent', color: viewMode === k ? 'var(--mg-primary-900)' : 'var(--c-text2)', boxShadow: viewMode === k ? 'var(--mg-shadow-sm)' : 'none' }}><i className={'pi ' + ic} style={{ fontSize: 12 }} />{l}</button>)}
      </div>
      <span style={{ flex: 1 }} />
      <div style={{ display: 'flex', gap: 10 }}>
        <button style={editing ? { ...A_btn('ghost'), color: 'var(--mg-primary-900)', borderColor: 'var(--mg-primary-900)' } : A_btn('ghost')} onClick={() => setEditing((e) => !e)}><i className={'pi ' + (editing ? 'pi-check' : 'pi-pencil')} style={{ fontSize: 12 }} />{editing ? 'Завершить редактирование' : 'Редактировать'}</button>
        <button style={A_btn('primary')} onClick={openCreate}><i className="pi pi-plus" style={{ fontSize: 12 }} />{A_T.dep.add}</button>
      </div>
    </div>

    {viewMode === 'tree' ? <div style={{ display: 'grid', gridTemplateColumns: '1fr 340px', gap: 16, alignItems: 'start' }}>
      {/* tree card */}
      <div style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', padding: 8 }}>
        {shown.length ? shown.map((n) => <A_TreeNode key={n.id} node={n} depth={0} selectedId={selectedId} onSelect={(d) => setSelectedId(d.id)} onEdit={openEdit} onDelete={(d) => setConfirm(d)} expanded={expanded} toggle={toggle} editing={editing} />)
          : <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 10, padding: '48px 20px', color: 'var(--c-muted)' }}><i className="pi pi-building" style={{ fontSize: 28, opacity: 0.3 }} /><span style={{ fontSize: 14, color: 'var(--c-text2)', fontWeight: 500 }}>{A_T.dep.empty}</span><span style={{ fontSize: 13, textAlign: 'center', maxWidth: 240 }}>{A_T.dep.emptyHint}</span><button style={{ ...A_btn('ghost'), marginTop: 4 }} onClick={openCreate}><i className="pi pi-plus" style={{ fontSize: 12 }} />{A_T.dep.add}</button></div>}
      </div>
      {/* detail */}
      {selected ? <div style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 16px', borderBottom: '1px solid var(--c-border)' }}>
          <div style={{ flex: 1, minWidth: 0 }}><div style={{ fontSize: 15, fontWeight: 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{selected.name}</div><div style={{ fontSize: 12, color: 'var(--c-muted)', marginTop: 2 }}>Руководитель: {selected.manager}</div></div>
          {editing && <button className="uicon" onClick={() => openEdit(selected)} title="Редактировать" style={{ width: 30, height: 30, borderRadius: 7, border: 'none', background: 'transparent', cursor: 'pointer' }}><i className="pi pi-pencil" style={{ fontSize: 13, color: 'var(--c-text2)' }} /></button>}
        </div>
        <div style={{ padding: 4 }}>
          {selected.members.length ? selected.members.map((m) => <div key={m.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '8px 12px' }}>{A_avatar(m.full_name, m.role, 30)}<div style={{ flex: 1, minWidth: 0 }}><div style={{ fontSize: 13, fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.full_name}</div><div style={{ fontSize: 11.5, color: 'var(--c-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{m.email}</div></div><A_Tag value={A_ROLE_LABEL[m.role]} severity={A_ROLE_SEV[m.role]} /></div>)
            : <div style={{ padding: '28px 12px', fontSize: 13, color: 'var(--c-muted)', textAlign: 'center' }}>{A_T.dep.noMembers}</div>}
        </div>
      </div> : <div style={{ background: 'var(--c-card)', border: '1px dashed var(--c-border2)', borderRadius: 'var(--mg-radius-lg)', padding: '40px 20px', textAlign: 'center', color: 'var(--c-muted)', fontSize: 13 }}>Выберите отдел, чтобы увидеть сотрудников</div>}
    </div> : <div style={{ background: 'var(--c-page)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', padding: '32px 20px', overflowX: 'auto' }}>
      <div style={{ display: 'flex', gap: 40, justifyContent: 'center', minWidth: 'min-content', alignItems: 'flex-start' }}>{shown.map((n) => <A_ChartNode key={n.id} node={n} />)}</div>
    </div>}

    {panel && <A_SidePanel mode={panel} form={form} setForm={setForm} parentOptions={parentOptions.filter((o) => o.value !== form.id)} userOptions={userOptions} onClose={() => setPanel(null)} onSave={savePanel} onOpenPicker={() => setPicker(true)} onRemoveMember={removeMember} />}
    {picker && <A_MemberPicker available={A_ALL_USERS.filter((u) => !form.members.some((m) => m.id === u.id))} close={() => setPicker(false)} onAdd={addMembers} />}
    {confirm && <A_Confirm message={A_T.dep.delConfirm(confirm.name)} onAccept={() => doDelete(confirm)} close={() => setConfirm(null)} />}
  </div>;
}

function A_RolesTab({ pushToast }) {
  const [perms, setPerms] = useState(() => { const m = {}; A_ROLES.forEach((r) => { m[r] = new Set(A_DEFAULT_PERMS[r]); }); return m; });
  const [collapsed, setCollapsed] = useState({});
  const [dirty, setDirty] = useState(false);
  const has = (r, p) => perms[r].has(p);
  function toggle(p, r, v) { if (r === 'admin') return; setPerms((m) => { const s = new Set(m[r]); v ? s.add(p) : s.delete(p); return { ...m, [r]: s }; }); setDirty(true); }
  function reset() { const m = {}; A_ROLES.forEach((r) => { m[r] = new Set(A_DEFAULT_PERMS[r]); }); setPerms(m); setDirty(false); }
  function save() { pushToast(A_T.roles.saved); setDirty(false); }
  const th = { padding: '9px 12px', fontSize: 11, fontWeight: 600, letterSpacing: '.02em', textTransform: 'uppercase', color: 'var(--c-muted)', borderBottom: '1px solid var(--c-border)' };

  return <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
    <A_Msg severity="secondary">{A_T.roles.adminNote}</A_Msg>
    {A_GROUPS.map((g) => { const open = !collapsed[g.key]; return <div key={g.key} style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', overflow: 'hidden' }}>
      <div onClick={() => setCollapsed((c) => ({ ...c, [g.key]: !c[g.key] }))} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '12px 16px', cursor: 'pointer', background: 'var(--c-hover)', borderBottom: open ? '1px solid var(--c-border)' : 'none' }}>
        <i className={'pi ' + (open ? 'pi-chevron-down' : 'pi-chevron-right')} style={{ fontSize: 12, color: 'var(--c-muted)' }} />
        <span style={{ flex: 1, fontSize: 14, fontWeight: 600 }}>{g.label}</span>
        <span style={{ fontSize: 11.5, fontWeight: 600, padding: '2px 8px', borderRadius: 999, background: 'var(--c-muted2)', color: 'var(--c-text2)' }}>{g.perms.length}</span>
      </div>
      {open && <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 760 }}>
        <thead><tr><th style={{ ...th, textAlign: 'start', minWidth: 260 }}>{A_T.roles.permissionLabel}</th>{A_ROLES.map((r) => <th key={r} style={{ ...th, textAlign: 'center', width: 92 }}>{A_ROLE_SHORT[r]}</th>)}</tr></thead>
        <tbody>{g.perms.map((p) => <tr key={p} className="urow">
          <td style={{ padding: '10px 12px', borderBottom: '1px solid var(--c-border)' }}><div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}><span style={{ fontSize: 13.5, color: 'var(--c-text)' }}>{A_PERM_LABEL[p] || p}</span><code style={{ fontFamily: 'ui-monospace,monospace', fontSize: 11, color: 'var(--c-muted)', background: 'var(--c-muted2)', padding: '1px 6px', borderRadius: 4, alignSelf: 'flex-start' }}>{p}</code></div></td>
          {A_ROLES.map((r) => <td key={r} style={{ padding: '10px 12px', borderBottom: '1px solid var(--c-border)', textAlign: 'center' }}><div style={{ display: 'flex', justifyContent: 'center' }}><A_Check checked={r === 'admin' ? true : has(r, p)} disabled={r === 'admin'} onChange={(v) => toggle(p, r, v)} /></div></td>)}
        </tr>)}</tbody>
      </table></div>}
    </div>; })}
    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, paddingTop: 2 }}><button style={{ ...A_btn('ghost'), opacity: dirty ? 1 : 0.5, pointerEvents: dirty ? 'auto' : 'none' }} onClick={reset}>{A_T.roles.reset}</button><button style={{ ...A_btn('primary'), opacity: dirty ? 1 : 0.5, pointerEvents: dirty ? 'auto' : 'none' }} onClick={save}>{A_T.roles.save}</button></div>
  </div>;
}

function A_VisibilityTab({ pushToast }) {
  const [vis, setVis] = useState(() => ({ ...A_DEFAULT_VIS }));
  const [dirty, setDirty] = useState(false);
  const opts = [{ value: 'all', label: A_T.vis.scopeAll }, { value: 'department', label: A_T.vis.scopeDepartment }, { value: 'own', label: A_T.vis.scopeOwn }];
  function setScope(r, v) { setVis((m) => ({ ...m, [r]: v })); setDirty(true); }
  function reset() { setVis({ ...A_DEFAULT_VIS }); setDirty(false); }
  function save() { pushToast(A_T.vis.saved); setDirty(false); }
  const th = { padding: '11px 16px', fontSize: 11.5, fontWeight: 600, letterSpacing: '.03em', textTransform: 'uppercase', color: 'var(--c-muted)', textAlign: 'start', borderBottom: '1px solid var(--c-border)' };

  return <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 640 }}>
    <A_Msg severity="warn">{A_T.vis.warning}</A_Msg>
    <div style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', overflow: 'hidden' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr><th style={{ ...th, width: 200 }}>{A_T.vis.roleColumn}</th><th style={th}>{A_T.vis.scopeColumn}</th></tr></thead>
        <tbody>{A_ROLES.map((r) => <tr key={r} className="urow"><td style={{ padding: '12px 16px', borderBottom: '1px solid var(--c-border)' }}><A_Tag value={A_ROLE_LABEL[r]} severity={A_ROLE_SEV[r]} /></td><td style={{ padding: '10px 16px', borderBottom: '1px solid var(--c-border)' }}><A_Dropdown value={vis[r]} onChange={(v) => setScope(r, v)} options={opts} width={240} /></td></tr>)}</tbody>
      </table>
    </div>
    <A_Msg severity="secondary">{A_T.vis.departmentHint}</A_Msg>
    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10 }}><button style={{ ...A_btn('ghost'), opacity: dirty ? 1 : 0.5, pointerEvents: dirty ? 'auto' : 'none' }} onClick={reset}>{A_T.vis.reset}</button><button style={{ ...A_btn('primary'), opacity: dirty ? 1 : 0.5, pointerEvents: dirty ? 'auto' : 'none' }} onClick={save}>{A_T.vis.save}</button></div>
  </div>;
}

function AccessTab() {
  const [tab, setTab] = useState('departments');
  const [toasts, setToasts] = useState([]);
  const pushToast = (summary) => { const id = Date.now() + Math.random(); setToasts((t) => [...t, { id, summary }]); setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 2600); };
  const TABS = [['departments', A_T.tabs.departments], ['roles', A_T.tabs.roles], ['visibility', A_T.tabs.visibility]];

  return <React.Fragment>
    <div style={{ marginBottom: 18 }}>
      <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>{A_T.title}</h2>
      <p style={{ margin: '5px 0 0', fontSize: 13, color: 'var(--c-muted)' }}>{A_T.subtitle}</p>
    </div>
    <div style={{ display: 'flex', gap: 4, borderBottom: '1px solid var(--c-border)', marginBottom: 20 }}>
      {TABS.map(([k, l]) => { const on = tab === k; return <button key={k} onClick={() => setTab(k)} style={{ background: 'transparent', border: 'none', cursor: 'pointer', padding: '10px 14px', marginBottom: -1, whiteSpace: 'nowrap', fontFamily: 'var(--mg-font-sans)', fontSize: 14, fontWeight: on ? 600 : 500, color: on ? 'var(--mg-primary-900)' : 'var(--c-muted)', borderBottom: '2px solid ' + (on ? 'var(--mg-primary-900)' : 'transparent') }}>{l}</button>; })}
    </div>
    {tab === 'departments' && <A_DepartmentsTab pushToast={pushToast} />}
    {tab === 'roles' && <A_RolesTab pushToast={pushToast} />}
    {tab === 'visibility' && <A_VisibilityTab pushToast={pushToast} />}
    <A_Toasts items={toasts} />
  </React.Fragment>;
}

window.AccessTab = AccessTab;
})();
