# План следующего PR по чатам

## Цель

- убрать текущие шероховатости в delete flow и sidebar
- начать использовать новый backend-контракт для более понятного AI UX
- не распыляться пока на слишком глубокую визуализацию metadata

## Общий объем

- примерно 3-5 часов аккуратной работы
- можно разбить на 2 небольших PR, если хочется меньшего риска

## План следующего PR

### 1. Исправить семантику и поведение `ChatSidebarList`

Файл:
- `front/src/components/chat/ChatSidebarList.vue`

Что сделать:
- убрать структуру, где delete action вложен внутрь кликабельной строки, если она там еще осталась
- сделать строку чата контейнером
- внутри сделать две отдельные интерактивные зоны:
  - зона выбора чата
  - зона удаления чата
- проверить keyboard navigation
- проверить hover, focus, active состояния

Почему это важно:
- улучшает accessibility
- убирает невалидную интерактивную вложенность
- делает поведение строки предсказуемее

Результат:
- sidebar станет чище технически и стабильнее по UX

### 2. Доделать delete flow

Файлы:
- `front/src/pages/AiChatPage/composables/useAiChatPage.ts`
- `front/src/pages/AiReportsPage/composables/useAiReportsPage.ts`
- `front/src/stores/chats.ts`

Что сделать:
- не очищать активный чат до успешного удаления на backend
- сначала `await chatsStore.deleteChat(id)`
- только потом:
  - если это был открытый чат, делать `resetChat(...)`
- на ошибке:
  - оставить экран в текущем состоянии
  - показать notification

Почему это важно:
- сейчас есть риск потерять открытый чат визуально, хотя удаление могло не пройти
- это уже не просто косметика, а надежность поведения

Результат:
- delete flow станет продуктово корректнее

### 3. Добавить confirm delete

Файлы:
- скорее всего:
  - `front/src/components/chat/ChatSidebarList.vue`
  - и/или page-level composables
- зависит от того, хотите confirm popup или modal

Рекомендуемый вариант:
- компактный confirm возле delete action
- если в проекте уже есть стандартный confirm/overlay подход, лучше встроиться в него

Что должно быть:
- "Удалить чат?"
- короткое пояснение, что вместе с chat может удалиться и связанный отчет, если это backend behavior
- confirm / cancel

Почему это важно:
- delete сейчас достаточно близко к misclick зоне
- для чатовой истории подтверждение уместно

Результат:
- пользовательский риск случайного удаления резко снижается

### 4. Добавить AI progress UI на основе `tool_calls`

Файлы:
- новый компонент, например:
  - `front/src/components/chat/ChatAiProgress.vue`
- возможно helper:
  - `front/src/entities/chat/helpers.ts` или `front/src/components/chat/utils.ts`
- интеграция в:
  - `front/src/pages/AiReportsPage/index.vue`
  - `front/src/pages/AiChatPage/index.vue`

Что сделать минимально:
- брать последний assistant message или `currentChat.aiContext`
- интерпретировать `toolCalls`
- показывать статус, пока `isSending === true`

Простейшая карта:
- `probe_data` -> "Проверяю данные"
- `create_report` -> "Создаю отчет"
- `update_report` -> "Обновляю отчет"

Можно сделать очень легкий UI:
- маленькая плашка над input или над typing indicator
- один текущий статус, без сложной таймлайновой визуализации

Почему это важно:
- backend уже отдает useful signals
- сейчас пользователь просто ждет
- это даст самый заметный UX-эффект из всех следующих шагов

Результат:
- AI-чат станет понятнее в момент долгого ожидания

### 5. Подготовить foundation для metadata rendering

Файлы:
- `front/src/components/chat/ChatMessageBubble.vue`
- новый компонент по желанию:
  - `ChatMessageMetadataPanel.vue`

Что сделать:
- не перегружать UI сразу
- начать с компактного вывода:
  - tool calls count
  - finish reason
- usage лучше пока не показывать обычному пользователю, максимум в dev/debug-режиме

Почему это важно:
- мы уже типизировали metadata
- стоит начать ее реально использовать
- но делать это лучше очень дозированно

Результат:
- появится понятная точка роста под richer AI responses

### 6. Сделать mobile pass для двух страниц

Файлы:
- `front/src/pages/AiChatPage/index.vue`
- `front/src/pages/AiReportsPage/index.vue`
- возможно стили chat-компонентов

Что проверить:
- sidebar на узком экране
- ширина области сообщений
- доступность delete/create actions
- поведение report banner на мобильных

Если нужно:
- свернуть sidebar в drawer
- или сделать stacked layout

Почему это важно:
- после роста функциональности layout может быть уже desktop-first
- лучше не откладывать слишком надолго

Результат:
- чатовый модуль станет ровнее по реальному UX

## Как я бы разбил это на 2 PR

### PR 1

- `ChatSidebarList` semantics
- delete flow
- confirm delete

Почему:
- это один логичный пакет про навигацию и безопасные действия
- небольшой риск
- быстрый и заметный эффект

### PR 2

- `ChatAiProgress`
- metadata foundation
- mobile pass

Почему:
- это уже UX-улучшения
- их проще делать поверх уже стабильного delete/navigation behavior

## Конкретный порядок редактирования

1. `front/src/components/chat/ChatSidebarList.vue`
2. `front/src/pages/AiChatPage/composables/useAiChatPage.ts`
3. `front/src/pages/AiReportsPage/composables/useAiReportsPage.ts`
4. confirm UX
5. новый `ChatAiProgress.vue`
6. интеграция в страницы
7. optional metadata panel
8. mobile CSS pass
9. `docker compose exec frontend npm run type-check`
10. `docker compose exec frontend npm run build`

## Что я считаю самым выгодным прямо сейчас

Если выбирать только один следующий кусок работ, я бы взял:
1. delete flow
2. confirm delete
3. sidebar semantics

Это не самый "вау"-эффект по UI, но это лучший ROI по качеству продукта.

Если выбирать второй по выгоде:
1. AI progress на `tool_calls`

Это уже даст очень заметное ощущение более умного и живого AI-интерфейса.

## Следующий практический шаг

Если идти сразу в реализацию, следующий самый логичный пакет:
- поправить sidebar
- сделать confirm delete
- выровнять delete flow end-to-end
