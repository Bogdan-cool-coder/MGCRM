/* System sections for Настройки → Система:
   - Журнал автоматизаций (AutomationsTab): read-only execution log of automation rules.
   - Сброс системы (ResetTab): selective data wipe — checkbox choice of which datasets to delete
     (unlike the product's full-wipe behaviour, per request).
   Built to match the fresh MACRO CRM look (shared --c-* / --mg-* tokens, table th/td, tags).
   Exposes window.AutomationsTab and window.ResetTab. */
(function () {
const { useState } = React;

/* ── shared button/tag helpers (mirror settings.html btn()) ── */
function sBtn(kind) {
  const base = { display: 'inline-flex', alignItems: 'center', gap: 7, height: 38, padding: '0 15px', borderRadius: 'var(--mg-radius-md)', fontFamily: 'var(--mg-font-sans)', fontSize: 13, fontWeight: 600, cursor: 'pointer', border: 'none', whiteSpace: 'nowrap' };
  if (kind === 'primary') return { ...base, background: 'var(--mg-primary-900)', color: '#fff' };
  if (kind === 'danger') return { ...base, background: 'var(--mg-status-danger-solid)', color: '#fff' };
  return { ...base, background: 'transparent', color: 'var(--c-text2)', border: '1px solid var(--c-border2)' };
}
const sTh = { padding: '11px 16px', fontSize: 12, fontWeight: 600, color: 'var(--c-text2)', textAlign: 'start', borderBottom: '1px solid var(--c-border)', whiteSpace: 'nowrap', background: 'var(--c-card)' };
const sTd = { padding: '12px 16px', fontSize: 13.5, color: 'var(--c-text)', borderBottom: '1px solid var(--c-border)', verticalAlign: 'middle' };

function SCheck({ checked, onChange, disabled, indeterminate }) {
  const active = checked || indeterminate;
  return <span onClick={disabled ? undefined : (e) => { e.stopPropagation(); onChange(); }} role="checkbox" aria-checked={checked} style={{ width: 20, height: 20, borderRadius: 6, border: '1.5px solid ' + (active ? 'var(--mg-primary-900)' : 'var(--c-border2)'), background: active ? 'var(--mg-primary-900)' : 'var(--c-card)', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: disabled ? 'not-allowed' : 'pointer', flexShrink: 0, opacity: disabled ? 0.45 : 1, transition: 'background .12s, border-color .12s' }}>
    {checked ? <i className="pi pi-check" style={{ fontSize: 11, color: '#fff' }} /> : indeterminate ? <span style={{ width: 9, height: 2, borderRadius: 1, background: '#fff' }} /> : null}
  </span>;
}

/* ═══════════════ Журнал автоматизаций ═══════════════ */
const S_TRIG = {
  created:  { label: 'Сделка создана', ic: 'pi-plus-circle' },
  stage:    { label: 'Этап изменён', ic: 'pi-sort-alt' },
  overdue:  { label: 'Задача просрочена', ic: 'pi-exclamation-circle' },
  webform:  { label: 'Заявка с сайта', ic: 'pi-globe' },
  lost:     { label: 'Статус «Отказ»', ic: 'pi-times-circle' },
  product:  { label: 'Товар добавлен', ic: 'pi-box' },
  contact:  { label: 'Контакт создан', ic: 'pi-user-plus' },
  noreply:  { label: 'Нет ответа 24ч', ic: 'pi-clock' },
  won:      { label: 'Сделка выиграна', ic: 'pi-check-circle' },
  birthday: { label: 'Дата рождения', ic: 'pi-calendar' }
};
const S_LOG = [
  { time: '02.07 · 09:41', name: 'Приветственное сообщение', trig: 'created', obj: 'Сделка #10485', link: true, action: 'Отправлено сообщение в Telegram', status: 'ok' },
  { time: '02.07 · 09:38', name: 'Задача при смене этапа', trig: 'stage', obj: 'Сделка #10477', link: true, action: 'Создана задача «Позвонить клиенту»', status: 'ok' },
  { time: '02.07 · 09:36', name: 'Автоназначение ответственного', trig: 'webform', obj: 'Сделка #10484', link: true, action: 'Назначен Иванов А. С.', status: 'ok' },
  { time: '02.07 · 09:30', name: 'Уведомление при отказе', trig: 'lost', obj: 'Сделка #10460', link: true, action: 'Email руководителю — SMTP timeout', status: 'err' },
  { time: '02.07 · 09:24', name: 'Пересчёт суммы сделки', trig: 'product', obj: 'Сделка #10483', link: true, action: 'Обновлено поле «Сумма» → 480 000 ₽', status: 'ok' },
  { time: '02.07 · 09:19', name: 'Напоминание о просрочке', trig: 'overdue', obj: 'Задача #3391', link: false, action: 'Уведомление ответственному', status: 'ok' },
  { time: '02.07 · 09:12', name: 'Дедупликация контактов', trig: 'contact', obj: 'Контакт «Петров И.»', link: true, action: 'Найден дубль — объединено', status: 'ok' },
  { time: '02.07 · 08:58', name: 'SLA-эскалация', trig: 'noreply', obj: 'Сделка #10471', link: true, action: 'Правило отключено — пропущено', status: 'skip' },
  { time: '02.07 · 08:45', name: 'Синхронизация с 1С', trig: 'won', obj: 'Сделка #10455', link: true, action: 'Выгрузка в 1С — нет связи с сервером', status: 'err' },
  { time: '02.07 · 08:30', name: 'Поздравление с днём рождения', trig: 'birthday', obj: 'Контакт «Сидорова М.»', link: true, action: 'Email отправлен', status: 'ok' }
];
const S_STATUS = {
  ok:   { label: 'Успешно', bg: 'var(--mg-status-success-bg)', fg: 'var(--mg-status-success-text)', ic: 'pi-check-circle' },
  err:  { label: 'Ошибка', bg: 'var(--mg-status-danger-bg)', fg: 'var(--mg-status-danger-text)', ic: 'pi-times-circle' },
  skip: { label: 'Пропущено', bg: 'var(--c-muted2)', fg: 'var(--c-text2)', ic: 'pi-minus-circle' }
};

function AutomationsTab() {
  const [q, setQ] = useState('');
  const [f, setF] = useState('all');
  const counts = { all: S_LOG.length, ok: 0, err: 0, skip: 0 };
  S_LOG.forEach((r) => counts[r.status]++);
  const ql = q.trim().toLowerCase();
  const rows = S_LOG.filter((r) => (f === 'all' || r.status === f) && (!ql || r.name.toLowerCase().includes(ql) || r.obj.toLowerCase().includes(ql) || S_TRIG[r.trig].label.toLowerCase().includes(ql)));

  return <React.Fragment>
    <h2 style={{ margin: '2px 0 4px', fontSize: 22, fontWeight: 600 }}>Журнал автоматизаций</h2>
    <p style={{ margin: '0 0 20px', fontSize: 13, color: 'var(--c-muted)' }}>История срабатывания правил автоматизации за последние 24 часа</p>

    {/* toolbar */}
    <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, height: 40, padding: '0 12px', flex: 1, minWidth: 200, maxWidth: 300, border: '1px solid var(--mg-input-border)', borderRadius: 'var(--mg-radius-md)', background: 'var(--c-card)' }}>
        <i className="pi pi-search" style={{ fontSize: 13, color: 'var(--c-muted)' }} />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Поиск по правилу или объекту…" style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontFamily: 'var(--mg-font-sans)', fontSize: 14, color: 'var(--c-text)' }} />
      </div>
      <div style={{ display: 'inline-flex', padding: 3, gap: 2, background: 'var(--c-muted2)', borderRadius: 'var(--mg-radius-md)' }}>
        {[['all', 'Все'], ['ok', 'Успешно'], ['err', 'Ошибка'], ['skip', 'Пропущено']].map(([k, l]) => <button key={k} onClick={() => setF(k)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, height: 32, padding: '0 12px', border: 'none', borderRadius: 'var(--mg-radius-sm)', cursor: 'pointer', fontFamily: 'var(--mg-font-sans)', fontSize: 12.5, fontWeight: 600, background: f === k ? 'var(--c-card)' : 'transparent', color: f === k ? 'var(--mg-primary-900)' : 'var(--c-text2)', boxShadow: f === k ? 'var(--mg-shadow-sm)' : 'none' }}>{l}<span style={{ fontSize: 11, color: 'var(--c-muted)' }}>{counts[k]}</span></button>)}
      </div>
      <span style={{ flex: 1 }} />
      <button style={sBtn('ghost')}><i className="pi pi-refresh" style={{ fontSize: 12 }} />Обновить</button>
    </div>

    {/* table */}
    <div style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 860 }}>
        <thead><tr>
          <th style={{ ...sTh, width: 108 }}>Время</th>
          <th style={sTh}>Автоматизация</th>
          <th style={sTh}>Триггер</th>
          <th style={sTh}>Объект</th>
          <th style={sTh}>Действие</th>
          <th style={{ ...sTh, width: 130 }}>Статус</th>
        </tr></thead>
        <tbody>{rows.map((r, i) => { const st = S_STATUS[r.status]; const tg = S_TRIG[r.trig]; return <tr key={i} className="urow" style={{ background: i % 2 ? 'var(--c-hover)' : 'var(--c-card)' }}>
          <td style={{ ...sTd, fontFamily: 'ui-monospace,monospace', fontSize: 12, color: 'var(--c-muted)', whiteSpace: 'nowrap' }}>{r.time}</td>
          <td style={{ ...sTd, fontWeight: 600 }}>{r.name}</td>
          <td style={sTd}><span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12.5, fontWeight: 500, padding: '3px 9px', borderRadius: 6, background: 'var(--c-muted2)', color: 'var(--c-text2)', whiteSpace: 'nowrap' }}><i className={'pi ' + tg.ic} style={{ fontSize: 11 }} />{tg.label}</span></td>
          <td style={{ ...sTd, whiteSpace: 'nowrap' }}><span style={{ color: r.link ? 'var(--mg-primary-900)' : 'var(--c-text)', fontWeight: r.link ? 500 : 400, cursor: r.link ? 'pointer' : 'default' }}>{r.obj}</span></td>
          <td style={{ ...sTd, color: 'var(--c-text2)' }}>{r.action}</td>
          <td style={sTd}><span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 600, padding: '3px 10px', borderRadius: 999, background: st.bg, color: st.fg, whiteSpace: 'nowrap' }}><i className={'pi ' + st.ic} style={{ fontSize: 11 }} />{st.label}</span></td>
        </tr>; })}</tbody>
      </table></div>
      {rows.length === 0 && <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 8, padding: '48px 20px', color: 'var(--c-muted)' }}><i className="pi pi-clock" style={{ fontSize: 26, opacity: 0.4 }} /><span style={{ fontSize: 14, color: 'var(--c-text2)', fontWeight: 500 }}>Записи не найдены</span></div>}
    </div>
  </React.Fragment>;
}

