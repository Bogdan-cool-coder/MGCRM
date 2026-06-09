# ТЗ: Эпик 4.1 — UI расширений автоматизаций и страница Последовательностей

**Зачем:** добавить в форму автоматизаций триггер `on_create` и 4 новых действия (`change_owner`, `webhook`, `email`, `start_sequence`), создать CRUD-страницу `/admin/sequences` для управления шаблонами рассылок/цепочек, добавить фильтр `action_kind` в историю запусков — закрыть UI-долг Эпика 4.1 (backend уже в коде).

**Где в коде:**
- Существующие страницы: `apps/web/src/app/(app)/admin/automations/new/page.tsx`, `apps/web/src/app/(app)/admin/automations/[id]/page.tsx`, `apps/web/src/app/(app)/admin/automation-runs/page.tsx`
- Новая страница: `apps/web/src/app/(app)/admin/sequences/page.tsx`
- Новые компоненты-конфиги: `apps/web/src/components/Automations/triggers/OnCreateConfig.tsx`
- `apps/web/src/components/Automations/actions/ChangeOwnerConfig.tsx`
- `apps/web/src/components/Automations/actions/WebhookConfig.tsx`
- `apps/web/src/components/Automations/actions/EmailConfig.tsx`
- `apps/web/src/components/Automations/actions/StartSequenceConfig.tsx`
- Новый компонент страницы: `apps/web/src/components/Sequences/SequenceList.tsx`, `apps/web/src/components/Sequences/SequenceFormModal.tsx`, `apps/web/src/components/Sequences/StepsBuilder.tsx`
- Обновить: `apps/web/src/lib/automationConfig.ts`, `apps/web/src/components/Sidebar.tsx`

## Изменяемые/новые файлы

| Файл | Суть |
|---|---|
| `lib/automationConfig.ts` | + `on_create` в TRIGGER_OPTIONS/LABELS; + 4 новых action в ACTION_OPTIONS/LABELS; + ON_CREATE_TARGET_TYPE_OPTIONS; + ACTION_KIND_FILTER_OPTIONS; + CHANGE_OWNER_RULE_OPTIONS; + USER_ROLE_OPTIONS |
| `lib/types.ts` | Расширить AutomationTriggerKind/AutomationActionKind union'ы; + Sequence/SequenceStep |
| `components/Sidebar.tsx` | + ссылка «Последовательности» (`bi-collection-play-fill`) после «Автоматизации» для admin/director |
| `components/Automations/AutomationForm.tsx` | + ветки для on_create (trigger) и 4 новых action; обновить inferTargetType; defaultTriggerConfig/defaultActionConfig; validate() |
| `components/Automations/TriggerBadge.tsx` | + кейс on_create (`bi-plus-circle-fill`, success, «При создании») |
| `components/Automations/ActionBadge.tsx` | + 4 новых action_kind (change_owner: bi-person-gear info; webhook: bi-send-fill warning; email: bi-envelope-fill info; start_sequence: bi-collection-play-fill primary) |
| `app/(app)/admin/automation-runs/page.tsx` | + select-фильтр action_kind |
| **NEW** components/Automations/triggers/OnCreateConfig.tsx | radio-выбор target_type ∈ {lead, deal, inbound_message} |
| **NEW** components/Automations/actions/ChangeOwnerConfig.tsx | rule radio + user_pool_filter (roles multi, department text, is_active checkbox) |
| **NEW** components/Automations/actions/WebhookConfig.tsx | url input + secret input + headers textarea |
| **NEW** components/Automations/actions/EmailConfig.tsx | recipient radio + UserSelect + subject input + body textarea + SMTP info-блок |
| **NEW** components/Automations/actions/StartSequenceConfig.tsx | select sequence_id из активных (SWR `/sequences?is_active=true`) |
| **NEW** app/(app)/admin/sequences/page.tsx | страница CRUD |
| **NEW** components/Sequences/SequenceList.tsx | таблица + фильтры |
| **NEW** components/Sequences/SequenceFormModal.tsx | modal создания/редактирования |
| **NEW** components/Sequences/StepsBuilder.tsx | список шагов с reorder + динамические config-блоки по kind |

## Wireframes ключевых элементов

### Форма автоматизации — секция «Триггер» (новый on_create)
```
┌─────────────────────────────────────────────────────────┐
│ Когда срабатывает                                        │
│ [select] ▼ При создании сущности                         │
│ Срабатывает в момент создания. Полезно для               │
│ авто-распределения новых лидов из Inbox.                 │
│ ─────────────────────────────────────────────────────── │
│ Тип цели *                                               │
│  ○ Лид   ○ Сделка   ○ Входящее сообщение                │
└─────────────────────────────────────────────────────────┘
```

### Действие «Сменить ответственного»
```
[select] ▼ Сменить ответственного
─────────
Правило распределения *
  ○ По очереди (round-robin) — По очереди, цикл по pool'у
  ○ По продукту            — Берёт product → ищет owner
  ○ По стране              — Берёт страну → ищет owner
  ○ По отделу              — Если у лида указан department
Фильтр pool'а пользователей
  Роли (пусто = все): [checkbox: admin / director / manager / lawyer]
  Отдел: [input: "Фильтр по отделу (опц.)"]
  [x] Только активные
```

### Действие «Webhook»
```
URL *                  [input https://...]
Секрет (опц.)          [input ...]
hint: Если задан — добавит header X-Macro-Signature: sha256=...
Доп. заголовки (JSON, опц.)  [textarea {"Authorization":"Bearer ..."}]
Тело: { event, automation_id, target_type, target_id, payload }
```

