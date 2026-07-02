/* Users section for Настройки → Система → Пользователи
   Mirrors front/src/pages/UsersPage (index.vue + composable + dialogs, ru.json),
   redesigned to the fresh MACRO CRM look. Exposes window.UsersTab. */
(function () {
const { useState, useRef, useEffect } = React;

const U_T = {
  title: 'Пользователи', subtitle: 'Управление учётными записями сотрудников',
  addUser: 'Добавить пользователя', editUser: 'Редактировать пользователя',
  active: 'Активен', inactive: 'Неактивен',
  passwordHint: 'Если не задать пароль, он будет сгенерирован автоматически. Сообщите учётные данные сотруднику.',
  fields: { full_name: 'ФИО', full_name_ph: 'Иванов Иван Иванович', email: 'Email', email_ph: 'user@company.com',
    phone: 'Телефон', phone_ph: '+7 (999) 000-00-00', job_title: 'Должность', job_title_ph: 'Менеджер по продажам',
    department: 'Отдел', department_ph: 'Выберите отдел', manager: 'Руководитель', manager_ph: 'Выберите руководителя',
    role: 'Роль', role_ph: 'Выберите роль (по умолчанию: Менеджер)', password: 'Пароль',
    password_ph: 'Оставьте пустым для автогенерации', password_edit_ph: 'Оставьте пустым, чтобы не менять' },
  filters: { search: 'Поиск по имени, email…', role: 'Роль', department: 'Отдел', status: 'Статус', active: 'Активен', inactive: 'Неактивен' },
  deactivate: 'Деактивировать', deactivateTitle: 'Деактивация пользователя',
  deactivateConfirm: (n) => 'Деактивировать пользователя «' + n + '»? Он не сможет войти в систему, но останется в истории.',
  activate: 'Активировать',
  reset: { action: 'Сбросить пароль', title: 'Сброс пароля',
    confirm: (n) => 'Сбросить пароль пользователя «' + n + '»? Текущий пароль перестанет работать, будет сгенерирован новый.',
    resultTitle: 'Новый пароль сгенерирован',
    oneTimeWarning: 'Сохраните пароль — он показывается только один раз. Передайте его пользователю по защищённому каналу.',
    newPassword: 'Новый пароль', copy: 'Копировать пароль' }
};

const U_ROLE_LABEL = { admin: 'Администратор', director: 'Директор', manager: 'Менеджер', lawyer: 'Юрист', accountant: 'Бухгалтер', cfo: 'Финансовый директор' };
const U_ROLE_SEV = { admin: 'danger', director: 'warn', lawyer: 'info', cfo: 'warn', manager: 'secondary', accountant: 'secondary' };
const U_ROLE_OPTS = ['admin', 'director', 'manager', 'lawyer', 'accountant', 'cfo'].map((v) => ({ value: v, label: U_ROLE_LABEL[v] }));
const U_ROLE_GRAD = {
  admin: 'linear-gradient(135deg,#4C7DF0,#2B4987)', director: 'linear-gradient(135deg,#E6A46E,#C67E3C)',
  cfo: 'linear-gradient(135deg,#E6A46E,#C67E3C)', lawyer: 'linear-gradient(135deg,#78B0E0,#4A82BC)',
  manager: 'linear-gradient(135deg,#4FBF8F,#2E9469)', accountant: 'linear-gradient(135deg,#9AA3B2,#6C7688)'
};
const U_DEPTS = ['Отдел продаж', 'Бухгалтерия', 'Финансы', 'Юридический отдел', 'Руководство'].map((n, i) => ({ id: i + 1, name: n }));
const U_ME = 2;
const U_USERS = [
  { id: 1, full_name: 'Admin', email: 'svkv42@gmail.com', phone: '+7 (495) 120-33-01', job_title: null, department_name: null, role: 'admin', is_active: true },
  { id: 2, full_name: 'Bogdan Yadykin', email: 'b.yadykin@macroglobaltech.com', phone: '+7 (495) 120-33-02', job_title: null, department_name: null, role: 'admin', is_active: true },
  { id: 3, full_name: 'Lawyer Test', email: 'lawyer@mgcrm.test', phone: '+7 (495) 120-33-03', job_title: null, department_name: null, role: 'lawyer', is_active: true },
  { id: 4, full_name: 'MG CRM Admin', email: 'admin@mgcrm.test', phone: '+7 (495) 120-33-04', job_title: null, department_name: null, role: 'admin', is_active: true },
  { id: 5, full_name: 'Георгий Некрасов', email: 'g.nekrasov@macroglobaltech.com', phone: '+7 (701) 555-21-05', job_title: 'Менеджер по продажам', department_name: 'Отдел продаж', role: 'manager', is_active: true },
  { id: 6, full_name: 'Директор Петров П.П.', email: 'director@mgcrm.test', phone: '+7 (701) 555-21-06', job_title: 'Директор по продажам', department_name: 'Отдел продаж', role: 'director', is_active: true },
  { id: 7, full_name: 'Иванов Алексей Сергеевич', email: 'manager1@mgcrm.test', phone: '+7 (701) 555-21-07', job_title: 'Менеджер по продажам', department_name: 'Отдел продаж', role: 'manager', is_active: true },
  { id: 8, full_name: 'Илья Рогов', email: 'ilyarogov.mera@gmail.com', phone: '+7 (701) 555-21-08', job_title: 'Менеджер по продажам', department_name: 'Отдел продаж', role: 'manager', is_active: true },
  { id: 9, full_name: 'Клим Федорин', email: 'k.fedorin@macroglobaltech.com', phone: '+7 (701) 555-21-09', job_title: 'Менеджер по продажам', department_name: 'Отдел продаж', role: 'manager', is_active: true },
  { id: 10, full_name: 'Олеся Моисеева', email: 'o.moiseeva@macroglobaltech.com', phone: '+7 (701) 555-21-10', job_title: 'Менеджер по продажам', department_name: 'Отдел продаж', role: 'manager', is_active: true },
  { id: 11, full_name: 'Петрова Мария Сергеевна', email: 'manager2@mgcrm.test', phone: '+7 (701) 555-21-11', job_title: 'Старший менеджер', department_name: 'Отдел продаж', role: 'manager', is_active: true },
  { id: 12, full_name: 'Анна Счётова', email: 'accountant@mgcrm.test', phone: '+7 (701) 340-11-22', job_title: 'Главный бухгалтер', department_name: 'Бухгалтерия', role: 'accountant', is_active: true },
  { id: 13, full_name: 'Сергей Казначеев', email: 'cfo@mgcrm.test', phone: '+7 (701) 555-21-13', job_title: 'Финансовый директор', department_name: 'Финансы', role: 'cfo', is_active: true },
  { id: 14, full_name: 'Роман Уваров', email: 'roman.old@mgcrm.test', phone: '+7 (701) 555-21-14', job_title: 'Менеджер по продажам', department_name: 'Отдел продаж', role: 'manager', is_active: false }
];

const U_SEV = {
  success: { bg: 'var(--mg-status-success-bg)', fg: 'var(--mg-status-success-text)' },
  danger: { bg: 'var(--mg-status-danger-bg)', fg: 'var(--mg-status-danger-text)' },
  warn: { bg: 'var(--mg-status-warning-bg)', fg: 'var(--mg-status-warning-text)' },
  info: { bg: 'var(--mg-status-info-bg)', fg: 'var(--mg-status-info-text)' },
  secondary: { bg: 'var(--c-muted2)', fg: 'var(--c-text2)' }
};
const uInp = { width: '100%', height: 40, padding: '0 13px', borderRadius: 'var(--mg-radius-md)', border: '1px solid var(--mg-input-border)', background: 'var(--c-card)', color: 'var(--c-text)', fontFamily: 'var(--mg-font-sans)', fontSize: 14, boxSizing: 'border-box' };
function uBtn(kind) {
  const base = { display: 'inline-flex', alignItems: 'center', gap: 7, height: 38, padding: '0 15px', borderRadius: 'var(--mg-radius-md)', fontFamily: 'var(--mg-font-sans)', fontSize: 13, fontWeight: 600, cursor: 'pointer', border: 'none', whiteSpace: 'nowrap' };
  if (kind === 'primary') return { ...base, background: 'var(--mg-primary-900)', color: '#fff' };
  if (kind === 'danger') return { ...base, background: 'var(--mg-status-danger-solid)', color: '#fff' };
  if (kind === 'warn') return { ...base, background: 'var(--mg-status-warning-solid)', color: '#3A2A12' };
  if (kind === 'text') return { ...base, background: 'transparent', color: 'var(--c-text2)' };
  return { ...base, background: 'transparent', color: 'var(--c-text2)', border: '1px solid var(--c-border2)' };
}
function uInitials(name) {
  const w = String(name).trim().split(/\s+/);
  return (w.length >= 2 ? w[0][0] + w[1][0] : (w[0].slice(0, 2))).toUpperCase();
}
function UTag({ value, severity }) {
  const c = U_SEV[severity] || U_SEV.secondary;
  return <span style={{ display: 'inline-flex', alignItems: 'center', fontSize: 11.5, fontWeight: 600, padding: '3px 9px', borderRadius: 6, background: c.bg, color: c.fg, whiteSpace: 'nowrap' }}>{value}</span>;
}
function UStatus({ active }) {
  const c = active ? U_SEV.success : U_SEV.secondary;
  return <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 500, color: active ? 'var(--mg-status-success-text)' : 'var(--c-muted)' }}>
    <span style={{ width: 7, height: 7, borderRadius: '50%', background: active ? 'var(--mg-status-success-solid, var(--mg-status-success-text))' : 'var(--c-border2)' }} />{active ? U_T.active : U_T.inactive}</span>;
}