/* ═══════════════ Сброс системы ═══════════════ */
const S_CATS = [
  { key: 'deals', icon: 'pi-briefcase', name: 'Сделки', desc: 'Все сделки и их история изменений', count: '1 248 записей' },
  { key: 'contacts', icon: 'pi-user', name: 'Контакты', desc: 'Контактные лица и их данные', count: '3 972 записи' },
  { key: 'companies', icon: 'pi-building', name: 'Компании', desc: 'Организации-клиенты', count: '864 записи' },
  { key: 'tasks', icon: 'pi-check-square', name: 'Задачи и активности', desc: 'Задачи, звонки, встречи, заметки', count: '5 310 записей' },
  { key: 'docs', icon: 'pi-file', name: 'Документы и файлы', desc: 'Договоры и вложения', count: '2 140 файлов' },
  { key: 'finance', icon: 'pi-wallet', name: 'Финансовые операции', desc: 'Платежи, счета, проводки', count: '918 записей' },
  { key: 'logs', icon: 'pi-history', name: 'Журналы и история', desc: 'Журнал автоматизаций и аудита', count: '42 300 событий' },
  { key: 'automations', icon: 'pi-bolt', name: 'Правила автоматизаций', desc: 'Настроенные сценарии', count: '36 правил' },
  { key: 'directories', icon: 'pi-folder-open', name: 'Справочники', desc: 'Товары, теги, поля, курсы валют', count: '512 записей' }
];
const S_CONFIRM_WORD = 'СБРОСИТЬ';

