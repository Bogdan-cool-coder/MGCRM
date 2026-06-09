# ТЗ: Эпик 19 — Automation 2 (SLA Wizard + Dry-run 2.0 + Branch в Sequence)

**Версия:** 1.0
**Дата:** 2026-06-02
**Автор:** designer
**Исполнитель:** frontend-specialist
**Зависит от:** Эпик 4 (AutomationForm, `/admin/automations`), Эпик 11 (Sequences, StepsBuilder), Эпик 21 (Notification Center — SLA breach sink)

---

## Cover

### Цель

Три независимых UI-блока, которые делают автоматизации в MACRO CRM промышленными:

1. **SLA Wizard** — специализированный мастер создания SLA-правил (тип автоматизации с триггером `idle_in_stage_days` + эскалационной цепочкой). Живёт в `/admin/sla` как самостоятельный раздел.
2. **Dry-run 2.0** — переработка существующего `TestRunModal` в полноценный dry-run с превью затронутых записей и кнопкой «Выполнить сейчас». Добавляется на страницу `/admin/automations` к каждому правилу.
3. **Branch-условия в Sequence Builder** — новый тип шага `if_else` в компоненте `StepsBuilder`: condition + true/false ветки с drag-drop внутри.

### Что уже есть (НЕ переписывать)

- `TestRunModal.tsx` — существующий dry-run preview без «Выполнить». Расширяем, не заменяем.
- `AutomationForm.tsx`, `AutomationStatusToggle.tsx`, `TriggerBadge.tsx`, `ActionBadge.tsx` — остаются без изменений.
- `StepsBuilder.tsx` — добавляем новый `kind='if_else'` поверх существующих `wait/tg_notify/email/create_task`.
- `/admin/automations/page.tsx` — добавляем кнопку «Dry-run» в actions каждой строки.

---

## Раздел 1: SLA Wizard — `/admin/sla`

### Зачем

Команда CS и продаж создаёт SLA-правила через обычный `AutomationForm` — это неудобно: нет понятия «эскалационная цепочка», нет подсказок по entity_type, нет превью времени реакции. SLA Wizard решает это через специализированный 3-шаговый мастер.

### Где в коде

- Страница-реестр: `apps/web/src/app/(app)/admin/sla/page.tsx`
- Мастер создания: `apps/web/src/app/(app)/admin/sla/new/page.tsx`
- Редактирование: `apps/web/src/app/(app)/admin/sla/[id]/page.tsx`
- Компоненты:
  - `apps/web/src/components/Sla/SlaCard.tsx`
  - `apps/web/src/components/Sla/SlaWizard.tsx`
  - `apps/web/src/components/Sla/SlaEscalationChain.tsx`

### Wireframe — реестр `/admin/sla`

```
┌────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: SLA-правила]     [+ Создать правило] │
│            ├────────────────────────────────────────────────────┤
│            │  Активных: 4   Сработало сегодня: 2               │
│            │                                                    │
│            │ ┌──────────────────────────────────────────────┐  │
│            │ │ SlaCard: «Сделка > 7д без активности»         │  │
│            │ │  entity: Сделка · порог: 7 дней               │  │
│            │ │  Активна | Сработала 12 раз | Эскалаций 3     │  │
│            │ │  [Toggle] [Dry-run] [Редактировать] [Удалить] │  │
│            │ ├──────────────────────────────────────────────┤  │
│            │ │ SlaCard: «Approval > 3д без решения»          │  │
│            │ │  ...                                           │  │
│            │ └──────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────┘
```

### Wireframe — мастер `/admin/sla/new` (3 шага)

```
┌────────────────────────────────────────────────────────────────┐
│ [Sidebar]  │ [PageHeader: Новое SLA-правило]  [← К списку]    │
│            ├────────────────────────────────────────────────────┤
│            │  ① Что отслеживаем  ② Порог реакции  ③ Действия  │
│            │  [●]────────────────[○]──────────────[○]          │
│            │                                                    │
│            │ ┌──────────────────────────────────────────────┐  │
│            │ │ Шаг 1: Что отслеживаем                        │  │
│            │ │                                                │  │
│            │ │  Название правила *                            │  │
│            │ │  [input: Сделка без активности 7 дней]        │  │
│            │ │                                                │  │
│            │ │  Тип записи *                                  │  │
│            │ │  [select: Сделка / Лид / Согласование / Задача]│  │
│            │ │                                                │  │
│            │ │  Этап (если применимо)                         │  │
│            │ │  [select: Все этапы / выбрать из воронки]     │  │
│            │ │                                                │  │
│            │ │  Воронка (если этап выбран)                    │  │
│            │ │  [select: загружается по SWR]                  │  │
│            │ │                                                │  │
│            │ │           [btn-ghost: Отмена] [btn-primary: Далее →] │
│            │ └──────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────┘
```