### Действие «Email»
```
┌── info ──────────────────────────────────────────────┐
│ ℹ  SMTP должен быть настроен на бэкенде.             │
│    Без него действие получит статус failed.          │
│    Настроить: SMTP_HOST/USER/PASS/FROM в .env        │
└──────────────────────────────────────────────────────┘
Получатель *
  ○ Ответственный пользователь   ○ Конкретный
[UserSelect если specific]
Тема *           [input: "Новый лид — {{ entity.name }}"]
Текст письма *   [textarea Jinja]
Переменные: {target_id} {target_title} {owner_name} {target_type}
```

### Действие «Запустить последовательность»
```
Последовательность *  [select из /sequences?is_active=true]
hint: Создать на /admin/sequences
```

### `/admin/sequences` — список
```
PageHeader: Последовательности | [+ Создать]
[search "Поиск по названию"] [Состояние select: Любое/Активные/Выключенные] [Сбросить]
┌─────────────────────────────────────────────────┐
│ Название       Шагов  Статус  Действия          │
│ Онбординг лида   4   Активна  [▶][✎][🗑]        │
└─────────────────────────────────────────────────┘
Empty: bi-collection-play, «Последовательностей пока нет» + [Создать первую]
```

### Sequence Form Modal
```
Новая последовательность                            [×]
Название *  [input]
Описание    [textarea rows=2]
[x] Активна
──── Шаги ──────────────────────────────────────────
┌── Шаг 1 ──────────────────────────────────────┐
│ [↑][↓] Вид: [select] Задержка: [_] дней  [✕] │
│ [динамический config по kind]                 │
└───────────────────────────────────────────────┘
[+ Добавить шаг]
                                  [Отмена] [Сохранить]
```

### StepsBuilder — kind config'и
- `wait` — config-блок не нужен
- `tg_notify` — message (textarea) + recipient (radio owner/specific)
- `email` — subject (input) + body (textarea) — без SMTP-info (она в EmailConfig)
- `create_task` — title (input) + responsible (radio owner/specific)

### Фильтр action_kind на /admin/automation-runs
```
[Автоматизация▼] [Тип цели▼] [ID цели] [Действие▼] [Статус▼]
                              ↑ новый
```

## TypeScript типы

```ts
export type AutomationTriggerKind = "on_enter_stage" | "idle_in_stage_days" | "date_field_approaching" | "on_create";
export type AutomationActionKind = "tg_notify" | "create_task" | "set_field" | "generate_document" | "change_owner" | "webhook" | "email" | "start_sequence";

export interface SequenceStep {
  id?: number;
  order: number;
  kind: "wait" | "tg_notify" | "email" | "create_task";
  delay_days: number;
  config: Record<string, unknown>;
}

export interface Sequence {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  steps_json: SequenceStep[];
  steps_count?: number;
  created_at: string;
  updated_at: string;
}
```

## API контракт (уже готов на бекенде)

| Операция | Endpoint |
|---|---|
| List sequences (filters) | `GET /api/sequences?q=&is_active=&limit=&offset=` |
| Create | `POST /api/sequences` body `{ name, description, steps_json, is_active }` |
| Update | `PATCH /api/sequences/{id}` |
| Delete | `DELETE /api/sequences/{id}` |
| Manual start | `POST /api/sequences/{id}/start` body `{ target_type, target_id }` |
| Step kinds enum | `GET /api/sequence-runs/step-kinds` → `{value,label}[]` |
| Active sequences (select) | `GET /api/sequences?is_active=true` |
| Runs with new filter | `GET /api/automation-runs?action_kind=...` |
| Automations | `POST/PATCH /api/automations` — структура без изменений |

## Состояния / Edge cases

- SequenceList loading: 3 skeleton-строки
- SequenceList empty: `bi-collection-play` + CTA
- StartSequenceConfig: loading select disabled «Загружаем…»; empty placeholder «Нет активных последовательностей»
- step-kinds API: fallback хардкод (wait/tg_notify/email/create_task)
- AutomationForm: при смене trigger/action_kind вызвать defaultTriggerConfig/defaultActionConfig

## Тексты (RU)

Все тексты в ТЗ детализированы выше. Inline-сообщения валидации (text-danger):
- `Укажи URL для Webhook`
- `Укажи тему письма`
- `Укажи текст письма`
- `Выбери получателя письма`
- `Выбери последовательность`

## Открытые вопросы (решения для frontend-specialist)

1. **`inbound_message` в AutomationTargetType** — добавь в union (нужно для on_create).
2. **`department` multi-select в ChangeOwnerConfig** — text input для MVP (frontend-specialist; можно расширить потом).
3. **StartRunModal target_type** — `lead`/`deal`/`subscription` достаточно для MVP.
4. **История запусков sequence** — не включать в этой итерации.
5. **UserSelect** — должен существовать (`@/components/UserSelect`), используется в EmailConfig.

## Tailwind tokens
- `--primary #172747`, `--primary-light #2B4987`, `--danger`, `--success`, `--info`
- Кнопки: `btn-primary` / `btn-secondary` / `btn-ghost`
- Инпуты: `input` / `label`
- Карточки: `card`
- Баджи: `badge badge-*`

## Bootstrap Icons
- on_create: `bi-plus-circle-fill`
- change_owner: `bi-person-gear`
- webhook: `bi-send-fill`
- email: `bi-envelope-fill`
- start_sequence: `bi-collection-play-fill`
- search: `bi-search`
- play: `bi-play-fill`
- edit: `bi-pencil`
- delete: `bi-trash`
- add: `bi-plus-lg`

## Mobile
Desktop-first. Mobile — TBD (Эпик 10). StepsBuilder на узких экранах скроллится горизонтально.