function UDropdown({ value, onChange, options, placeholder, width, filter }) {
  const [open, setOpen] = useState(false); const [q, setQ] = useState(''); const ref = useRef(null);
  useEffect(() => { const h = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); }; document.addEventListener('mousedown', h); return () => document.removeEventListener('mousedown', h); }, []);
  const sel = options.find((o) => o.value === value);
  const list = filter && q ? options.filter((o) => o.label.toLowerCase().includes(q.toLowerCase())) : options;
  return <div ref={ref} style={{ position: 'relative', width: width || '100%' }}>
    <div onClick={() => setOpen(!open)} style={{ display: 'flex', alignItems: 'center', gap: 8, height: 40, padding: '0 12px', border: '1px solid ' + (open ? 'var(--mg-input-focus-border)' : 'var(--mg-input-border)'), borderRadius: 'var(--mg-radius-md)', background: 'var(--c-card)', cursor: 'pointer', boxShadow: open ? 'var(--mg-focus-ring)' : 'none' }}>
      <span style={{ flex: 1, fontSize: 14, color: sel ? 'var(--c-text)' : 'var(--c-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{sel ? sel.label : placeholder}</span>
      {sel && <i className="pi pi-times" onClick={(e) => { e.stopPropagation(); onChange(null); setOpen(false); }} style={{ fontSize: 12, color: 'var(--c-muted)', cursor: 'pointer' }} />}
      <i className={'pi ' + (open ? 'pi-chevron-up' : 'pi-chevron-down')} style={{ fontSize: 12, color: 'var(--c-muted)' }} />
    </div>
    {open && <div style={{ position: 'absolute', top: 44, insetInlineStart: 0, zIndex: 40, width: '100%', minWidth: 180, background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-md)', boxShadow: 'var(--mg-shadow-lg)', padding: 5, maxHeight: 280, overflowY: 'auto' }}>
      {filter && <div style={{ display: 'flex', alignItems: 'center', gap: 7, height: 34, padding: '0 10px', margin: '2px 2px 6px', border: '1px solid var(--c-border2)', borderRadius: 'var(--mg-radius-sm)', background: 'var(--c-page)' }}>
        <i className="pi pi-search" style={{ fontSize: 12, color: 'var(--c-muted)' }} /><input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Поиск…" style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontFamily: 'var(--mg-font-sans)', fontSize: 13, color: 'var(--c-text)' }} /></div>}
      {list.map((o) => { const on = o.value === value; return <div key={String(o.value)} onClick={() => { onChange(o.value); setOpen(false); setQ(''); }} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 10px', borderRadius: 7, cursor: 'pointer', fontSize: 13.5, fontWeight: on ? 600 : 500, color: on ? 'var(--mg-primary-900)' : 'var(--c-text)', background: on ? 'var(--mg-primary-100)' : 'transparent' }}>{o.label}{on && <i className="pi pi-check" style={{ marginInlineStart: 'auto', fontSize: 12 }} />}</div>; })}
      {list.length === 0 && <div style={{ padding: '8px 10px', fontSize: 13, color: 'var(--c-muted)' }}>Ничего не найдено</div>}
    </div>}
  </div>;
}

function UModal({ title, close, footer, width, children }) {
  return <div onClick={close} style={{ position: 'fixed', inset: 0, zIndex: 1300, background: 'rgba(9,16,32,0.55)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
    <div onClick={(e) => e.stopPropagation()} style={{ width: '100%', maxWidth: width || 544, background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-lg)', overflow: 'hidden', maxHeight: 'calc(100vh - 40px)', display: 'flex', flexDirection: 'column' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--c-border)', flexShrink: 0 }}>
        <span style={{ flex: 1, fontSize: 16, fontWeight: 600, color: 'var(--c-text)' }}>{title}</span>
        <i className="pi pi-times" onClick={close} style={{ fontSize: 14, color: 'var(--c-muted)', cursor: 'pointer' }} />
      </div>
      <div style={{ padding: 20, overflowY: 'auto' }}>{children}</div>
      {footer && <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 10, padding: '14px 20px', borderTop: '1px solid var(--c-border)', flexShrink: 0 }}>{footer}</div>}
    </div>
  </div>;
}
function ULabel({ children }) { return <label style={{ display: 'block', fontSize: 13, fontWeight: 500, color: 'var(--c-text)', marginBottom: 6 }}>{children}</label>; }

function UUserDialog({ editing, close, onSave }) {
  const isEdit = !!editing;
  const [f, setF] = useState(editing ? { full_name: editing.full_name, email: editing.email, phone: editing.phone || '', job_title: editing.job_title || '', department_id: (U_DEPTS.find((d) => d.name === editing.department_name) || {}).id ?? null, manager_id: null, role: editing.role, password: '' } : { full_name: '', email: '', phone: '', job_title: '', department_id: null, manager_id: null, role: null, password: '' });
  const [err, setErr] = useState({}); const [showPw, setShowPw] = useState(false);
  const set = (k, v) => setF((s) => ({ ...s, [k]: v }));
  const managers = U_USERS.filter((u) => u.id !== (editing || {}).id).map((u) => ({ value: u.id, label: u.full_name }));
  function submit() {
    const e = {};
    if (!f.full_name.trim()) e.full_name = 'Обязательное поле';
    if (!f.email.trim()) e.email = 'Обязательное поле'; else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.trim())) e.email = 'Некорректный email';
    if (f.password && f.password.length < 8) e.password = 'Минимум 8 символов';
    setErr(e); if (Object.keys(e).length) return; onSave(f, isEdit);
  }
  const half = { flex: 1, minWidth: 0 };
  return <UModal title={isEdit ? U_T.editUser : U_T.addUser} close={close} width={544}
    footer={<React.Fragment><button style={uBtn('text')} onClick={close}>Отмена</button><button style={uBtn('primary')} onClick={submit}>{isEdit ? 'Сохранить' : 'Создать'}</button></React.Fragment>}>
    <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      <div><ULabel>{U_T.fields.full_name} *</ULabel><input className="inp" autoFocus value={f.full_name} onChange={(e) => set('full_name', e.target.value)} placeholder={U_T.fields.full_name_ph} style={{ ...uInp, borderColor: err.full_name ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)' }} />{err.full_name && <div style={{ fontSize: 12, color: 'var(--mg-status-danger-text)', marginTop: 5 }}>{err.full_name}</div>}</div>
      <div><ULabel>{U_T.fields.email} *</ULabel><input className="inp" type="email" value={f.email} onChange={(e) => set('email', e.target.value)} placeholder={U_T.fields.email_ph} style={{ ...uInp, borderColor: err.email ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)' }} />{err.email && <div style={{ fontSize: 12, color: 'var(--mg-status-danger-text)', marginTop: 5 }}>{err.email}</div>}</div>
      <div style={{ display: 'flex', gap: 14 }}>
        <div style={half}><ULabel>{U_T.fields.phone}</ULabel><input className="inp" value={f.phone} onChange={(e) => set('phone', e.target.value)} placeholder={U_T.fields.phone_ph} style={uInp} /></div>
        <div style={half}><ULabel>{U_T.fields.job_title}</ULabel><input className="inp" value={f.job_title} onChange={(e) => set('job_title', e.target.value)} placeholder={U_T.fields.job_title_ph} style={uInp} /></div>
      </div>
      <div style={{ display: 'flex', gap: 14 }}>
        <div style={half}><ULabel>{U_T.fields.department}</ULabel><UDropdown value={f.department_id} onChange={(v) => set('department_id', v)} options={U_DEPTS.map((d) => ({ value: d.id, label: d.name }))} placeholder={U_T.fields.department_ph} /></div>
        <div style={half}><ULabel>{U_T.fields.role}</ULabel><UDropdown value={f.role} onChange={(v) => set('role', v)} options={U_ROLE_OPTS} placeholder={U_T.fields.role_ph} /></div>
      </div>
      <div><ULabel>{U_T.fields.manager}</ULabel><UDropdown value={f.manager_id} onChange={(v) => set('manager_id', v)} options={managers} placeholder={U_T.fields.manager_ph} filter /></div>
      <div><ULabel>{U_T.fields.password}</ULabel><div style={{ position: 'relative' }}>
        <input className="inp" type={showPw ? 'text' : 'password'} value={f.password} onChange={(e) => set('password', e.target.value)} placeholder={isEdit ? U_T.fields.password_edit_ph : U_T.fields.password_ph} style={{ ...uInp, paddingInlineEnd: 42, borderColor: err.password ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)' }} />
        <i className={'pi ' + (showPw ? 'pi-eye-slash' : 'pi-eye')} onClick={() => setShowPw(!showPw)} style={{ position: 'absolute', insetInlineEnd: 13, top: 13, fontSize: 15, color: 'var(--c-muted)', cursor: 'pointer' }} /></div>
        {err.password && <div style={{ fontSize: 12, color: 'var(--mg-status-danger-text)', marginTop: 5 }}>{err.password}</div>}</div>
      {!isEdit && <div style={{ display: 'flex', gap: 9, alignItems: 'flex-start', padding: '11px 13px', background: 'var(--c-hover)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-md)' }}>
        <i className="pi pi-info-circle" style={{ fontSize: 14, color: 'var(--c-muted)', marginTop: 1 }} /><span style={{ fontSize: 12.5, color: 'var(--c-text2)', lineHeight: 1.5 }}>{U_T.passwordHint}</span></div>}
    </div>
  </UModal>;
}

function UConfirm({ header, icon, iconColor, message, acceptLabel, acceptKind, onAccept, close }) {
  return <UModal title={header} close={close} width={430}
    footer={<React.Fragment><button style={uBtn('ghost')} onClick={close}>Отмена</button><button style={uBtn(acceptKind)} onClick={onAccept}>{acceptLabel}</button></React.Fragment>}>
    <div style={{ display: 'flex', gap: 14, alignItems: 'flex-start' }}>
      <span style={{ width: 40, height: 40, borderRadius: '50%', background: (U_SEV[acceptKind === 'danger' ? 'danger' : 'warn']).bg, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><i className={'pi ' + icon} style={{ fontSize: 17, color: iconColor }} /></span>
      <div style={{ fontSize: 14, color: 'var(--c-text2)', lineHeight: 1.55, paddingTop: 3 }}>{message}</div>
    </div>
  </UModal>;
}

function UResetResult({ password, close }) {
  const [copied, setCopied] = useState(false);
  return <UModal title={U_T.reset.resultTitle} close={close} width={448} footer={<button style={uBtn('ghost')} onClick={close}>Закрыть</button>}>
    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10, padding: 13, marginBottom: 18, background: 'var(--mg-status-warning-bg)', border: '1px solid var(--mg-status-warning-border)', borderRadius: 'var(--mg-radius-md)' }}>
      <i className="pi pi-exclamation-triangle" style={{ fontSize: 15, color: 'var(--mg-status-warning-solid)', marginTop: 1, flexShrink: 0 }} /><span style={{ fontSize: 13, color: 'var(--mg-status-warning-text)', lineHeight: 1.5 }}>{U_T.reset.oneTimeWarning}</span>
    </div>
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      <label style={{ fontSize: 13, fontWeight: 500, color: 'var(--c-text)' }}>{U_T.reset.newPassword}</label>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px', background: 'var(--c-hover)', border: '1px solid var(--c-border2)', borderRadius: 'var(--mg-radius-md)' }}>
        <code style={{ flex: 1, fontFamily: 'ui-monospace,monospace', fontSize: 15, fontWeight: 500, color: 'var(--c-text)', letterSpacing: '0.04em', wordBreak: 'break-all' }}>{password}</code>
        <button onClick={() => { navigator.clipboard && navigator.clipboard.writeText(password); setCopied(true); setTimeout(() => setCopied(false), 2000); }} title={U_T.reset.copy} style={{ width: 32, height: 32, borderRadius: '50%', border: 'none', background: 'transparent', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}><i className={'pi ' + (copied ? 'pi-check' : 'pi-copy')} style={{ fontSize: 14, color: copied ? 'var(--mg-status-success-text)' : 'var(--c-muted)' }} /></button>
      </div>
    </div>
  </UModal>;
}

function UToasts({ items }) {
  return <div style={{ position: 'fixed', insetBlockEnd: 20, insetInlineEnd: 20, zIndex: 2000, display: 'flex', flexDirection: 'column', gap: 10 }}>
    {items.map((t) => { const c = U_SEV[t.severity] || U_SEV.success; return <div key={t.id} style={{ display: 'flex', alignItems: 'center', gap: 10, minWidth: 260, maxWidth: 360, padding: '12px 14px', background: 'var(--c-card)', border: '1px solid var(--c-border)', borderInlineStart: '3px solid ' + c.fg, borderRadius: 'var(--mg-radius-md)', boxShadow: 'var(--mg-shadow-lg)', animation: 'toastIn .2s ease' }}>
      <i className="pi pi-check-circle" style={{ fontSize: 16, color: c.fg }} /><span style={{ fontSize: 13.5, fontWeight: 500, color: 'var(--c-text)' }}>{t.summary}</span></div>; })}
  </div>;
}

function UIconBtn({ icon, color, title, onClick }) {
  return <button className="uicon" onClick={onClick} title={title} aria-label={title} style={{ width: 32, height: 32, borderRadius: 8, border: 'none', background: 'transparent', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}><i className={'pi ' + icon} style={{ fontSize: 14, color }} /></button>;
}

function UsersTab() {
  const [rows, setRows] = useState(U_USERS);
  const [search, setSearch] = useState(''); const [roleF, setRoleF] = useState(null); const [deptF, setDeptF] = useState(null); const [activeF, setActiveF] = useState(null);
  const [dialog, setDialog] = useState(null); const [toasts, setToasts] = useState([]);
  const [editing, setEditing] = useState(false);
  const pushToast = (summary) => { const id = Date.now() + Math.random(); setToasts((t) => [...t, { id, summary, severity: 'success' }]); setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 2600); };

  const th = { padding: '11px 16px', fontSize: 11.5, fontWeight: 600, letterSpacing: '.03em', textTransform: 'uppercase', color: 'var(--c-muted)', textAlign: 'start', borderBottom: '1px solid var(--c-border)', whiteSpace: 'nowrap' };
  const td = { padding: '10px 16px', fontSize: 13.5, color: 'var(--c-text)', borderBottom: '1px solid var(--c-border)', verticalAlign: 'middle' };
  const filtered = rows.filter((u) => {
    if (search.trim()) { const q = search.trim().toLowerCase(); if (!(u.full_name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q))) return false; }
    if (roleF && u.role !== roleF) return false;
    if (deptF !== null && ((U_DEPTS.find((d) => d.id === deptF) || {}).name) !== u.department_name) return false;
    return true;
  });
  const active = filtered.filter((u) => u.is_active);
  const inactive = filtered.filter((u) => !u.is_active);
  const activeCount = rows.filter((u) => u.is_active).length;

  function saveUser(form, isEdit) {
    if (isEdit) { setRows((rs) => rs.map((u) => u.id === dialog.editing.id ? { ...u, full_name: form.full_name.trim(), email: form.email.trim(), phone: form.phone.trim() || null, job_title: form.job_title.trim() || null, department_name: (U_DEPTS.find((d) => d.id === form.department_id) || {}).name ?? null, role: form.role } : u)); pushToast('Изменения сохранены'); }
    else { const id = Math.max(...rows.map((r) => r.id)) + 1; setRows((rs) => [...rs, { id, full_name: form.full_name.trim(), email: form.email.trim(), phone: form.phone.trim() || null, job_title: form.job_title.trim() || null, department_name: (U_DEPTS.find((d) => d.id === form.department_id) || {}).name ?? null, role: form.role || 'manager', is_active: true }]); pushToast('Пользователь создан'); }
    setDialog(null);
  }
  function doDeactivate(u) { setRows((rs) => rs.map((x) => x.id === u.id ? { ...x, is_active: false } : x)); pushToast('Пользователь деактивирован'); setDialog(null); }
  function doReactivate(u) { setRows((rs) => rs.map((x) => x.id === u.id ? { ...x, is_active: true } : x)); pushToast('Пользователь активирован'); }
  function doReset() { setDialog({ type: 'resetResult', pw: uGenPw() }); }

  const columns = <thead><tr>
    <th style={th}>Сотрудник</th>
    <th style={{ ...th, width: 170 }}>Телефон</th>
    <th style={{ ...th, width: 180 }}>Должность</th>
    <th style={{ ...th, width: 150 }}>Отдел</th>
    <th style={{ ...th, width: 150 }}>Роль</th>
    {editing && <th style={{ ...th, width: 120, textAlign: 'end' }}>Действия</th>}
  </tr></thead>;
  const renderRow = (u, archive) => <tr key={u.id} className="urow" style={{ transition: 'background .12s', opacity: archive ? 0.72 : 1 }}>
    <td style={td}><div style={{ display: 'flex', alignItems: 'center', gap: 11 }}>
      <span style={{ width: 36, height: 36, borderRadius: '50%', flexShrink: 0, background: archive ? 'var(--c-muted2)' : (U_ROLE_GRAD[u.role] || U_ROLE_GRAD.accountant), color: archive ? 'var(--c-muted)' : '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 12.5, fontWeight: 600, letterSpacing: '.02em' }}>{uInitials(u.full_name)}</span>
      <div style={{ minWidth: 0 }}><div style={{ fontWeight: 600, fontSize: 13.5, color: 'var(--c-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{u.full_name}</div>
        <div style={{ fontSize: 12, color: 'var(--c-muted)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{u.email}</div></div>
    </div></td>
    <td style={{ ...td, color: u.phone ? 'var(--c-text2)' : 'var(--c-muted)', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>{u.phone ?? '—'}</td>
    <td style={{ ...td, color: u.job_title ? 'var(--c-text2)' : 'var(--c-muted)' }}>{u.job_title ?? '—'}</td>
    <td style={{ ...td, color: u.department_name ? 'var(--c-text2)' : 'var(--c-muted)' }}>{u.department_name ?? '—'}</td>
    <td style={td}>{u.role ? <UTag value={U_ROLE_LABEL[u.role]} severity={U_ROLE_SEV[u.role]} /> : <span style={{ color: 'var(--c-muted)' }}>—</span>}</td>
    {editing && <td style={td}><div style={{ display: 'flex', gap: 2, justifyContent: 'flex-end' }}>
      <UIconBtn icon="pi-pencil" color="var(--c-text2)" title="Редактировать" onClick={() => setDialog({ type: 'form', editing: u })} />
      {u.id !== U_ME && <UIconBtn icon="pi-key" color="var(--c-text2)" title={U_T.reset.action} onClick={() => setDialog({ type: 'confirm', kind: 'reset', user: u })} />}
      {archive ? <UIconBtn icon="pi-replay" color="var(--mg-status-success-text)" title={U_T.activate} onClick={() => doReactivate(u)} /> : <UIconBtn icon="pi-ban" color="var(--mg-status-danger-text)" title={U_T.deactivate} onClick={() => setDialog({ type: 'confirm', kind: 'deactivate', user: u })} />}
    </div></td>}
  </tr>;

  return <React.Fragment>
    {/* section header */}
    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16, marginBottom: 20 }}>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <h2 style={{ margin: 0, fontSize: 20, fontWeight: 600 }}>{U_T.title}</h2>
          <span style={{ fontSize: 12, fontWeight: 600, padding: '2px 9px', borderRadius: 999, background: 'var(--mg-primary-100)', color: 'var(--mg-primary-900)' }}>{rows.length}</span>
        </div>
        <p style={{ margin: '5px 0 0', fontSize: 13, color: 'var(--c-muted)' }}>{U_T.subtitle} · {activeCount} активных</p>
      </div>
      <div style={{ display: 'flex', gap: 10, flexShrink: 0 }}>
        <button style={editing ? { ...uBtn('ghost'), color: 'var(--mg-primary-900)', borderColor: 'var(--mg-primary-900)' } : uBtn('ghost')} onClick={() => setEditing((e) => !e)}><i className={'pi ' + (editing ? 'pi-check' : 'pi-pencil')} style={{ fontSize: 12 }} />{editing ? 'Завершить редактирование' : 'Редактировать'}</button>
        <button style={uBtn('primary')} onClick={() => setDialog({ type: 'form', editing: null })}><i className="pi pi-plus" style={{ fontSize: 12 }} />{U_T.addUser}</button>
      </div>
    </div>

    {/* toolbar */}
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, height: 40, padding: '0 12px', flex: 1, minWidth: 200, maxWidth: 320, border: '1px solid var(--mg-input-border)', borderRadius: 'var(--mg-radius-md)', background: 'var(--c-card)' }}>
        <i className="pi pi-search" style={{ fontSize: 13, color: 'var(--c-muted)' }} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={U_T.filters.search} style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontFamily: 'var(--mg-font-sans)', fontSize: 14, color: 'var(--c-text)' }} />
      </div>
      <UDropdown value={roleF} onChange={setRoleF} options={U_ROLE_OPTS} placeholder={U_T.filters.role} width={150} />
      <UDropdown value={deptF} onChange={setDeptF} options={U_DEPTS.map((d) => ({ value: d.id, label: d.name }))} placeholder={U_T.filters.department} width={168} />
    </div>

    {/* active table */}
    <div style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 820 }}>
          {columns}
          <tbody>{active.map((u) => renderRow(u, false))}</tbody>
        </table>
      </div>
      {active.length === 0 && <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12, padding: '52px 20px', color: 'var(--c-muted)' }}>
        <i className="pi pi-users" style={{ fontSize: 30, opacity: 0.3 }} /><span style={{ fontSize: 14, color: 'var(--c-text2)' }}>Активные пользователи не найдены</span></div>}
    </div>

    {/* dismissed employees */}
    {inactive.length > 0 && <div style={{ marginTop: 28 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 12 }}>
        <i className="pi pi-user-minus" style={{ fontSize: 14, color: 'var(--c-muted)' }} />
        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 600, color: 'var(--c-text2)' }}>Уволенные</h3>
        <span style={{ fontSize: 11.5, fontWeight: 600, padding: '2px 8px', borderRadius: 999, background: 'var(--c-muted2)', color: 'var(--c-text2)' }}>{inactive.length}</span>
      </div>
      <div style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 820 }}>
            {columns}
            <tbody>{inactive.map((u) => renderRow(u, true))}</tbody>
          </table>
        </div>
      </div>
    </div>}

    {dialog && dialog.type === 'form' && <UUserDialog key={dialog.editing ? 'edit-' + dialog.editing.id : 'new'} editing={dialog.editing} close={() => setDialog(null)} onSave={saveUser} />}
    {dialog && dialog.type === 'confirm' && dialog.kind === 'deactivate' && <UConfirm header={U_T.deactivateTitle} icon="pi-exclamation-triangle" iconColor="var(--mg-status-warning-solid)" message={U_T.deactivateConfirm(dialog.user.full_name)} acceptLabel={U_T.deactivate} acceptKind="danger" onAccept={() => doDeactivate(dialog.user)} close={() => setDialog(null)} />}
    {dialog && dialog.type === 'confirm' && dialog.kind === 'reset' && <UConfirm header={U_T.reset.title} icon="pi-key" iconColor="var(--mg-status-warning-solid)" message={U_T.reset.confirm(dialog.user.full_name)} acceptLabel={U_T.reset.action} acceptKind="warn" onAccept={doReset} close={() => setDialog(null)} />}
    {dialog && dialog.type === 'resetResult' && <UResetResult password={dialog.pw} close={() => setDialog(null)} />}
    <UToasts items={toasts} />
  </React.Fragment>;
}

function uGenPw() { const a = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'; let s = ''; for (let i = 0; i < 12; i++) s += a[Math.floor(Math.random() * a.length)]; return s; }

window.UsersTab = UsersTab;
})();