```
┌────────────────────────────────────────────────────────────────┐
│            │  ① Что отслеживаем  ② Порог реакции  ③ Действия  │
│            │  [✓]────────────────[●]──────────────[○]          │
│            │                                                    │
│            │ ┌──────────────────────────────────────────────┐  │
│            │ │ Шаг 2: Порог реакции                          │  │
│            │ │                                                │  │
│            │ │  Записи без изменений дольше *                 │  │
│            │ │  [input: 7] [select: дней / часов]            │  │
│            │ │                                                │  │
│            │ │  ─── Эскалационная цепочка ───                 │  │
│            │ │                                                │  │
│            │ │  Уровень 1: Если через [3] дн → уведомить     │  │
│            │ │  [select: Ответственный / Менеджер / Конкр. пользователь]│
│            │ │  [select: Ответственный]                       │  │
│            │ │                                                │  │
│            │ │  [+ Добавить уровень эскалации]               │  │
│            │ │                                                │  │
│            │ │  Уровень 2: Если через [7] дн → уведомить     │  │
│            │ │  [select: Менеджер]                            │  │
│            │ │  [× удалить уровень]                           │  │
│            │ │                                                │  │
│            │ │  [btn-ghost: Назад] [btn-primary: Далее →]    │  │
│            │ └──────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────┘
```

```
┌────────────────────────────────────────────────────────────────┐
│            │  ① Что отслеживаем  ② Порог реакции  ③ Действия  │
│            │  [✓]────────────────[✓]──────────────[●]          │
│            │                                                    │
│            │ ┌──────────────────────────────────────────────┐  │
│            │ │ Шаг 3: Действия при нарушении SLA             │  │
│            │ │                                                │  │
│            │ │  Telegram-уведомление                          │  │
│            │ │  [checkbox ✓] Отправить сообщение             │  │
│            │ │  [textarea: текст, поддерживает {deal_name}]  │  │
│            │ │                                                │  │
│            │ │  Дополнительно                                 │  │
│            │ │  [checkbox] Пометить запись как просроченную  │  │
│            │ │  [checkbox] Создать уведомление в системе     │  │
│            │ │            (kind='sla_breach' → Эпик 21)      │  │
│            │ │                                                │  │
│            │ │  Описание (опционально)                        │  │
│            │ │  [textarea]                                    │  │
│            │ │                                                │  │
│            │ │  [btn-ghost: Назад] [btn-primary: Создать правило] │
│            │ └──────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────┘
```

### Композиция SLA

- **Layout**: общий `(app)/layout.tsx` с Sidebar
- **Реестр**: `apps/web/src/app/(app)/admin/sla/page.tsx`
  - SWR: `GET /api/automations?trigger_kind=idle_in_stage_days` (фильтр по тегу или отдельный endpoint — см. Открытые вопросы)
  - Renders: `SlaCard` для каждого правила
- **Мастер**: `apps/web/src/app/(app)/admin/sla/new/page.tsx`
  - Local state: `step: 1 | 2 | 3`, данные каждого шага накапливаются в `wizardState`
  - На последнем шаге: `POST /api/automations` с собранным payload
- **Редактирование**: `apps/web/src/app/(app)/admin/sla/[id]/page.tsx`
  - SWR: `GET /api/automations/{id}`, передаёт в `SlaWizard mode="edit"`

### Компонент SlaCard

```
SlaCard props:
  automation: Automation (trigger_kind === 'idle_in_stage_days')
  onMutate: () => void  // revalidate SWR
```

Структура карточки:

```
┌─────────────────────────────────────────────────────────────────┐
│ [•] Сделка > 7 дней без активности                    [toggle] │
│  Сделка · Все этапы · Воронка «Продажи»                        │
│                                                                 │
│  Порог: 7 дн  ·  Эскалация 1: +3 дн → Ответственный           │
│                  Эскалация 2: +7 дн → Менеджер                 │
│                                                                 │
│  [badge: Активна]  Сработала 12 раз · Эскалаций 3             │
│                                                                 │
│  [btn-ghost: Dry-run]  [btn-ghost: Редактировать]  [bi-trash text-danger] │
└─────────────────────────────────────────────────────────────────┘
```

Tailwind-классы карточки:
- Корень: `card p-5 space-y-3`
- Заголовок: `flex items-start justify-between gap-4`
- Название: `text-base font-semibold text-primary`
- Мета (entity · этапы · воронка): `text-sm text-gray-500 mt-0.5`
- Эскалационная цепочка: `text-sm text-gray-700 space-y-0.5 border-l-2 border-warning/40 pl-3`
- Статистика: `flex items-center gap-3 text-sm text-gray-600`
- Статус-badge активна: `badge bg-success/10 text-success`
- Статус-badge неактивна: `badge bg-gray-100 text-gray-500`
- Actions: `flex items-center gap-2 pt-1`

