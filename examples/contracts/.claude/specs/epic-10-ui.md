# ТЗ: Эпик 10 UI — Закрытие топ UX-долгов (audit-driven)

**Зачем:** Устранить критические UX-барьеры выявленные аудитом — перегруженный Sidebar, сломанная карточка контрагента, отсутствие рабочего контекста на дашборде менеджера, визуальный шум в канбане и отсутствие единообразия в пустых состояниях и терминологии.

---

## Где в коде (полный список изменяемых файлов)

**Изменяемые страницы:**
- `apps/web/src/app/(app)/dashboard/page.tsx`
- `apps/web/src/app/(app)/counterparties/[id]/page.tsx`
- `apps/web/src/app/(app)/deals/page.tsx`
- `apps/web/src/app/(app)/leads/[id]/page.tsx`
- `apps/web/src/app/(app)/contacts/[id]/page.tsx`

**Изменяемые существующие компоненты:**
- `apps/web/src/components/Sidebar.tsx`
- `apps/web/src/components/DealModal.tsx`
- `apps/web/src/components/Leads/LeadFormModal.tsx`
- `apps/web/src/components/Leads/LeadConvertModal.tsx`

**Новые компоненты:**
- `apps/web/src/components/Dashboard/MyTasksWidget.tsx`
- `apps/web/src/components/Dashboard/HotDealsWidget.tsx`
- `apps/web/src/components/Counterparty/CounterpartyRightRail.tsx`
- `apps/web/src/components/Counterparty/CounterpartyInlineField.tsx`
- `apps/web/src/components/Deals/KanbanCard.tsx`
- `apps/web/src/components/Deals/LostReasonModal.tsx`
- `apps/web/src/components/Deals/StagePopover.tsx`
- `apps/web/src/components/EmptyState.tsx`
- `apps/web/src/components/RoleGate.tsx`
- `apps/web/src/components/Leads/LeadScoreIndicator.tsx`

---

## Задача 1. Sidebar — группировка и коллапс «Настройки»

3 секции:
- **Продажи** (без заголовка, всегда): Дашборд, Лиды, Сделки, Контрагенты, Контакты, Компании
- **CS** (subtle-заголовок `CS` всегда): Реестр клиентов, Входящие, Договоры
- **Настройки** (collapsible default closed, только для admin/director/lawyer): 15+ admin-пунктов
- localStorage `admin_sidebar_expanded` (по аналогии `segments_sidebar_expanded`)
- Manager видит только секции Продажи + CS (12 пунктов вместо 27)

## Задача 2. `<EmptyState icon title description? cta?>`

Унифицированный компонент. Применить в:
- /deals пустая колонка
- /counterparties/[id] табы deals/tasks/notes/contacts/documents
- /leads/[id] и /contacts/[id] timeline
- MyTasksWidget и HotDealsWidget

## Задача 3. `<RoleGate allowed fallback?>`

Использует `useMe()`. Применить в Sidebar.

## Задача 4. Dashboard виджеты «Мои задачи» + «Горящие сделки»

Сверху страницы, до status-tiles:
```
grid grid-cols-1 lg:grid-cols-2 gap-4
  <MyTasksWidget />     <HotDealsWidget />
```

### MyTasksWidget
- SWR `/activities?responsible_id=me&status=open&page_size=5&sort=due_at_asc`
- Тип `MyTask { id, kind, title, due_at, target_type, target_id, target_name?, is_overdue }`
- 5 строк: иконка kind + title + due_at (overdue=red) + link на target
- Footer: «Все мои задачи →» (`/activities?my=true`)
- Empty: EmptyState с `bi-check2-circle`

### HotDealsWidget
- SWR `/deals/hot?owner=me&limit=5` (backend нужно создать)
- Тип `HotDeal { id, title, amount, currency, stage_name, stage_color, idle_days, days_to_close, heat_reason, counterparty_name? }`
- Heat-rules backend: `idle_in_stage > 3d OR (expected_close_date - today) < 7d`
- Fallback если /deals/hot нет: clientside filter из /deals/board
- Empty: EmptyState с `bi-fire`

