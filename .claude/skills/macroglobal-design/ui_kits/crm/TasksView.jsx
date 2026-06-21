// MACRO Global CRM — UI Kit · Tasks (kanban board)
const TASK_COLUMNS = [
  { key: 'overdue', label: 'Просрочено', accent: '#FF5A44', tasks: [
    { id: 1, type: 'call', typeLabel: 'Звонок', sev: 'danger', title: 'Перезвонить по КП', deal: 'Глобус Логистик', due: 'Вчера 16:00', overdue: true },
  ]},
  { key: 'today', label: 'Сегодня', accent: '#EF9F27', tasks: [
    { id: 2, type: 'meeting', typeLabel: 'Встреча', sev: 'success', title: 'Демо MACRO AI Core', deal: 'ТехноПарк', due: 'Сегодня 14:00' },
    { id: 3, type: 'call', typeLabel: 'Звонок', sev: 'success', title: 'Уточнить бюджет', deal: 'ООО «Ромашка»', due: 'Сегодня 17:30' },
  ]},
  { key: 'upcoming', label: 'Предстоящие', accent: '#378ADD', tasks: [
    { id: 4, type: 'follow_up', typeLabel: 'Follow-up', sev: 'info', title: 'Отправить договор', deal: 'СтройИнвест', due: 'Завтра 11:00' },
    { id: 5, type: 'task', typeLabel: 'Задача', sev: 'secondary', title: 'Подготовить презентацию', deal: 'Acme Corp', due: '24.06 10:00' },
  ]},
  { key: 'done', label: 'Выполнено', accent: '#1D9E75', tasks: [
    { id: 6, type: 'note', typeLabel: 'Заметка', sev: 'secondary', title: 'Записать итоги встречи', deal: 'ТехноПарк', due: '20.06', done: true },
  ]},
];

const TASK_ICON = { call: 'pi-phone', meeting: 'pi-calendar', follow_up: 'pi-arrow-right-arrow-left', task: 'pi-check-square', note: 'pi-file-edit' };

function TaskCard({ t }) {
  const { Tag, Avatar } = window.MACROGlobalCRMDesignSystem_2f42e6;
  const [h, setH] = React.useState(false);
  return (
    <div onMouseEnter={() => setH(true)} onMouseLeave={() => setH(false)}
      style={{ background: 'var(--mg-surface-card)', border: '1px solid var(--mg-border-default)', borderRadius: 'var(--mg-radius-md)', padding: '12px', cursor: 'pointer',
        boxShadow: h ? 'var(--mg-shadow-card)' : 'none', opacity: t.done ? 0.65 : 1, transition: 'box-shadow var(--mg-transition-fast)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '8px' }}>
        <Tag size="sm" severity={t.sev} icon={TASK_ICON[t.type]}>{t.typeLabel}</Tag>
        {t.done && <i className="pi pi-check-circle" style={{ fontSize: '14px', color: 'var(--mg-green-700)', marginLeft: 'auto' }} />}
      </div>
      <div style={{ fontSize: '14px', fontWeight: 600, color: 'var(--mg-gray-800)', marginBottom: '6px', textDecoration: t.done ? 'line-through' : 'none' }}>{t.title}</div>
      <div style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12px', color: 'var(--mg-text-muted)', marginBottom: '8px' }}>
        <i className="pi pi-briefcase" style={{ fontSize: '11px' }} />{t.deal}
      </div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '4px', fontSize: '12px', fontWeight: 500, color: t.overdue ? 'var(--mg-danger)' : 'var(--mg-text-secondary)' }}>
          <i className="pi pi-clock" style={{ fontSize: '11px' }} />{t.due}
        </span>
        <Avatar name="MG CRM Admin" size={20} />
      </div>
    </div>
  );
}

function TaskColumn({ col }) {
  return (
    <div style={{ width: '280px', minWidth: '280px', flexShrink: 0, display: 'flex', flexDirection: 'column', maxHeight: '100%' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '8px', padding: '6px 4px 10px' }}>
        <span style={{ width: '8px', height: '8px', borderRadius: '50%', background: col.accent }} />
        <span style={{ fontSize: '14px', fontWeight: 700, color: 'var(--mg-gray-800)' }}>{col.label}</span>
        <span style={{ fontSize: '12px', fontWeight: 600, color: 'var(--mg-text-muted)', background: 'var(--mg-gray-200)', borderRadius: '10px', padding: '1px 8px' }}>{col.tasks.length}</span>
        <i className="pi pi-plus" style={{ marginLeft: 'auto', fontSize: '13px', color: 'var(--mg-text-muted)', cursor: 'pointer' }} />
      </div>
      <div style={{ flex: 1, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '8px', padding: '2px' }}>
        {col.tasks.map((t) => <TaskCard key={t.id} t={t} />)}
      </div>
    </div>
  );
}

function TasksView() {
  return (
    <div style={{ flex: 1, overflow: 'auto', padding: '16px 24px', display: 'flex', gap: '16px', alignItems: 'flex-start', background: 'var(--mg-surface-page)', height: '100%', minHeight: 0 }}>
      {TASK_COLUMNS.map((c) => <TaskColumn key={c.key} col={c} />)}
    </div>
  );
}
window.TasksView = TasksView;