### Компонент SlaWizard

```
SlaWizard props:
  mode: 'create' | 'edit'
  initialData?: Automation  // только для mode='edit'
  onSuccess: () => void
```

Степпер (верх формы):
- Три нумерованных шага: `① Что отслеживаем` `② Порог реакции` `③ Действия`
- Активный шаг: круг `bg-primary text-white` + label жирный
- Пройденный: `bg-success/20 text-success` + галочка `bi-check-lg`
- Будущий: `bg-gray-100 text-gray-400`
- Разделитель между шагами: горизонтальная линия `flex-1 h-px bg-gray-200`

Шаг 1 — поля:
- `Название правила` — `input`, required
- `Тип записи` — `select` с опциями: `deal` / `lead` / `approval` / `task`
- `Воронка` — `select`, SWR `GET /api/pipelines`, показывается если `deal` или `lead`
- `Этап` — `select`, загружается по `pipeline_id` через SWR `GET /api/pipelines/{id}/stages`, опция «Все этапы» (null)

Шаг 2 — поля:
- `Записей без изменений дольше` — `input[type=number]` + `select['дней'|'часов']`, required
- `SlaEscalationChain` — динамический список уровней эскалации (см. ниже)

Шаг 3 — поля:
- `checkbox` «Отправить Telegram-уведомление»
- `textarea` сообщение — появляется если checkbox отмечен, placeholder «Сделка {deal_name} просрочена...»
- `checkbox` «Пометить запись как просроченную» (set_field action)
- `checkbox` «Создать системное уведомление» (create_notification, Эпик 21)
- `textarea` Описание правила — необязательно

### Компонент SlaEscalationChain

```
SlaEscalationChain props:
  levels: EscalationLevel[]
  onChange: (levels: EscalationLevel[]) => void

EscalationLevel = {
  after_days: number
  notify: 'owner' | 'manager' | string  // string = user_id конкретного
}
```

Каждый уровень рендерится как строка:
```
  Уровень N:  Через [input: 3] дн  уведомить  [select: Ответственный / Менеджер / ...]  [bi-x-lg btn-ghost]
```

Кнопка `+ Добавить уровень эскалации`: `btn-secondary` под списком. Максимум 5 уровней (выше кнопка прячется).

Валидация: каждый следующий уровень должен иметь `after_days` строго больше предыдущего — иначе inline ошибка `text-danger` под строкой: «Срок уровня N должен быть больше уровня N−1».

### Payload, который Wizard собирает в `POST /api/automations`

```json
{
  "name": "Сделка > 7 дней без активности",
  "description": "...",
  "pipeline_id": 1,
  "stage_id": null,
  "trigger_kind": "idle_in_stage_days",
  "trigger_config": {
    "days": 7,
    "target_type": "deal",
    "escalation_chain": [
      { "after_days": 3, "notify": "owner" },
      { "after_days": 7, "notify": "manager" }
    ]
  },
  "action_kind": "tg_notify",
  "action_config": {
    "recipient": "owner",
    "message": "Сделка {deal_name} без активности {days_idle} дней"
  },
  "is_active": true
}
```

Примечание: поле `trigger_config.escalation_chain` — новое. Требует поддержки от backend-specialist (см. Открытые вопросы).

### States — реестр SLA

- **Loading**: `card p-5` с 3 skeleton-строками: `div.animate-pulse h-4 bg-gray-200 rounded`
- **Empty**: `card p-10` центрированный, иконка `bi-shield-check text-5xl text-gray-300`, заголовок «SLA-правил пока нет», описание «Создай первое правило — система сама отследит просрочки и уведомит команду», кнопка `btn-primary` «Создать правило»
- **Error**: `div text-danger p-4` над списком: «Не удалось загрузить правила. Попробуй обновить страницу.»

### States — Wizard

- Кнопка «Далее» при неполных данных: disabled (серая)
- Submit на шаге 3: кнопка меняется на «Сохраняем…» + disabled
- Ошибка API: inline `div bg-danger/10 border border-danger/30 text-danger text-sm rounded-md p-3` под кнопками
- Успех: `router.push('/admin/sla')` + SWR revalidate

### Interactions — SLA

| Элемент | Действие | Результат |
|---|---|---|
| Кнопка «+ Создать правило» | click | `router.push('/admin/sla/new')` |
| Toggle на SlaCard | toggle | `PATCH /api/automations/{id}` `{is_active: bool}` + `mutate()` |
| Кнопка «Dry-run» на SlaCard | click | Открывает `DryRunModal` (Раздел 2) |
| Кнопка «Редактировать» | click | `router.push('/admin/sla/{id}')` |
| Кнопка `bi-trash` | click | Modal confirm → `DELETE /api/automations/{id}` + `mutate()` |
| Степпер — кнопка «Назад» | click | Декремент `step`, данные сохранены |
| Степпер — кнопка «Далее» | click | Валидация текущего шага → инкремент `step` |
| Select «Воронка» на шаге 1 | change | Сбросить выбранный этап, подгрузить этапы SWR |
| Кнопка «+ Добавить уровень» | click | Append новый `EscalationLevel` с `after_days = prev + 1` |