PageHeader description: «Рабочий стол» (вместо «Сводка и аналитика по договорам»).

## Задача 5. Карточка контрагента — Right Rail + inline-edit

### Layout
```
flex gap-0 min-h-screen
  flex-1 min-w-0 (контент)
    px-8 pt-4 border-b flex gap-1 (tabs)
    p-8 (tab content)
  w-80 shrink-0 border-l (right rail)
    <CounterpartyRightRail />
```

### Tabs (8 вместо 9 — `overview` упразднён)
`timeline | tasks | deals | contacts | subscriptions | documents | notes | audit`

Default → `timeline`. Tab `tasks` имеет badge с количеством открытых.

### PageHeader actions (новые CTA)
- `Задача` (btn-secondary, bi-check2-square)
- `Сделка` (btn-secondary, bi-kanban)
- `Договор` (btn-primary, bi-plus-lg) → `/contracts/new?counterparty=${id}`
- `bi-arrow-left` (btn-ghost) → `/counterparties`
+ CategoryBadge показывать в actions

### CounterpartyRightRail компонент

```tsx
interface CounterpartyRightRailProps {
  cp: Counterparty;
  dealsCount: number;
  openTasksCount: number;
  lastActivityAt: string | null;
  users: User[] | undefined;
  onOwnerChange: (userId: number | null) => void;
  onSaved: () => void;
}
```

Структура сверху вниз:
1. `<h2 className="text-base font-semibold text-primary leading-tight">{legal_form} «{name}»</h2>`
2. Бейджи: HealthBadge (если есть подписки) + CategoryBadge + country chip
3. Ответственный (CounterpartyInlineField, click → UserSelect popover) → PATCH `/counterparties/{id}` body `{ responsible_user_id }`
4. Quick stats card (`bg-gray-50 rounded-lg px-3 py-2 text-xs space-y-1`):
   - `{N} сделок · {N} задач`
   - `Последняя активность: {N} дн. назад` / `сегодня`
5. Accordion «Реквизиты» (default closed). Поля inline-edit:
   - ИНН/БИН (по country), Подписант, Телефон, Email, Адрес, Банк/Счёт
   - Footer: `Все реквизиты →` (заглушка в Эпике 10)

### CounterpartyInlineField компонент

```tsx
interface CounterpartyInlineFieldProps {
  label: string;
  value: string | null;
  onSave: (newValue: string) => Promise<void>;
  type?: "text" | "email" | "tel";
  placeholder?: string;
}
```

UX:
- Display mode: span + `bi-pencil` иконка на hover (opacity-0 → 100)
- Click → `<input className="input text-sm py-1" autoFocus />`
- `onBlur` или Enter → onSave → PATCH → revert to display mode
- Escape → отмена без save
- Loading: disabled + opacity-50
- Error: inline `text-danger text-xs` под полем

### УДАЛИТЬ
- Tab `overview` (полностью)
- Кнопку «Изменить реквизиты (в списке)» (router.push("/counterparties")) — главный антипаттерн

## Задача 6. Kanban /deals — убрать `<select>` из карточки

### KanbanCard компонент

```tsx
interface KanbanCardProps {
  deal: Deal;
  stages: PipelineStage[];
  counterpartyName: string | undefined;
  userName: string | undefined;
  onMove: (dealId: number, stageId: number) => void;
  onOpen: (deal: Deal) => void;
  onDelete?: (dealId: number) => void;
}
```

- Draggable card с relative + group
- Kebab `bi-three-dots-vertical` — absolute top-2 right-2, opacity-0 group-hover:opacity-100
- Контент (title + counterparty + amount + userName) — click → onOpen
- Close-badge если expected_close_date < 7 дней (text-danger / warning / gray)

### KanbanCardMenu (внутренний popover)
- Открыть карточку
- Перевести в этап → открывает StagePopover
- Удалить (text-danger, если onDelete передан)