function ResetTab() {
  const [sel, setSel] = useState({});
  const [word, setWord] = useState('');
  const [confirm, setConfirm] = useState(false);
  const [done, setDone] = useState(false);
  const selKeys = S_CATS.filter((c) => sel[c.key]).map((c) => c.key);
  const n = selKeys.length;
  const allOn = n === S_CATS.length;
  const toggle = (k) => setSel((s) => ({ ...s, [k]: !s[k] }));
  const toggleAll = () => { const v = !allOn; const m = {}; S_CATS.forEach((c) => { m[c.key] = v; }); setSel(m); };
  const canReset = n > 0 && word.trim().toUpperCase() === S_CONFIRM_WORD;
  function doReset() { setConfirm(false); setDone(true); setSel({}); setWord(''); setTimeout(() => setDone(false), 3600); }

  return <React.Fragment>
    <h2 style={{ margin: '2px 0 4px', fontSize: 22, fontWeight: 600 }}>Сброс системы</h2>
    <p style={{ margin: '0 0 20px', fontSize: 13, color: 'var(--c-muted)' }}>Выборочное удаление данных из системы. Настройки аккаунта и учётные записи сохраняются.</p>

    {/* danger banner */}
    <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start', padding: '14px 16px', marginBottom: 16, background: 'var(--mg-status-danger-bg)', border: '1px solid var(--mg-status-danger-border)', borderRadius: 'var(--mg-radius-lg)' }}>
      <i className="pi pi-exclamation-triangle" style={{ fontSize: 18, color: 'var(--mg-status-danger-text)', marginTop: 1, flexShrink: 0 }} />
      <div style={{ fontSize: 13.5, color: 'var(--c-text2)', lineHeight: 1.55 }}><b style={{ color: 'var(--mg-status-danger-text)', fontWeight: 600 }}>Операция необратима.</b> Выбранные данные будут удалены безвозвратно. Перед сбросом рекомендуем выгрузить резервную копию.</div>
    </div>

    {/* selection card */}
    <div style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', overflow: 'hidden', marginBottom: 16 }}>
      <div onClick={toggleAll} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '13px 16px', borderBottom: '1px solid var(--c-border)', background: 'var(--c-hover)', cursor: 'pointer' }}>
        <SCheck checked={allOn} indeterminate={n > 0 && !allOn} onChange={toggleAll} />
        <span style={{ flex: 1, fontSize: 13.5, fontWeight: 600 }}>Выбрать всё</span>
        <span style={{ fontSize: 12.5, color: 'var(--c-muted)', whiteSpace: 'nowrap', flexShrink: 0 }}>Выбрано: {n} из {S_CATS.length}</span>
      </div>
      {S_CATS.map((c, i) => { const on = !!sel[c.key]; return <div key={c.key} onClick={() => toggle(c.key)} style={{ display: 'flex', alignItems: 'center', gap: 13, padding: '13px 16px', borderBottom: i < S_CATS.length - 1 ? '1px solid var(--c-border)' : 'none', cursor: 'pointer', background: on ? 'var(--mg-primary-100)' : 'transparent', transition: 'background .12s' }}>
        <SCheck checked={on} onChange={() => toggle(c.key)} />
        <span style={{ width: 36, height: 36, borderRadius: 'var(--mg-radius-md)', background: 'var(--c-muted2)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><i className={'pi ' + c.icon} style={{ fontSize: 15, color: 'var(--c-text2)' }} /></span>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--c-text)' }}>{c.name}</div>
          <div style={{ fontSize: 12.5, color: 'var(--c-muted)', marginTop: 2 }}>{c.desc}</div>
        </div>
        <span style={{ fontSize: 12, fontWeight: 600, padding: '3px 10px', borderRadius: 999, background: 'var(--c-muted2)', color: 'var(--c-text2)', whiteSpace: 'nowrap' }}>{c.count}</span>
      </div>; })}
    </div>

    {/* confirm card */}
    <div style={{ background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-sm)', padding: '18px 20px' }}>
      <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 4 }}>Подтверждение</div>
      <div style={{ fontSize: 13, color: 'var(--c-muted)', marginBottom: 12 }}>Чтобы удалить {n > 0 ? 'выбранные данные (' + n + ')' : 'выбранные данные'}, введите <b style={{ color: 'var(--c-text2)', fontFamily: 'ui-monospace,monospace' }}>{S_CONFIRM_WORD}</b> в поле ниже.</div>
      <div style={{ display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
        <input className="inp" value={word} onChange={(e) => setWord(e.target.value)} placeholder={S_CONFIRM_WORD} style={{ flex: 1, minWidth: 200, maxWidth: 280, height: 40, padding: '0 13px', borderRadius: 'var(--mg-radius-md)', border: '1px solid ' + (word && !canReset && n > 0 ? 'var(--mg-status-danger-border)' : 'var(--mg-input-border)'), background: 'var(--c-card)', color: 'var(--c-text)', fontFamily: 'ui-monospace,monospace', fontSize: 14, letterSpacing: '0.05em' }} />
        <span style={{ flex: 1 }} />
        <button disabled={!canReset} onClick={() => setConfirm(true)} style={{ ...sBtn('danger'), opacity: canReset ? 1 : 0.45, pointerEvents: canReset ? 'auto' : 'none' }}><i className="pi pi-trash" style={{ fontSize: 13 }} />Удалить выбранное{n > 0 ? ' (' + n + ')' : ''}</button>
      </div>
    </div>

    {/* final confirm modal */}
    {confirm && <div onClick={() => setConfirm(false)} style={{ position: 'fixed', inset: 0, zIndex: 1500, background: 'rgba(9,16,32,0.55)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ width: 460, maxWidth: '92vw', background: 'var(--c-card)', border: '1px solid var(--c-border)', borderRadius: 'var(--mg-radius-lg)', boxShadow: 'var(--mg-shadow-lg)' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '16px 20px', borderBottom: '1px solid var(--c-border)' }}><span style={{ flex: 1, fontSize: 16, fontWeight: 600 }}>Удалить данные?</span><i className="pi pi-times" onClick={() => setConfirm(false)} style={{ fontSize: 14, color: 'var(--c-muted)', cursor: 'pointer' }} /></div>
        <div style={{ padding: 20, display: 'flex', gap: 14, alignItems: 'flex-start' }}>
          <span style={{ width: 42, height: 42, borderRadius: '50%', background: 'var(--mg-status-danger-bg)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}><i className="pi pi-exclamation-triangle" style={{ fontSize: 18, color: 'var(--mg-status-danger-text)' }} /></span>
          <div style={{ fontSize: 14, color: 'var(--c-text2)', lineHeight: 1.55, paddingTop: 2 }}>Будет безвозвратно удалено <b style={{ color: 'var(--c-text)' }}>{n}</b> {n === 1 ? 'категория' : n < 5 ? 'категории' : 'категорий'} данных: {selKeys.map((k) => (S_CATS.find((c) => c.key === k) || {}).name).join(', ')}. Продолжить?</div>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, padding: '14px 20px', borderTop: '1px solid var(--c-border)' }}><button style={sBtn('ghost')} onClick={() => setConfirm(false)}>Отмена</button><button style={sBtn('danger')} onClick={doReset}><i className="pi pi-trash" style={{ fontSize: 13 }} />Удалить безвозвратно</button></div>
      </div>
    </div>}

    {/* success toast */}
    {done && <div style={{ position: 'fixed', insetBlockEnd: 20, insetInlineEnd: 20, zIndex: 2000, display: 'flex', alignItems: 'center', gap: 10, minWidth: 260, padding: '12px 14px', background: 'var(--c-card)', border: '1px solid var(--c-border)', borderInlineStart: '3px solid var(--mg-status-success-text)', borderRadius: 'var(--mg-radius-md)', boxShadow: 'var(--mg-shadow-lg)', animation: 'toastIn .2s ease' }}><i className="pi pi-check-circle" style={{ fontSize: 16, color: 'var(--mg-status-success-text)' }} /><span style={{ fontSize: 13.5, fontWeight: 500 }}>Выбранные данные удалены</span></div>}
  </React.Fragment>;
}

window.AutomationsTab = AutomationsTab;
window.ResetTab = ResetTab;
})();