### Sidebar — добавить пункт

В `Sidebar.tsx` добавить пункт «SLA-правила» с иконкой `bi-shield-check` в секцию «Администратор» → после пункта «Автоматизации».

---

## Раздел 2: Dry-run 2.0

### Зачем

Существующий `TestRunModal` — preview «что бы произошло» без возможности выполнить. Dry-run 2.0 добавляет:
- Кнопку «Выполнить сейчас» с confirm-шагом (опасное действие)
- Понятный список записей, которые зацепит правило
- Счётчик «Подходит N записей»

### Где в коде

- Существующий: `apps/web/src/components/Automations/TestRunModal.tsx` — **расширяем**
- Добавляем кнопку «Dry-run» на `/admin/automations/page.tsx` в строку таблицы
- Добавляем кнопку «Dry-run» на `SlaCard.tsx` (Раздел 1)

### Wireframe — Dry-run модал

```
┌──────────────────────────────────────────────────────────────┐
│  Тестовый прогон: «Сделка > 7 дней без активности»      [×] │
├──────────────────────────────────────────────────────────────┤
│  ┌─ info banner ──────────────────────────────────────────┐  │
│  │ ℹ  Dry-run: записи проверяются, действия НЕ выполняются│  │
│  └────────────────────────────────────────────────────────┘  │
│                                                              │
│  [loading state: «Анализируем подходящие записи…»]          │
│                                                              │
│  — или после загрузки —                                      │
│                                                              │
│  Подходит записей: 4                                         │
│                                                              │
│  ┌── PreviewCard: Сделка #142 «ООО Альфа»         ✓ ────┐   │
│  │  Бездействие: 9 дней · Этап: HOT deals                │   │
│  │  Действие: TG → owner · «Сделка {deal_name} просрочена»│  │
│  └────────────────────────────────────────────────────────┘  │
│  ┌── PreviewCard: Сделка #99 «ИП Белов»           ✓ ────┐   │
│  │  ...                                                   │   │
│  └────────────────────────────────────────────────────────┘  │
│                                                              │
│  ┌─ warning banner (если нажата «Выполнить») ─────────────┐  │
│  │ ⚠ Это РЕАЛЬНОЕ выполнение. Уведомления уйдут.          │  │
│  │ Затронет 4 записи. Продолжить?                         │  │
│  │ [btn-ghost: Отмена]              [btn-danger: Выполнить] │  │
│  └────────────────────────────────────────────────────────┘  │
├──────────────────────────────────────────────────────────────┤
│  [btn-ghost: Закрыть]  [btn-secondary: Повторить анализ]    │
│                        [btn-danger: Выполнить сейчас]        │
└──────────────────────────────────────────────────────────────┘
```

### Расширение DryRunModal (на базе TestRunModal)

Новые состояния модала (state machine):

```
idle
  → [нажать «Запустить анализ»]
analyzing
  → [POST /api/automations/{id}/test]
previewing  (результат показан)
  → [нажать «Выполнить сейчас»]
confirm_execute  (warning banner внутри modal)
  → [нажать «Выполнить» в баннере]
executing
  → [POST /api/automations/{id}/execute]
done (success)
  → [нажать «Закрыть»]
error
```

Тип `DryRunState = 'idle' | 'analyzing' | 'previewing' | 'confirm_execute' | 'executing' | 'done' | 'error'`

Изменения относительно `TestRunModal`:

1. `width="xl"` вместо `"lg"` — нужно больше места под список записей
2. Убрать поля «Тип цели» / «ID цели» из шапки при вызове с SLA-страницы (для `idle_in_stage_days` backend сам пробегает все подходящие)
3. Добавить счётчик «Подходит записей: N» жирным `text-lg font-semibold text-primary`
4. PreviewCard получает новое поле `idle_days?: number` — «Бездействие: N дней»
5. Footer — три кнопки:
   - `btn-ghost` «Закрыть»
   - `btn-secondary` «Повторить анализ» (сбрасывает state → `idle`)
   - `btn-danger` «Выполнить сейчас» — появляется только в state `previewing`, disabled в остальных

### Confirm-баннер перед выполнением

Внутри `<Modal>` над footer появляется:
```
bg-warning/10 border border-warning/40 rounded-md p-3 text-sm
```
Текст: «Это реальное выполнение. Уведомления уйдут получателям. Будет затронуто N записей. Продолжить?»

