// MACRO Global CRM — UI Kit · Deals (pipeline board)
const DEALS_STAGES = [
  { id: 1, name: 'Новые лиды', color: '#1D9E75', deals: [
    { id: 11, title: 'REG-Test Lead', amount: '0 ₽', owner: 'MG CRM Admin', daysInStage: 0, health: 'no-task' },
    { id: 12, title: 'ООО «Ромашка» — внедрение CRM', amount: '1 200 000 ₽', product: 'MACRO AI Core', owner: 'Иван Петров', daysInStage: 2, health: 'ok', task: 'Звонок · сегодня 14:00' },
    { id: 13, title: 'Acme Corp — AI assistant pilot', amount: '10 $', owner: 'MG CRM Admin', daysInStage: 0, health: 'no-task' },
  ]},
  { id: 2, name: 'REG-Квалификация', color: '#D4537E', deals: [
    { id: 21, title: 'ТехноПарк — MACRO AI Core', amount: '3 500 000 ₽', product: 'MACRO AI Core', owner: 'Анна Сидорова', daysInStage: 5, health: 'ok', task: 'Встреча · завтра 11:00' },
  ]},
  { id: 3, name: 'Назначить встречу', color: '#378ADD', deals: [
    { id: 31, title: 'Глобус Логистик — интеграция', amount: '180 000 ₽', owner: 'Иван Петров', daysInStage: 21, health: 'overdue', rotting: true, task: 'Встреча просрочена на 3 дня' },
  ]},
  { id: 4, name: 'Встреча', color: '#7F77DD', deals: [
    { id: 41, title: 'СтройИнвест — комплекс', amount: '1 880 000 ₽', owner: 'Анна Сидорова', daysInStage: 4, health: 'ok', task: 'Презентация · 24.06 16:00' },
  ]},
];

function FilterBand() {
  const { Input, Select, Button } = window.MACROGlobalCRMDesignSystem_2f42e6;
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', padding: '12px 24px', background: 'var(--mg-surface-card)', borderBottom: '1px solid var(--mg-border-default)', flexShrink: 0 }}>
      <Input icon="pi-search" placeholder="Поиск по названию" style={{ width: '260px' }} />
      <Select placeholder="Ответственный" />
      <Select placeholder="Компания" />
      <Button variant="primary" text icon="pi-refresh">Сбросить</Button>
    </div>
  );
}

function StageColumn({ stage }) {
  const { KanbanCard } = window.MACROGlobalCRMDesignSystem_2f42e6;
  const amber = stage.color === '#EF9F27';
  const total = stage.deals.length;
  const sumLabel = stage.deals.reduce((s, d) => s, 0);
  return (
    <div style={{ width: '280px', minWidth: '280px', flexShrink: 0, display: 'flex', flexDirection: 'column', background: 'var(--mg-surface-card)', border: '1px solid var(--mg-border-default)', borderRadius: 'var(--mg-radius-lg)', overflow: 'hidden', maxHeight: '100%' }}>
      <div style={{ padding: '12px 12px 8px', borderBottom: '1px solid rgba(0,0,0,0.12)', background: stage.color }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' }}>
          <span style={{ fontSize: '12px', fontWeight: 700, color: amber ? 'rgba(107,74,0,0.7)' : 'rgba(255,255,255,0.7)', minWidth: '1.5rem' }}>{total}</span>
          <span style={{ flex: 1, textAlign: 'center', fontSize: '16px', fontWeight: 700, color: amber ? '#6B4A00' : '#fff', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{stage.name}</span>
          <i className="pi pi-plus" style={{ fontSize: '13px', color: amber ? '#6B4A00' : '#fff', opacity: 0.8, cursor: 'pointer' }} />
        </div>
        <div style={{ textAlign: 'center', fontSize: '12px', fontWeight: 600, color: amber ? 'rgba(107,74,0,0.7)' : 'rgba(255,255,255,0.7)' }}>{stage.sum}</div>
      </div>
      <div style={{ flex: 1, overflowY: 'auto', padding: '8px', display: 'flex', flexDirection: 'column', gap: '8px', minHeight: '80px' }}>
        {stage.deals.map((d) => <KanbanCard key={d.id} {...d} />)}
      </div>
    </div>
  );
}

function DealsView() {
  const stages = DEALS_STAGES.map((s) => ({ ...s, sum: ({1:'1 200 010 ₽',2:'3 500 000 ₽',3:'180 000 ₽',4:'1 880 000 ₽'})[s.id] }));
  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100%', minHeight: 0 }}>
      <FilterBand />
      <div style={{ flex: 1, overflow: 'auto', padding: '16px 24px', display: 'flex', gap: '14px', alignItems: 'flex-start', background: 'var(--mg-surface-page)' }}>
        {stages.map((s) => <StageColumn key={s.id} stage={s} />)}
      </div>
    </div>
  );
}
window.DealsView = DealsView;