### StagePopover (отдельный файл)
```tsx
interface StagePopoverProps {
  stages: PipelineStage[];
  currentStageId: number;
  onSelect: (stageId: number) => void;
  onClose: () => void;
}
```

### Заголовок колонки
Добавить сумму: `{stage.name} · {count} · {sum} ₽`

`fmtColAmount(deals)` — sum RUB/null only, разные валюты → "—".

### УДАЛИТЬ `<select>` со всеми этапами из inline-карточки.

## Задача 7. LostReasonModal — при переходе в lost-этап

```tsx
interface LostReasonModalProps {
  open: boolean;
  dealId: number;
  targetStageId: number;
  onClose: () => void;
  onConfirmed: () => void;
}

const LOST_REASON_PRESETS = ["Цена", "Конкурент", "Не подошло", "Передумали", "Не наш сегмент", "Другое"] as const;
```

Логика:
- Click пресет → заполняет textarea (или ` · {preset}` append)
- Textarea обязательна (`disabled={!reason.trim()}`)
- Submit: `POST /deals/{dealId}/move body { stage_id: targetStageId, lost_reason: reason.trim() }` — backend нужно научить move принимать lost_reason. Иначе 2 запроса.

### Интеграция с deals/page.tsx
```tsx
const [lostTarget, setLostTarget] = useState<{ dealId: number; stageId: number } | null>(null);

async function move(dealId, stageId) {
  const targetStage = (board?.columns ?? []).find(c => c.stage.id === stageId)?.stage;
  if (targetStage?.is_lost) {
    setLostTarget({ dealId, stageId });
    return;
  }
  // ... existing API call
}

// Render:
{lostTarget && <LostReasonModal ... onConfirmed={() => { setLostTarget(null); mutate(); }} />}
```

Drag-and-drop тоже проходит через `move()` → автоматически открывает modal.

## Задача 8. DealModal — `expected_close_date` (date input)

В DealModal.tsx добавить:
```tsx
const [closeDate, setCloseDate] = useState(deal.expected_close_date?.slice(0, 10) || "");
<Field label="Планируемая дата закрытия" type="date" value={closeDate} onChange={setCloseDate} />
saveFields: { expected_close_date: closeDate || null }
```

## Задача 9. LeadFormModal — `score` slider

```tsx
<div>
  <label className="label">Оценка лида</label>
  <div className="flex items-center gap-3">
    <input type="range" min="0" max="100" step="5" value={form.score || "0"} onChange={...} className="flex-1 accent-primary" />
    <span className="w-8 text-right text-sm tabular-nums font-medium text-primary">{form.score || "0"}</span>
  </div>
  <div className="text-xs text-gray-400 mt-1">0 — холодный, 100 — горячий</div>
</div>
```

### LeadScoreIndicator (в /leads list)
```tsx
if (score == null) return null;
if (score >= 70) return <i className="bi bi-fire text-danger text-xs" title={`Оценка: ${score}`} />;
if (score >= 50) return <i className="bi bi-circle-fill text-warning text-xs" title={`Оценка: ${score}`} />;
return null;
```

Применить в LeadCard.tsx + LeadRow.tsx.

## Задача 10. Табы для /leads/[id] и /contacts/[id]

По образцу /companies/[id]:
```tsx
type LeadTab = "details" | "timeline" | "audit";
const LEAD_TABS = [
  { key: "details", label: "Обзор", icon: "bi-info-circle" },
  { key: "timeline", label: "Активности", icon: "bi-clock-history" },
  { key: "audit", label: "История изм.", icon: "bi-journal-text" },
];
```

Details: реквизиты + CustomFieldsBlock + notes.
Timeline: `<Timeline targetType="lead" targetId={id} />`
Audit: `<AuditLogTimeline entityType="lead" entityId={id} />`

Аналогично для /contacts/[id].

## Задача 11. LeadConvertModal — duplicate-suggestion + country prompt