Кнопки в баннере: `[btn-ghost: Отмена выполнения]` + `[btn-danger: Да, выполнить]`

После нажатия «Выполнить»: state → `executing`, кнопки disabled, текст «Выполняем…»

### API для выполнения

```
POST /api/automations/{id}/execute
Response: { executed: number, errors: string[] }
```

Это новый endpoint — требуется backend (см. Открытые вопросы).

### States — DryRunModal

- `idle`: кнопка «Запустить анализ» в footer
- `analyzing`: spinner «Анализируем подходящие записи…», кнопки disabled
- `previewing`: список PreviewCard + счётчик + кнопка «Выполнить сейчас»
- `confirm_execute`: warning-баннер поверх списка
- `executing`: «Выполняем…», все кнопки disabled
- `done`: success banner «Выполнено. Затронуто N записей.» в `bg-success/10 border-success/40`, кнопка «Закрыть»
- `error`: `text-danger bg-danger/10` banner с текстом ошибки

### Где добавить кнопку «Dry-run» в существующий код

`/admin/automations/page.tsx` — строка таблицы: добавить `<button className="btn-ghost text-sm">` с иконкой `bi-play-circle` и текстом «Dry-run» рядом с «Редактировать»/«Удалить». При клике: `setDryRunTarget(automation)` → открыть `DryRunModal`.

---

## Раздел 3: Branch-условия в Sequence Builder

### Зачем

Сейчас Sequence Builder позволяет только линейную цепочку шагов. Branch добавляет ветвление: «если поле X равно Y — делай одно, иначе — другое». Это нужно для персонализированных цепочек (например: если статус сделки = Hot → ускорить, иначе → подождать).

### Где в коде

- Расширяем: `apps/web/src/components/Sequences/StepsBuilder.tsx`
- Новый субкомпонент: `apps/web/src/components/Sequences/BranchStepBlock.tsx`
- Расширяем: `@/lib/types.ts` — `SequenceStepKind` добавить `'if_else'`

### Wireframe — шаг if_else в StepsBuilder

```
┌── Step 3: If/Else ────────────────────────────────────────────┐
│  Тип: [select: If/Else (ветвление)]    [↑] [↓] [× удалить]   │
│                                                                │
│  Условие:                                                      │
│  [select: поле] [select: оператор] [input: значение]          │
│  Поле: deal_amount / lead_score / stage_name / custom_field   │
│  Оператор: равно / не равно / больше / меньше / содержит       │
│                                                                │
│  ┌── Если истина (True branch) ──────────────────────────┐    │
│  │  [Под-список шагов: drag-drop]                        │    │
│  │  ┌─ substep: tg_notify ──────────────────────────┐   │    │
│  │  │ Кому: owner · Сообщение: «Горячая сделка!»   │   │    │
│  │  └───────────────────────────────────────────────┘   │    │
│  │  [+ Добавить шаг в ветку]                             │    │
│  └───────────────────────────────────────────────────────┘    │
│                                                                │
│  ┌── Иначе (False branch) ───────────────────────────────┐    │
│  │  [пусто]                                              │    │
│  │  [+ Добавить шаг в ветку]                             │    │
│  └───────────────────────────────────────────────────────┘    │
└────────────────────────────────────────────────────────────────┘
```

### Компонент BranchStepBlock

```
BranchStepBlock props:
  config: BranchConfig
  onChange: (config: BranchConfig) => void

BranchConfig = {
  condition: {
    field: string       // 'deal_amount' | 'lead_score' | 'stage_name' | 'custom:FIELDNAME'
    operator: 'eq' | 'neq' | 'gt' | 'lt' | 'contains'
    value: string
  }
  true_steps: SequenceStep[]
  false_steps: SequenceStep[]
}
```

Поле `Поле` — `select` с опциями:
- `deal_amount` — Сумма сделки
- `lead_score` — Скор лида
- `stage_name` — Название этапа
- `assigned_user_id` — Ответственный (ID)

Поле `Оператор` — зависит от типа поля:
- Числовые (`deal_amount`, `lead_score`): `eq / neq / gt / lt`
- Строковые (`stage_name`): `eq / neq / contains`
- ID (`assigned_user_id`): `eq / neq`

Поле `Значение` — простой `input` (строка, конвертируем при POST)

**True/False ветки** — каждая рендерит вложенный мини-StepsBuilder:
- Те же step kinds: `wait / tg_notify / email / create_task` (НЕ вложенный `if_else` — рекурсия в MVP не поддерживается)
- Визуально: `border-l-4 border-success/40 pl-3 rounded-l` (true) и `border-l-4 border-gray-200 pl-3 rounded-l` (false)
- Кнопка «+ Добавить шаг в ветку»: `btn-ghost text-sm` под списком

### Ограничения MVP

