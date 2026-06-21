// MACRO Global CRM — UI Kit · Contacts (data table)
const CONTACTS = [
  { id: 1, name: 'REG-Тест Физлицо Изменённое', type: 'Физлицо', source: '—', tags: [] },
  { id: 2, name: 'Иван Петров', type: 'Физлицо', source: 'Сайт', tags: ['VIP'] },
  { id: 3, name: 'Анна Сидорова', type: 'Физлицо', source: 'Реклама', tags: ['Тёплый'] },
  { id: 4, name: 'ООО «Ромашка»', type: 'Компания', source: 'Партнёр', tags: ['Договор', 'L'] },
  { id: 5, name: 'ТехноПарк', type: 'Компания', source: 'Выставка', tags: ['S1'] },
  { id: 6, name: 'Глобус Логистик', type: 'Компания', source: 'Холодный обзвон', tags: [] },
  { id: 7, name: 'СтройИнвест', type: 'Компания', source: 'Сайт', tags: ['Тёплый'] },
];

function ContactsToolbar({ entity, setEntity }) {
  const { Select, Button } = window.MACROGlobalCRMDesignSystem_2f42e6;
  const Seg = ({ k, label }) => {
    const active = entity === k;
    return (
      <button onClick={() => setEntity(k)}
        style={{ fontFamily: 'var(--mg-font-sans)', fontSize: '14px', fontWeight: 600, padding: '7px 16px', border: 'none', cursor: 'pointer',
          background: active ? 'var(--mg-surface-card)' : 'transparent', color: active ? 'var(--mg-primary-900)' : 'var(--mg-text-muted)',
          borderRadius: '5px', boxShadow: active ? 'var(--mg-shadow-sm)' : 'none' }}>{label}</button>
    );
  };
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '12px', padding: '12px 24px', background: 'var(--mg-surface-card)', borderBottom: '1px solid var(--mg-border-default)', flexShrink: 0 }}>
      <div style={{ display: 'inline-flex', gap: '2px', background: 'var(--mg-gray-100)', borderRadius: '7px', padding: '3px' }}>
        <Seg k="contact" label="Физлицо" /><Seg k="company" label="Компания" />
      </div>
      <Select placeholder="Источник" />
      <Select placeholder="Страна" />
      <Button variant="primary" icon="pi-check">Применить</Button>
      <Button variant="primary" text icon="pi-refresh">Сбросить</Button>
    </div>
  );
}

function ContactsView() {
  const { Tag, Button } = window.MACROGlobalCRMDesignSystem_2f42e6;
  const [entity, setEntity] = React.useState('contact');
  const rows = CONTACTS.filter((c) => entity === 'contact' ? c.type === 'Физлицо' : c.type === 'Компания');
  const tagSev = (t) => ({ L: 'danger', S1: 'success', VIP: 'secondary', Договор: 'primary', 'Тёплый': 'warning' }[t] || 'secondary');
  const th = { textAlign: 'left', padding: '12px 16px', fontSize: '13px', fontWeight: 600, color: 'var(--mg-text-secondary)', borderBottom: '1px solid var(--mg-border-default)', whiteSpace: 'nowrap' };
  const td = { padding: '11px 16px', fontSize: '14px', color: 'var(--mg-text-primary)', borderBottom: '1px solid var(--mg-gray-100)' };

  const Row = ({ c, i }) => {
    const [h, setH] = React.useState(false);
    return (
      <tr onMouseEnter={() => setH(true)} onMouseLeave={() => setH(false)} style={{ background: h ? 'var(--mg-gray-50)' : (i % 2 ? 'var(--mg-gray-50)' : 'var(--mg-surface-card)'), cursor: 'pointer' }}>
        <td style={{ ...td, color: 'var(--mg-text-muted)', width: '60px' }}>{c.id}</td>
        <td style={td}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: '8px', color: 'var(--mg-primary-900)', fontWeight: 500 }}>
            <i className={`pi ${c.type === 'Компания' ? 'pi-building' : 'pi-user'}`} style={{ fontSize: '13px', color: 'var(--mg-text-muted)' }} />{c.name}
          </span>
        </td>
        <td style={td}><Tag severity={c.type === 'Компания' ? 'primary' : 'info'}>{c.type}</Tag></td>
        <td style={{ ...td, color: c.source === '—' ? 'var(--mg-text-muted)' : 'var(--mg-text-secondary)' }}>{c.source}</td>
        <td style={td}>
          <span style={{ display: 'inline-flex', gap: '4px' }}>
            {c.tags.length ? c.tags.map((t) => <Tag key={t} size="sm" severity={tagSev(t)}>{t}</Tag>) : <span style={{ color: 'var(--mg-text-muted)' }}>—</span>}
          </span>
        </td>
        <td style={{ ...td, width: '60px', textAlign: 'right' }}><Button variant="secondary" text icon="pi-ellipsis-v" size="sm" /></td>
      </tr>
    );
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%', minHeight: 0, background: 'var(--mg-surface-page)' }}>
      <ContactsToolbar entity={entity} setEntity={setEntity} />
      <div style={{ flex: 1, overflow: 'auto', padding: '16px 24px', minHeight: 0 }}>
        <div style={{ background: 'var(--mg-surface-card)', border: '1px solid var(--mg-border-default)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-card)', overflow: 'hidden' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontFamily: 'var(--mg-font-sans)' }}>
            <thead><tr>
              <th style={{ ...th, width: '60px' }}>#</th>
              <th style={th}>Название / ФИО</th>
              <th style={th}>Тип</th>
              <th style={th}>Источник</th>
              <th style={th}>Теги</th>
              <th style={{ ...th, textAlign: 'right' }}>Действия</th>
            </tr></thead>
            <tbody>{rows.map((c, i) => <Row key={c.id} c={c} i={i} />)}</tbody>
          </table>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '6px', padding: '12px', borderTop: '1px solid var(--mg-gray-100)' }}>
            {['pi-angle-double-left', 'pi-angle-left'].map((p) => <i key={p} className={`pi ${p}`} style={{ fontSize: '13px', color: 'var(--mg-text-muted)', cursor: 'pointer', padding: '4px' }} />)}
            <span style={{ minWidth: '28px', height: '28px', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', borderRadius: '5px', background: 'var(--mg-primary-900)', color: '#fff', fontSize: '13px', fontWeight: 600 }}>1</span>
            {['pi-angle-right', 'pi-angle-double-right'].map((p) => <i key={p} className={`pi ${p}`} style={{ fontSize: '13px', color: 'var(--mg-text-muted)', cursor: 'pointer', padding: '4px' }} />)}
          </div>
        </div>
        <div style={{ textAlign: 'right', fontSize: '13px', color: 'var(--mg-text-muted)', marginTop: '10px' }}>Итого: {rows.length}</div>
      </div>
    </div>
  );
}
window.ContactsView = ContactsView;