Обработка ApiError detail:
- `409 + { code: "duplicate_found", candidate: { id, name, country_code } }` → блок «Найден похожий контрагент» с кнопками «Привязать к {name}» / «Создать нового» (последний делает retry с `confirm_create_new: true`)
- `400 + { code: "country_required" }` → блок «Укажи страну контрагента» с select KZ/UZ/KG/RU/AE/BY/AM/GE + Продолжить → retry с `country_code`

## Задача 12. Терминология «Ответственный»

Find-and-replace в JSX label/placeholder:
- `"Владелец"` → `"Ответственный"`
- `"Owner"` (если в UI) → `"Ответственный"`

Места:
- deals/page.tsx ~173
- DealModal.tsx ~79
- leads/[id]/page.tsx ~101
- LeadFormModal.tsx ~253
- contacts/[id]/page.tsx ~89

Backend поля НЕ менять: `owner_id`, `owner_user_id`, `responsible_id`.

---

## Новые типы в types.ts

```tsx
export interface MyTask {
  id: number;
  kind: ActivityKind;
  title: string;
  due_at: string | null;
  target_type: ActivityTargetType;
  target_id: number;
  target_name?: string | null;
  is_overdue: boolean;
}

export interface HotDeal {
  id: number;
  title: string;
  amount: number | null;
  currency: string | null;
  stage_name: string;
  stage_color: string | null;
  idle_days: number;
  days_to_close: number | null;
  heat_reason: "idle" | "deadline";
  counterparty_name?: string | null;
}

export const LOST_REASON_PRESETS = ["Цена","Конкурент","Не подошло","Передумали","Не наш сегмент","Другое"] as const;
export type LostReasonPreset = typeof LOST_REASON_PRESETS[number];

// Deal — добавить:
// expected_close_date?: string | null;
// lost_reason?: string | null;

// Lead — добавить:
// score?: number | null;
```

---

## Зависимости от backend Эпика 4.2

| Требование | Приоритет | Fallback |
|---|---|---|
| Deal.expected_close_date | критично | — |
| Deal.lost_reason | критично | — |
| Lead.score | важно | — |
| `/deals/{id}/move` принимает `lost_reason` | критично | 2 запроса |
| `/activities?responsible_id=me` или fallback на /auth/me + id | важно | — |
| `/deals/hot?owner=me&limit=5` | важно | clientside filter из /deals/board |
| `Counterparty.responsible_user_id` | для Right Rail | заглушка «—» |
| `LeadConvert` 409 `duplicate_found` + 400 `country_required` | для UX-сценариев | стандартная error display |

---

## Tailwind / Bootstrap Icons

- `--primary #172747`, `--primary-light #2B4987`, `--danger`, `--success`, `--warning`, `--info`
- Кнопки: `btn-primary / btn-secondary / btn-ghost`, инпуты `input` / `label`
- Иконки: `bi-fire` (hot), `bi-check2-circle` (tasks empty), `bi-three-dots-vertical` (kebab), `bi-clock-history` (timeline), `bi-journal-text` (audit), `bi-info-circle` (details), `bi-pencil` (inline edit), `bi-arrow-left` (back), `bi-plus-lg` (CTA), `bi-buildings` (companies)

## Mobile

Desktop-first. Right Rail на <1024px скрывается (TODO comment). Эпик 10 — только desktop.

## States/Interactions/Тексты

См. оригинальный отчёт designer для каждого компонента (loading skeleton, empty, error).

---

## Open questions (frontend-specialist решает)

1. `Counterparty.responsible_user_id` — спросить у backend. Если нет — placeholder «—».
2. Карточка `/deals/[id]` не существует — оставить DealModal как переход.
3. `/activities` страница нет — footer-ссылка временно без действия.
4. HotDealsWidget fallback — clientside filter если /deals/hot не готов.
5. `window.confirm` vs ConfirmModal — рекомендуется `<ConfirmModal>` (мини-компонент Эпика 10).
6. date-fns — проверить package.json, иначе нативный Intl.
7. RoleGate на destructive — вне scope Эпика 10.
8. Страны в LeadConvertModal — захардкожено (KZ/UZ/KG/RU/AE/BY/AM/GE).