- Максимальная вложенность: 1 уровень (BranchStep не может содержать другой BranchStep)
- Если пользователь выбирает `if_else` в под-ветке — показать предупреждение: `text-warning text-sm` «Вложенные ветки пока не поддерживаются»
- `false_steps` может быть пустым массивом (ветка «Иначе» необязательна)

### Расширение SequenceStepKind в types.ts

```typescript
// Было:
type SequenceStepKind = 'wait' | 'tg_notify' | 'email' | 'create_task'

// Стало:
type SequenceStepKind = 'wait' | 'tg_notify' | 'email' | 'create_task' | 'if_else'
```

Расширение `SequenceStep`:
```typescript
interface SequenceStep {
  order: number
  kind: SequenceStepKind
  delay_days: number
  config: Record<string, unknown>
  // Для kind='if_else' config содержит BranchConfig
}
```

### Расширение STEP_KIND_FALLBACK в StepsBuilder

Добавить в массив:
```typescript
{ value: 'if_else', label: 'If/Else (ветвление)' }
```

И добавить в `defaultConfig`:
```typescript
if (kind === 'if_else') return {
  condition: { field: 'deal_amount', operator: 'gt', value: '' },
  true_steps: [],
  false_steps: []
}
```

### States — BranchStepBlock

- Пустое условие (value не заполнено): inline `text-danger text-xs` «Укажи значение для сравнения»
- Обе ветки пусты: inline `text-warning text-xs` «Хотя бы одна ветка должна содержать действие»
- True-ветка с шагами, false — пустая: допустимо, без предупреждений

### Interactions — Branch

| Элемент | Действие | Результат |
|---|---|---|
| Select «Тип шага» → `if_else` | change | Рендер `BranchStepBlock` вместо `StepConfigBlock` |
| Select «Поле» | change | Обновить доступные операторы (числовые / строковые) |
| Кнопка «+ Добавить шаг в ветку» (true) | click | `true_steps.push(newStep)`, re-render |
| Кнопка «+ Добавить шаг в ветку» (false) | click | `false_steps.push(newStep)`, re-render |
| Drag-drop шага в ветке | drag | `arrayMove` внутри `true_steps` или `false_steps` |
| Кнопка `× удалить` у под-шага | click | Splice из `true_steps` / `false_steps` |

---

## Раздел 4: Координация с Эпиком 21 (Notification Center)

### SLA breach → create_notification

Когда на шаге 3 Wizard пользователь отмечает «Создать системное уведомление»:
- В `action_config` добавляется флаг `create_notification: true` и `notification_kind: "sla_breach"`
- Backend executor при срабатывании SLA создаёт `Notification(kind='sla_breach', user_id=owner_id, payload={...})`
- NotificationBell (Эпик 21) отображает непрочитанные, включая `sla_breach`

В UI на шаге 3 Wizard:
- Если Эпик 21 ещё не задеплоен → checkbox «Создать системное уведомление» показывает badge `badge bg-warning/10 text-warning` «Скоро» рядом с лейблом
- Если задеплоен → обычный checkbox без badge

### Типы нотификаций SLA (для backend-specialist)

Payload нотификации при `kind='sla_breach'`:
```json
{
  "kind": "sla_breach",
  "rule_name": "Сделка > 7 дней без активности",
  "target_type": "deal",
  "target_id": 142,
  "idle_days": 9,
  "escalation_level": 1
}
```

Это внешний контракт, который backend должен поддержать при реализации executor'а эскалации.

---

## Список компонентов (итого)

### Новые файлы

| Файл | Назначение |
|---|---|
| `apps/web/src/app/(app)/admin/sla/page.tsx` | Реестр SLA-правил |
| `apps/web/src/app/(app)/admin/sla/new/page.tsx` | Мастер создания SLA |
| `apps/web/src/app/(app)/admin/sla/[id]/page.tsx` | Редактирование SLA |
| `apps/web/src/components/Sla/SlaCard.tsx` | Карточка SLA-правила |
| `apps/web/src/components/Sla/SlaWizard.tsx` | Степпер-мастер (3 шага) |
| `apps/web/src/components/Sla/SlaEscalationChain.tsx` | Редактор эскалационной цепочки |
| `apps/web/src/components/Sequences/BranchStepBlock.tsx` | If/Else шаг в StepsBuilder |

### Расширяемые файлы

| Файл | Что меняется |
|---|---|
| `apps/web/src/components/Automations/TestRunModal.tsx` | State machine + кнопка «Выполнить сейчас» + confirm-баннер |
| `apps/web/src/app/(app)/admin/automations/page.tsx` | Кнопка «Dry-run» в строке таблицы |
| `apps/web/src/components/Sequences/StepsBuilder.tsx` | Поддержка `kind='if_else'`, `BranchStepBlock`, расширение fallback |
| `apps/web/src/lib/types.ts` | `SequenceStepKind` + `BranchConfig` interface |
| `apps/web/src/components/Sidebar.tsx` | Пункт «SLA-правила» в секции Администратор |

### Реюзуемые компоненты (без изменений)

- `PageHeader` — все страницы SLA
- `Modal` — DryRunModal, confirm удаления
- `AutomationStatusToggle` — переиспользуется в SlaCard
- `TriggerBadge`, `ActionBadge` — можно добавить в SlaCard как вспомогательные

---

## API-контракты

### Раздел 1 — SLA Wizard

| Метод | URL | Назначение |
|---|---|---|
| `GET` | `/api/automations?trigger_kind=idle_in_stage_days` | Список SLA-правил |
| `POST` | `/api/automations` | Создание SLA-правила |
| `GET` | `/api/automations/{id}` | Загрузка для редактирования |
| `PUT` | `/api/automations/{id}` | Обновление SLA-правила |
| `PATCH` | `/api/automations/{id}` | Toggle is_active |
| `DELETE` | `/api/automations/{id}` | Удаление |
| `GET` | `/api/pipelines` | Список воронок для select |
| `GET` | `/api/pipelines/{id}/stages` | Этапы выбранной воронки |

Все через `api / fetcher` из `@/lib/api` с `credentials: "same-origin"`.

### Раздел 2 — Dry-run 2.0

| Метод | URL | Назначение |
|---|---|---|
| `POST` | `/api/automations/{id}/test` | Dry-run preview (уже существует) |
| `POST` | `/api/automations/{id}/execute` | Реальное выполнение (новый endpoint) |

Response `POST /execute`:
```typescript
interface ExecuteResponse {
  executed: number
  skipped: number
  errors: string[]
}
```

### Раздел 3 — Branch

| Метод | URL | Назначение |
|---|---|---|
| `GET` | `/api/sequences/{id}` | Загрузка Sequence с steps_json |
| `PUT` | `/api/sequences/{id}` | Сохранение с if_else шагами |

`steps_json` расширяется: шаг с `kind='if_else'` содержит вложенные `true_steps[]` и `false_steps[]`.

---

## Тексты (RU, без i18n)

### Раздел 1 — SLA

- Заголовок страницы: `SLA-правила`
- Description в PageHeader: `Отслеживай просрочки и автоматически уведомляй команду`
- Кнопка создания: `Создать правило`
- Stat label активных: `Активных:`
- Stat label срабатываний: `Сработало сегодня:`
- Empty-state заголовок: `SLA-правил пока нет`
- Empty-state описание: `Создай первое правило — система сама отследит просрочки и уведомит команду`
- Empty-state CTA: `Создать правило`
- Error: `Не удалось загрузить правила. Попробуй обновить страницу.`
- Wizard заголовок (create): `Новое SLA-правило`
- Wizard заголовок (edit): `Редактировать SLA-правило`
- Шаг 1 заголовок: `Что отслеживаем`
- Шаг 2 заголовок: `Порог реакции`
- Шаг 3 заголовок: `Действия при нарушении`
- Label «Название правила»: `Название правила *`
- Label «Тип записи»: `Тип записи *`
- Options тип: `Сделка / Лид / Согласование / Задача`
- Label «Воронка»: `Воронка`
- Label «Этап»: `Этап`
- Option всех этапов: `Все этапы`
- Label порог: `Без изменений дольше *`
- Options единица: `дней / часов`
- Label эскалация-заголовок: `Эскалационная цепочка`
- Label уровня: `Уровень N:`
- Prefix уровня: `Через`
- Suffix уровня: `дн. уведомить`
- Options получатель: `Ответственный / Менеджер`
- Кнопка добавить уровень: `+ Добавить уровень эскалации`
- Error эскалации: `Срок уровня N должен быть больше уровня N−1`
- Label TG checkbox: `Отправить Telegram-уведомление`
- Label TG message: `Текст уведомления`
- Placeholder TG message: `Сделка {deal_name} без активности {days_idle} дней...`
- Label overdue checkbox: `Пометить запись как просроченную`
- Label notification checkbox: `Создать системное уведомление`
- Badge «Скоро»: `Скоро`
- Label description: `Описание (необязательно)`
- Кнопки wizard: `Отмена / Назад / Далее / Создать правило / Сохранить`
- Error API: `Не удалось сохранить правило. Попробуй ещё раз.`
- Confirm удаления: `Удалить правило? Оно перестанет срабатывать. История запусков сохранится.`
- Кнопка удалить confirm: `Удалить`
- Card badge активна: `Активна`
- Card badge неактивна: `Выключена`
- Card stats: `Сработала N раз · Эскалаций M`
- Card кнопки: `Dry-run / Редактировать`

### Раздел 2 — Dry-run

- Modal title: `Тестовый прогон: {название}`
- Info banner: `Dry-run: записи проверяются, действия НЕ выполняются`
- Loading: `Анализируем подходящие записи…`
- Счётчик: `Подходит записей: N`
- Empty result: `Нет записей, которые попадают под это правило`
- Confirm banner: `Это реальное выполнение. Уведомления уйдут получателям. Будет затронуто N записей. Продолжить?`
- Кнопка «Выполнить сейчас»: `Выполнить сейчас`
- Кнопка в confirm: `Да, выполнить`
- Кнопка отмены confirm: `Отмена выполнения`
- Executing: `Выполняем…`
- Done banner: `Выполнено. Затронуто N записей.`
- Error: `Не удалось выполнить. {detail}`
- Кнопка закрыть: `Закрыть`
- Кнопка повторить: `Повторить анализ`
- PreviewCard поле idle: `Бездействие: N дней`

### Раздел 3 — Branch

- Label step kind if_else: `If/Else (ветвление)`
- Section condition: `Условие`
- Label поле: `Поле`
- Label оператор: `Оператор`
- Label значение: `Значение`
- Options поля: `Сумма сделки / Скор лида / Название этапа / Ответственный (ID)`
- Options оператор числовой: `равно / не равно / больше / меньше`
- Options оператор строковый: `равно / не равно / содержит`
- Section true: `Если истина`
- Section false: `Иначе`
- Кнопка добавить в ветку: `+ Добавить шаг`
- Warning вложенность: `Вложенные ветки пока не поддерживаются`
- Validation пустое значение: `Укажи значение для сравнения`
- Validation пустые ветки: `Хотя бы одна ветка должна содержать действие`

---

## Адаптивность

- Desktop-first. Mobile — TBD (эпик 10).
- Степпер SLA Wizard на маленьких экранах: лейблы шагов скрываются, остаются только номера (`hidden sm:inline`).
- SlaCard на мобильном: actions переносятся на новую строку (`flex-wrap`).

---

## Открытые вопросы

### Backend (требуется правка backend-specialist)

1. **`POST /api/automations/{id}/execute`** — новый endpoint реального выполнения. Принимает `{}`, возвращает `{executed, skipped, errors[]}`. Логика: взять те же записи что и `/test`, но реально выполнить actions + записать AutomationRun.

2. **`trigger_config.escalation_chain`** — поле нового вида в `trigger_config`. Backend executor при `idle_in_stage_days` должен учитывать массив `escalation_chain: [{after_days, notify}]` и выполнять эскалацию послойно. Требуется: (а) обновление executor'а `automation_cron.py`, (б) seed 4 дефолтных SLA-правил с `escalation_chain`.

3. **Фильтрация SLA vs обычных автоматизаций** — нужно решить: отдельный endpoint `/api/sla-rules` или фильтр `?trigger_kind=idle_in_stage_days&is_sla=true`. Предлагаю фильтр (меньше изменений), но если SLA будет иметь особые поля — отдельная модель. Решение за backend-specialist + Богданом.

4. **`GET /api/pipelines/{id}/stages`** — проверить что endpoint существует и возвращает `[{id, name, order}]`. Если нет — добавить.

5. **`SequenceStep kind='if_else'` в executor** — sequence_executor.py должен обрабатывать `kind='if_else'`: вычислять condition на target-объекте, выбирать `true_steps` или `false_steps`, выполнять их как подпоследовательность. Это нетривиальная логика — согласовать контракт `BranchConfig` с backend до начала реализации frontend.

6. **Seed SLA-правил** — 4 правила из задания (сделка 7д, approval 3д, лид 1д, задача 24ч) должны быть в `automation_seed.py` с advisory-lock. Уточнить: в какие `pipeline_id` они привязываются (sales/lifecycle/без воронки)?

### UX-вопросы (нужна позиция Богдана)

7. **Раздел SLA — отдельный или вкладка?** В ТЗ описан как отдельный раздел `/admin/sla`. Альтернатива: вкладка «SLA» на странице `/admin/automations`. Отдельный раздел — проще навигация, но больше роутов. Вкладка — меньше кода, но перегруженная страница. Рекомендую отдельный раздел.

8. **Dry-run кнопка в таблице automations** — сейчас строка кликабельна целиком (переход на `/{id}`). Кнопка «Dry-run» — отдельный элемент. Предлагаю: `e.stopPropagation()` на кнопке + добавить её в последнюю колонку «Actions» рядом с иконкой редактирования. Согласовать стиль actions-колонки.

9. **Branch: field options — статические или динамические?** Список полей для условия (`deal_amount`, `lead_score` и т.д.) сейчас статический в коде. Если нужны custom fields — потребуется `GET /api/custom-fields?entity=deal`. Для MVP предлагаю статический список + пометка «Кастомные поля — скоро».
