# AI-чаты: фронтенд

**Проект:** Vizion Frontend  
**Стек:** Vue 3, TypeScript, Pinia, PrimeVue 4, vue-router 5  
**Scope:** только фронтенд. Бэкенд API готов.

---

## Оглавление

1. [Цели и контекст](#1-цели-и-контекст)
2. [API контракт](#2-api-контракт)
3. [Архитектура](#3-архитектура)
4. [Техническое задание](#4-техническое-задание)
   - [4.1 DTO-типы](#41-dto-типы-srcapitypeschatsts)
   - [4.2 API-модуль](#42-api-модуль-srcapichatsts)
   - [4.3 Entity-слой](#43-entity-слой-srcentitiesChat)
   - [4.4 Service](#44-service-srcserviceschatservicets)
   - [4.5 Store](#45-store-srcStoreschatsts)
   - [4.6 Composable useChat](#46-composable-usechat)
   - [4.7 Компоненты чата](#47-компоненты-чата-srccomponentschat)
   - [4.8 Страница AI-конструктор](#48-страница-ai-конструктор)
   - [4.9 Страница AI-чат](#49-страница-ai-чат)
   - [4.10 Изменения в существующих файлах](#410-изменения-в-существующих-файлах)
5. [Точки расширения](#5-точки-расширения)
6. [Детальный план выполнения](#6-детальный-план-выполнения)

---

## 1. Цели и контекст

Добавить в приложение два новых раздела в сайдбаре:

| Раздел | URL | Тип чата | Поведение |
|---|---|---|---|
| AI-конструктор отчётов | `/ai-reports` | `report_generation` | Пользователь описывает отчёт → AI генерирует и сохраняет его. После создания появляется ссылка на готовый отчёт. |
| AI-чат | `/ai-chat` | `quick_qa` | Аналитический диалог. Отчёт может появиться или нет — обрабатывается одинаково. |

**Доступ:** только роли `superadmin`, `admin`, `analyst`. Роль `viewer` не видит пунктов в сайдбаре и не может перейти по URL (router guard через `meta.roles` уже реализован).

**Нет стриминга.** Бэкенд отдаёт синхронный JSON-ответ. Время ответа AI — 5–30 секунд, поэтому ключевой UX-элемент — явный typing indicator пока идёт ожидание.

---

## 2. API контракт

Все запросы через существующий `apiClient` (axios с Bearer-токеном из `useUserStore`).

### Эндпоинты

| Метод | URL | Тело запроса | Описание |
|---|---|---|---|
| `GET` | `/api/chats` | — | Список чатов текущего пользователя |
| `POST` | `/api/chats` | `{ type }` | Создать чат |
| `GET` | `/api/chats/{id}` | — | Чат с полной историей сообщений |
| `DELETE` | `/api/chats/{id}` | — | Удалить чат и связанный отчёт |
| `POST` | `/api/chats/{id}/messages` | `{ content }` | Отправить сообщение, получить ответ AI |
| `GET` | `/api/chats/{id}/messages` | — | История сообщений чата |

### Формат ответов

**`GET /api/chats`** → `ChatListItemDto[]`:
```json
[
  {
    "id": 1,
    "type": "report_generation",
    "title": "Реестр сделок по агентам",
    "report_id": 42,
    "created_at": "2026-04-07T10:00:00Z",
    "updated_at": "2026-04-07T10:05:00Z",
    "last_message": {
      "role": "assistant",
      "content": "Отчёт успешно создан.",
      "created_at": "2026-04-07T10:05:00Z"
    }
  }
]
```

**`POST /api/chats/{id}/messages`** → `SendMessageResponseDto`:
```json
{
  "message": {
    "id": 5,
    "chat_id": 1,
    "role": "assistant",
    "content": "Отчёт создан. Он содержит...",
    "metadata": null,
    "created_at": "2026-04-07T10:05:00Z"
  },
  "chat": {
    "id": 1,
    "type": "report_generation",
    "title": "Реестр сделок",
    "report_id": 42,
    "messages": [...],
    "report": { ... }
  }
}
```

> **Важно:** `metadata` на сообщениях — структура уточняется с бэкендом. Типизировать как `Record<string, unknown> | null`, не рендерить специфичный контент. Заложена точка расширения через слот.

---

## 3. Архитектура

### Принцип: гибрид Store + Composable

Разделение по зоне ответственности:

```
useChatStore (Pinia) — глобальное, живёт между страницами
├── chats[]              список для сайдбара страниц
├── activeChatId         какой чат открыт
├── fetchChats()
├── createChat()
├── deleteChat()
├── setActive()
└── syncChatFromDetail() ← вызывается из composable после ответа AI

useChat (Composable) — локальное, живёт пока открыта страница
├── currentChat          полный объект с messages[]
├── isSending            typing indicator
├── isLoadingChat        skeleton при открытии чата
├── loadChat()
├── createAndOpenChat()
├── sendMessage()        → внутри вызывает store.syncChatFromDetail()
└── resetChat()
```

Страницы используют **page-level composable** (`useAiReportsPage`, `useAiChatPage`), который оркестрирует store + `useChat` и не содержит прямых вызовов API. Соответствует паттерну `useReportPage` из существующего кода.

### Полная структура файлов

```
src/
├── api/
│   ├── chats.ts                          [NEW]
│   ├── index.ts                          [MODIFY — добавить реэкспорт]
│   └── types/
│       ├── chats.ts                      [NEW]
│       └── index.ts                      [MODIFY — добавить реэкспорт]
│
├── entities/
│   └── chat/
│       ├── index.ts                      [NEW]
│       ├── types.ts                      [NEW]
│       └── mappers.ts                    [NEW]
│
├── stores/
│   └── chats.ts                          [NEW]
│
├── services/
│   ├── ChatService.ts                    [NEW]
│   └── index.ts                          [MODIFY — зарегистрировать chatService]
│
├── composables/
│   └── useChat.ts                        [NEW]
│
├── components/
│   └── chat/
│       ├── ChatMessageBubble.vue         [NEW]
│       ├── ChatMessageList.vue           [NEW]
│       ├── ChatInput.vue                 [NEW]
│       ├── ChatTypingIndicator.vue       [NEW]
│       ├── ChatReportBanner.vue          [NEW]
│       ├── ChatSidebarList.vue           [NEW]
│       └── index.ts                      [NEW]
│
├── pages/
│   ├── AiReportsPage/
│   │   ├── index.ts                      [NEW]
│   │   ├── index.vue                     [NEW]
│   │   ├── locale/
│   │   │   ├── ru.json                   [NEW]
│   │   │   └── en.json                   [NEW]
│   │   └── composables/
│   │       └── useAiReportsPage.ts       [NEW]
│   └── AiChatPage/
│       ├── index.ts                      [NEW]
│       ├── index.vue                     [NEW]
│       ├── locale/
│       │   ├── ru.json                   [NEW]
│       │   └── en.json                   [NEW]
│       └── composables/
│           └── useAiChatPage.ts          [NEW]
│
├── components/Sidebar/
│   ├── Sidebar.vue                       [MODIFY]
│   └── locale/
│       ├── ru.json                       [MODIFY]
│       └── en.json                       [MODIFY]
│
└── router/routes/base.ts                 [MODIFY]
```

---

## 4. Техническое задание

### 4.1 DTO-типы (`src/api/types/chats.ts`)

Создать файл. Типы описывают контракт с бэкендом — не изменять без синхронизации с API.

```ts
export type ChatMessageRole = 'user' | 'assistant' | 'system'
export type ChatType = 'report_generation' | 'quick_qa'

// metadata намеренно слабо типизирован — структура уточняется.
// При уточнении: заменить на ChatMessageMetadata с discriminated union.
export interface ChatMessageDto {
  id: number
  chat_id: number
  user_id: number
  company_id: number
  role: ChatMessageRole
  content: string
  metadata: Record<string, unknown> | null
  created_at: string
  updated_at: string
}

export interface ChatLastMessageDto {
  role: ChatMessageRole
  content: string
  created_at: string
}

export interface ChatListItemDto {
  id: number
  type: ChatType
  title: string | null
  report_id: number | null
  created_at: string
  updated_at: string
  last_message: ChatLastMessageDto | null
}

export interface ChatDetailDto {
  id: number
  type: ChatType
  title: string | null
  report_id: number | null
  messages: ChatMessageDto[]
  report: import('./reports').ReportDto | null
  created_at: string
  updated_at: string
}

export interface SendMessageResponseDto {
  message: ChatMessageDto
  chat: ChatDetailDto
}

export interface CreateChatRequest {
  type: ChatType
}

export interface SendMessageRequest {
  content: string
}
```

Добавить реэкспорты в `src/api/types/index.ts`.

---

### 4.2 API-модуль (`src/api/chats.ts`)

По образцу `src/api/reports.ts`. Только HTTP, никакой бизнес-логики.

```ts
import { apiClient } from '@/api/client'
import type {
  ChatDetailDto, ChatListItemDto, ChatMessageDto,
  CreateChatRequest, SendMessageRequest, SendMessageResponseDto,
} from '@/api/types/chats'

export const chatsApi = {
  async fetchChats(): Promise<ChatListItemDto[]> {
    const response = await apiClient.get<ChatListItemDto[]>('/api/chats')
    return response.data
  },

  async createChat(data: CreateChatRequest): Promise<ChatDetailDto> {
    const response = await apiClient.post<ChatDetailDto>('/api/chats', data)
    return response.data
  },

  async fetchChat(id: number): Promise<ChatDetailDto> {
    const response = await apiClient.get<ChatDetailDto>(`/api/chats/${id}`)
    return response.data
  },

  async deleteChat(id: number): Promise<void> {
    await apiClient.delete(`/api/chats/${id}`)
  },

  async sendMessage(chatId: number, data: SendMessageRequest): Promise<SendMessageResponseDto> {
    const response = await apiClient.post<SendMessageResponseDto>(
      `/api/chats/${chatId}/messages`,
      data,
    )
    return response.data
  },

  async fetchMessages(chatId: number): Promise<ChatMessageDto[]> {
    const response = await apiClient.get<ChatMessageDto[]>(`/api/chats/${chatId}/messages`)
    return response.data
  },
}
```

Добавить реэкспорт в `src/api/index.ts`.

---

### 4.3 Entity-слой (`src/entities/chat/`)

По образцу `src/entities/report/`. Доменные типы — независимы от DTO.

#### `types.ts`

```ts
import type { ChatMessageRole, ChatType } from '@/api/types/chats'
import type { Report } from '@/entities/report'

export type { ChatMessageRole, ChatType }

export interface ChatMessage {
  id: number
  chatId: number
  role: ChatMessageRole
  content: string
  metadata: Record<string, unknown> | null
  createdAt: string
}

export interface ChatListItem {
  id: number
  type: ChatType
  title: string | null
  reportId: number | null
  updatedAt: string
  lastMessage: { role: ChatMessageRole; content: string } | null
}

export interface ChatDetail {
  id: number
  type: ChatType
  title: string | null
  reportId: number | null
  messages: ChatMessage[]
  // Не ReportItem из @/features/reports — тот требует type: 'dashboard'|'custom',
  // которого нет в ReportDto. Используем Report entity с локализованными полями.
  report: (Report & { title: string; description?: string }) | null
}
```

#### `mappers.ts`

```ts
import type { ChatDetailDto, ChatListItemDto, ChatMessageDto } from '@/api/types/chats'
import type { ChatDetail, ChatListItem, ChatMessage } from './types'
import { mapReportDtoToReport } from '@/entities/report'
import { getLocalizedText } from '@/utils/localization'
// Импорт ReportItem из @/features/reports не нужен — см. комментарий к ChatDetail.report

export const mapChatMessageDtoToMessage = (dto: ChatMessageDto): ChatMessage => ({
  id: dto.id,
  chatId: dto.chat_id,
  role: dto.role,
  content: dto.content,
  metadata: dto.metadata,
  createdAt: dto.created_at,
})

export const mapChatListItemDtoToItem = (dto: ChatListItemDto): ChatListItem => ({
  id: dto.id,
  type: dto.type,
  title: dto.title,
  reportId: dto.report_id,
  updatedAt: dto.updated_at,
  lastMessage: dto.last_message
    ? { role: dto.last_message.role, content: dto.last_message.content }
    : null,
})

export const mapChatDetailDtoToDetail = (dto: ChatDetailDto): ChatDetail => ({
  id: dto.id,
  type: dto.type,
  title: dto.title,
  reportId: dto.report_id,
  messages: dto.messages.map(mapChatMessageDtoToMessage),
  report: dto.report
    ? {
        ...mapReportDtoToReport(dto.report),
        title: getLocalizedText(dto.report.title),
        description: dto.report.description
          ? getLocalizedText(dto.report.description)
          : undefined,
      }
    : null,
})
```

#### `index.ts`

Реэкспортировать все типы и маперы.

---

### 4.4 Service (`src/services/ChatService.ts`)

По образцу `src/services/ReportService.ts`. Использует `chatsApi` + маперы.

```ts
import { chatsApi } from '@/api/chats'
import {
  mapChatDetailDtoToDetail,
  mapChatListItemDtoToItem,
  mapChatMessageDtoToMessage,
} from '@/entities/chat/mappers'
import type { ChatDetail, ChatListItem, ChatMessage, ChatType } from '@/entities/chat'

export class ChatService {
  async fetchChats(): Promise<ChatListItem[]> {
    return (await chatsApi.fetchChats()).map(mapChatListItemDtoToItem)
  }

  async createChat(type: ChatType): Promise<ChatDetail> {
    return mapChatDetailDtoToDetail(await chatsApi.createChat({ type }))
  }

  async fetchChat(id: number): Promise<ChatDetail> {
    return mapChatDetailDtoToDetail(await chatsApi.fetchChat(id))
  }

  async deleteChat(id: number): Promise<void> {
    await chatsApi.deleteChat(id)
  }

  async sendMessage(
    chatId: number,
    content: string,
  ): Promise<{ message: ChatMessage; chat: ChatDetail }> {
    const response = await chatsApi.sendMessage(chatId, { content })
    return {
      message: mapChatMessageDtoToMessage(response.message),
      chat: mapChatDetailDtoToDetail(response.chat),
    }
  }
}
```

#### Правки `src/services/index.ts`

```ts
// Добавить в тип Services:
chatService: ChatService

// Добавить в createServices():
chatService: new ChatService()

// Добавить реэкспорт:
export { ChatService }
```

---

### 4.5 Store (`src/stores/chats.ts`)

По образцу `src/stores/companies.ts`. Хранит список чатов для сайдбара страниц и `activeChatId`. Детальное состояние конкретного чата — только в composable.

```ts
import { defineStore } from 'pinia'
import { ChatService } from '@/services/ChatService'
import type { ChatDetail, ChatListItem, ChatType } from '@/entities/chat'

const chatService = new ChatService()

export const useChatsStore = defineStore('chats', {
  state: () => ({
    chats: [] as ChatListItem[],
    activeChatId: null as number | null,
  }),

  getters: {
    getChats(): ChatListItem[] {
      return this.chats
    },
    getActiveChatId(): number | null {
      return this.activeChatId
    },
    // Страницы используют нужный геттер по типу
    getReportGenerationChats(): ChatListItem[] {
      return this.chats.filter((c) => c.type === 'report_generation')
    },
    getQuickQaChats(): ChatListItem[] {
      return this.chats.filter((c) => c.type === 'quick_qa')
    },
  },

  actions: {
    async fetchChats(): Promise<void> {
      this.chats = await chatService.fetchChats()
    },

    // Создать, добавить в начало списка, установить activeChatId
    async createChat(type: ChatType): Promise<number> {
      const detail = await chatService.createChat(type)
      const listItem: ChatListItem = {
        id: detail.id,
        type: detail.type,
        title: detail.title,
        reportId: detail.reportId,
        updatedAt: new Date().toISOString(),
        lastMessage: null,
      }
      this.chats.unshift(listItem)
      this.activeChatId = detail.id
      return detail.id
    },

    async deleteChat(id: number): Promise<void> {
      await chatService.deleteChat(id)
      this.chats = this.chats.filter((c) => c.id !== id)
      if (this.activeChatId === id) {
        this.activeChatId = null
      }
    },

    setActive(id: number | null): void {
      this.activeChatId = id
    },

    // Вызывается из useChat после ответа AI.
    // Обновляет запись в списке без перезапроса всего списка.
    syncChatFromDetail(chat: ChatDetail): void {
      const idx = this.chats.findIndex((c) => c.id === chat.id)
      if (idx === -1) return
      const existing = this.chats[idx]
      const msgs = chat.messages
      // Явная конструкция вместо spread — иначе TS выводит id?: number | undefined.
      // Array.prototype.at() не поддерживается в target проекта — используем [length-1].
      this.chats[idx] = {
        id: existing.id,
        type: existing.type,
        title: chat.title,
        reportId: chat.reportId,
        updatedAt: new Date().toISOString(),
        lastMessage: msgs.length > 0
          ? { role: msgs[msgs.length - 1].role, content: msgs[msgs.length - 1].content }
          : existing.lastMessage,
      }
    },
  },

  // persist не используем — сессионное состояние
})
```

---

### 4.6 Composable `useChat`

**Файл:** `src/composables/useChat.ts`

Локальное состояние конкретного открытого чата. Шарится между `AiReportsPage` и `AiChatPage`. Единственная точка связи со store — вызов `syncChatFromDetail`.

```ts
import { ref } from 'vue'
import { useChatsStore } from '@/stores/chats'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import type { ChatDetail, ChatMessage, ChatType } from '@/entities/chat'

export const useChat = () => {
  const chatsStore = useChatsStore()
  const { chatService } = useServices()
  const { notifyApiError } = useNotifications()

  const currentChat = ref<ChatDetail | null>(null)
  const isSending = ref(false)
  const isLoadingChat = ref(false)

  const loadChat = async (id: number): Promise<void> => {
    isLoadingChat.value = true
    try {
      currentChat.value = await chatService.fetchChat(id)
      chatsStore.setActive(id)
    } catch (error) {
      notifyApiError(error, 'Не удалось загрузить чат')
    } finally {
      isLoadingChat.value = false
    }
  }

  // Возвращает true если чат успешно создан и загружен.
  // Вызывающий код должен проверить возвращаемое значение перед отправкой.
  const createAndOpenChat = async (type: ChatType): Promise<boolean> => {
    try {
      const id = await chatsStore.createChat(type)
      await loadChat(id)
      return currentChat.value !== null
    } catch (error) {
      notifyApiError(error, 'Не удалось создать чат')
      return false
    }
  }

  const sendMessage = async (content: string): Promise<void> => {
    if (!currentChat.value || isSending.value) return

    const chatId = currentChat.value.id

    // Оптимистичный push — пользователь видит своё сообщение сразу
    const optimisticMessage: ChatMessage = {
      id: Date.now(), // временный id
      chatId,
      role: 'user',
      content,
      metadata: null,
      createdAt: new Date().toISOString(),
    }
    currentChat.value.messages.push(optimisticMessage)

    isSending.value = true
    try {
      const { message, chat } = await chatService.sendMessage(chatId, content)

      // Добавляем ответ ассистента
      currentChat.value.messages.push(message)

      // Синхронизируем мета в currentChat
      currentChat.value.title = chat.title
      currentChat.value.reportId = chat.reportId
      currentChat.value.report = chat.report

      // Синхронизируем запись в списке (сайдбар перерисуется реактивно)
      chatsStore.syncChatFromDetail(chat)
    } catch (error) {
      // Сообщение пользователя остаётся видимым — не откатываем.
      // Добавляем системное сообщение прямо в чат, чтобы пользователь
      // видел причину и мог повторить попытку.
      currentChat.value.messages.push({
        id: Date.now() + 1,
        chatId,
        role: 'system',
        content: '⚠ Нет связи с сервером. Сообщение не доставлено — попробуйте ещё раз.',
        metadata: null,
        createdAt: new Date().toISOString(),
      })
      notifyApiError(error, 'Нет связи с сервером')
    } finally {
      isSending.value = false
    }
  }

  const resetChat = (): void => {
    currentChat.value = null
    chatsStore.setActive(null)
  }

  return {
    currentChat,
    isSending,
    isLoadingChat,
    loadChat,
    createAndOpenChat,
    sendMessage,
    resetChat,
  }
}
```

---

### 4.7 Компоненты чата (`src/components/chat/`)

#### `ChatTypingIndicator.vue`

Анимированные три точки. Нет пропсов. CSS-анимация `@keyframes bounce` с задержками `0ms`, `150ms`, `300ms` для каждой точки. Показывается в конце списка пока `isSending === true`.

---

#### `ChatMessageBubble.vue`

**Props:**
```ts
interface Props {
  message: ChatMessage
}
```

**Стили по роли:**

| `role` | Выравнивание | Фон | Цвет текста | Прочее |
|---|---|---|---|---|
| `user` | `flex-end` | `$primary` | white | — |
| `assistant` | `flex-start` | `$surface-100` | `$surface-900` | — |
| `system` | `flex-start` | `$surface-200` | `$surface-700` | `border-left: 3px solid $orange-500` |

> **Внимание:** переменных `$yellow-100` / `$yellow-900` в проекте нет. Для `system` использовать `$surface-200` / `$surface-700` + акцентная левая граница `$orange-500`.

Время — `createdAt` форматировать как `HH:mm` через `Intl.DateTimeFormat`.

`metadata` — **не рендерить**. Заложить именованный слот для будущего расширения:
```html
<slot name="metadata" :metadata="message.metadata" />
```

---

#### `ChatMessageList.vue`

**Props:**
```ts
interface Props {
  messages: ChatMessage[]
  isSending: boolean
}
```

- `v-for` по `messages` → `ChatMessageBubble`
- `ChatTypingIndicator` `v-if="isSending"` в конце
- `watch([() => messages.length, isSending], () => nextTick(scrollToBottom))`
- `scrollToBottom` → `container.scrollTop = container.scrollHeight`
- Контейнер: `overflow-y: auto`, `flex: 1`, `min-height: 0`

---

#### `ChatInput.vue`

**Props:**
```ts
interface Props {
  disabled?: boolean
  placeholder?: string
}
```

**Emits:** `submit(content: string)`

- PrimeVue `Textarea` с `autoResize`
- Кнопка "Отправить": `disabled` если `props.disabled || !content.trim()`
- `Ctrl+Enter` (Win/Linux) и `Cmd+Enter` (Mac) → `handleSubmit`
- `handleSubmit`: emit `submit(content.trim())`, очистить поле

---

#### `ChatReportBanner.vue`

**Props:**
```ts
interface Props {
  reportId: number
  reportTitle?: string | null
}
```

PrimeVue `Message`, `severity="success"`. Текст: "Отчёт создан". Кнопка `router-link` → `/reports/{reportId}`. Родитель управляет видимостью через `v-if="currentChat?.reportId"`.

---

#### `ChatSidebarList.vue`

**Props:**
```ts
interface Props {
  chats: ChatListItem[]
  activeChatId: number | null
}
```

**Emits:** `select(id: number)`, `create()`

- Шапка: заголовок + кнопка "+ Новый"
- `v-for` по `chats` → кликабельная строка
- Текст строки: `chat.title || chat.lastMessage?.content || 'Новый чат'` — обрезать до 50 символов
- Активный элемент — выделить как `router-link-active` в основном сайдбаре
- `EmptyState` если `chats.length === 0`

---

#### `index.ts`

Реэкспортировать все шесть компонентов.

---

### 4.8 Страница AI-конструктор

**Путь:** `src/pages/AiReportsPage/`

#### `composables/useAiReportsPage.ts`

Оркестрирует store + `useChat`. Прямых вызовов API нет.

```ts
import { computed } from 'vue'
import { useChatsStore } from '@/stores/chats'
import { useChat } from '@/composables/useChat'
import { useNotifications } from '@/composables/useNotifications'

export const useAiReportsPage = () => {
  const chatsStore = useChatsStore()
  const chat = useChat()
  const { notifyApiError } = useNotifications()

  // try/catch обязателен: при падении fetchChats страница должна оставаться
  // рабочей (пустой сайдбар + активный ввод), а не висеть молча.
  const init = async () => {
    try {
      await chatsStore.fetchChats()
      const id = chatsStore.getActiveChatId
      if (id) await chat.loadChat(id)
    } catch (error) {
      notifyApiError(error, 'Не удалось загрузить список чатов')
    }
  }

  // По аналогии с useAiChatPage: если чата нет — создаём перед отправкой.
  // Прямой вызов chat.sendMessage без создания → тихий early return без фидбека.
  const handleSend = async (content: string) => {
    if (!chat.currentChat.value) {
      const created = await chat.createAndOpenChat('report_generation')
      if (!created) return // создание упало, ошибка уже показана
    }
    await chat.sendMessage(content)
  }

  return {
    // Для левой панели (из store)
    reportChats: computed(() => chatsStore.getReportGenerationChats),
    activeChatId: computed(() => chatsStore.getActiveChatId),

    // Для правой панели (из useChat)
    currentChat: chat.currentChat,
    isSending: chat.isSending,
    isLoadingChat: chat.isLoadingChat,

    // Handlers
    init,
    handleSelectChat: (id: number) => chat.loadChat(id),
    handleCreateNew: () => chat.createAndOpenChat('report_generation'),
    handleSend,
    handleDeleteChat: (id: number) => chatsStore.deleteChat(id),
  }
}
```

#### `index.vue` — Layout

Двухколоночный, высота 100% (как `ReportPage`):

```
┌──────────────────────┬───────────────────────────────────────────────┐
│  ChatSidebarList     │  ChatReportBanner  (v-if currentChat.reportId)│
│  width: 220px        ├───────────────────────────────────────────────┤
│  flex-shrink: 0      │  LoadingState      (v-if isLoadingChat)       │
│  border-right:       │  EmptyState        (v-else-if !currentChat)   │
│  1px solid $surface  │  ChatMessageList   (v-else, flex: 1)          │
│  overflow-y: auto    ├───────────────────────────────────────────────┤
│                      │  ChatInput  (disabled=isSending)              │
└──────────────────────┴───────────────────────────────────────────────┘
```

`onMounted` → `init()`.

#### Локали

```json
// ru.json
{
  "title": "AI-конструктор",
  "newReport": "Новый отчёт",
  "placeholder": "Опишите отчёт, который хотите получить...",
  "emptyChat": "Выберите отчёт или создайте новый",
  "errors": {
    "loadFailed": "Не удалось загрузить чат",
    "sendFailed": "Не удалось отправить сообщение"
  }
}

// en.json
{
  "title": "AI Constructor",
  "newReport": "New Report",
  "placeholder": "Describe the report you want to create...",
  "emptyChat": "Select a report or create a new one",
  "errors": {
    "loadFailed": "Failed to load chat",
    "sendFailed": "Failed to send message"
  }
}
```

---

### 4.9 Страница AI-чат

**Путь:** `src/pages/AiChatPage/`

#### `composables/useAiChatPage.ts`

```ts
import { useChat } from '@/composables/useChat'

export const useAiChatPage = () => {
  const chat = useChat()

  // При первом сообщении — создать чат автоматически.
  // createAndOpenChat возвращает boolean — проверяем перед sendMessage,
  // иначе при падении создания sendMessage уйдёт в тихий early return.
  const handleSend = async (content: string) => {
    if (!chat.currentChat.value) {
      const created = await chat.createAndOpenChat('quick_qa')
      if (!created) return
    }
    await chat.sendMessage(content)
  }

  return {
    currentChat: chat.currentChat,
    isSending: chat.isSending,
    handleSend,
    handleNewChat: () => chat.resetChat(),
  }
}
```

#### `index.vue` — Layout

Полноширинный:

```
┌─────────────────────────────────────────────────────────────────────┐
│  "AI-чат"                                      [Новый чат] Button   │
├─────────────────────────────────────────────────────────────────────┤
│  ChatReportBanner  (v-if currentChat?.reportId)                     │
├─────────────────────────────────────────────────────────────────────┤
│  EmptyState        (v-if="!currentChat")                            │
│  ChatMessageList   (v-else, flex: 1, overflow-y: auto)             │
├─────────────────────────────────────────────────────────────────────┤
│  ChatInput  (disabled=isSending)                                    │
└─────────────────────────────────────────────────────────────────────┘
```

#### Локали

```json
// ru.json
{
  "title": "AI-чат",
  "newChat": "Новый чат",
  "placeholder": "Задайте вопрос по вашим данным...",
  "emptyChat": "Задайте вопрос, чтобы начать",
  "errors": {
    "sendFailed": "Не удалось отправить сообщение"
  }
}

// en.json
{
  "title": "AI Chat",
  "newChat": "New Chat",
  "placeholder": "Ask a question about your data...",
  "emptyChat": "Ask a question to start",
  "errors": {
    "sendFailed": "Failed to send message"
  }
}
```

---

### 4.10 Изменения в существующих файлах

#### `src/router/routes/base.ts`

Добавить перед catch-all маршрутом:

```ts
{
  path: '/ai-reports',
  name: 'AiReports',
  component: () => import('@/pages/AiReportsPage'),
  meta: {
    requiresAuth: true,
    roles: ['superadmin', 'admin', 'analyst'] as UserRole[],
  },
},
{
  path: '/ai-chat',
  name: 'AiChat',
  component: () => import('@/pages/AiChatPage'),
  meta: {
    requiresAuth: true,
    roles: ['superadmin', 'admin', 'analyst'] as UserRole[],
  },
},
```

#### `src/components/Sidebar/Sidebar.vue`

Добавить computed:

```ts
const canUseAi = computed(() =>
  ['superadmin', 'admin', 'analyst'].includes(userStore.getUserRole)
)
```

Добавить в `<nav>` после `/reports`:

```html
<router-link v-if="canUseAi" to="/ai-reports" class="nav-item">
  <i class="pi pi-sparkles"></i>
  <span>{{ t('aiReports') }}</span>
</router-link>
<router-link v-if="canUseAi" to="/ai-chat" class="nav-item">
  <i class="pi pi-comments"></i>
  <span>{{ t('aiChat') }}</span>
</router-link>
```

#### `src/components/Sidebar/locale/ru.json`

```json
{
  "reports": "Отчёты",
  "company": "Компания",
  "aiReports": "AI-конструктор",
  "aiChat": "AI-чат"
}
```

#### `src/components/Sidebar/locale/en.json`

```json
{
  "reports": "Reports",
  "company": "Company",
  "aiReports": "AI Constructor",
  "aiChat": "AI Chat"
}
```

---

## 5. Точки расширения

Каждая заложена архитектурно — не требует рефакторинга при реализации.

| Что неясно | Где расширять | Механизм |
|---|---|---|
| Структура `ChatMessage.metadata` | `entities/chat/types.ts` | Заменить `Record<string, unknown>` на `ChatMessageMetadata` с discriminated union |
| Специфичный рендер `metadata` | `ChatMessageBubble.vue` | Именованный слот `#metadata` — заполнить контентом |
| `quick_qa` создаёт отчёт | `useChat.ts → sendMessage` | Уже обрабатывается: `reportId` обновляется из `response.chat` |
| Стриминг (SSE) в будущем | `ChatService.sendMessage` + `useChat.sendMessage` | Поменять реализацию методов — интерфейс composable остаётся прежним, компоненты не трогаем |
| История сессий в AI-чате | `useAiChatPage.ts` | Добавить `chatsStore.getQuickQaChats` + `ChatSidebarList` — геттер уже есть |
| Удаление чата из списка | `ChatSidebarList.vue` | Добавить emit `delete(id)` → `handleDeleteChat` уже есть в `useAiReportsPage` |
| Новый тип чата | `api/types/chats.ts` | Добавить в union `ChatType`, добавить геттер в store |

---

## 6. Детальный план выполнения

### Зависимости между шагами

```
Шаг 1  api/types/chats.ts + api/chats.ts
  └─► Шаг 2  entities/chat/
        └─► Шаг 3  services/ChatService.ts + services/index.ts
              └─► Шаг 4  stores/chats.ts
                    └─► Шаг 5  composables/useChat.ts
                          ├─► Шаг 6a  components/chat/*
                          ├─► Шаг 6b  pages/AiReportsPage/*
                          └─► Шаг 6c  pages/AiChatPage/*
                                          └─► Шаг 7  Sidebar + router
```

Шаги 6a, 6b, 6c не зависят друг от друга — можно выполнять параллельно.

---

### Шаг 1 — API-слой

**Файлы:** `src/api/types/chats.ts`, `src/api/chats.ts`, правки `src/api/types/index.ts`, `src/api/index.ts`

**Задачи:**
- [ ] Создать `src/api/types/chats.ts` — все DTO-типы и request-интерфейсы из раздела 4.1
- [ ] Создать `src/api/chats.ts` — 6 методов из раздела 4.2
- [ ] Добавить реэкспорты в `src/api/types/index.ts`
- [ ] Добавить реэкспорт `chatsApi` в `src/api/index.ts`

**Критерий готовности:** TypeScript компилируется без ошибок, методы видны через автодополнение.

---

### Шаг 2 — Entity-слой

**Файлы:** `src/entities/chat/types.ts`, `src/entities/chat/mappers.ts`, `src/entities/chat/index.ts`

**Задачи:**
- [ ] Создать `types.ts` — `ChatMessage`, `ChatListItem`, `ChatDetail` из раздела 4.3
- [ ] Создать `mappers.ts` — три функции маппинга
- [ ] Создать `index.ts` — реэкспорт всего

**Критерий готовности:** маперы корректно трансформируют mock-DTO в доменные типы.

---

### Шаг 3 — Service

**Файлы:** `src/services/ChatService.ts`, `src/services/index.ts`

**Задачи:**
- [ ] Создать `ChatService.ts` — 5 методов из раздела 4.4
- [ ] Добавить `chatService: new ChatService()` в `createServices()`
- [ ] Добавить `chatService: ChatService` в тип `Services`
- [ ] Реэкспортировать `ChatService` из `index.ts`

**Критерий готовности:** `useServices()` возвращает `chatService` с правильным типом.

---

### Шаг 4 — Store

**Файл:** `src/stores/chats.ts`

**Задачи:**
- [ ] Создать store со state, getters, actions из раздела 4.5
- [ ] Убедиться что `syncChatFromDetail` корректно обновляет поля без мутации всего массива
- [ ] `persist` не добавлять

**Критерий готовности:** `useChatsStore()` работает в DevTools, геттеры фильтруют по типу.

---

### Шаг 5 — Composable `useChat`

**Файл:** `src/composables/useChat.ts`

**Задачи:**
- [ ] Реализовать `loadChat`, `createAndOpenChat`, `sendMessage`, `resetChat` из раздела 4.6
- [ ] Оптимистичный push + rollback при ошибке в `sendMessage`
- [ ] Вызов `chatsStore.syncChatFromDetail` после успешного ответа
- [ ] Обновление `currentChat.title`, `reportId`, `report` после ответа AI

**Критерий готовности:** `sendMessage` добавляет сообщения в правильном порядке, откатывает при 5xx.

---

### Шаг 6a — Компоненты чата

**Файлы:** `src/components/chat/*`

**Задачи:**
- [ ] `ChatTypingIndicator.vue` — CSS bounce-анимация, нет пропсов
- [ ] `ChatMessageBubble.vue` — стили по роли, слот `#metadata`, форматирование времени
- [ ] `ChatMessageList.vue` — auto-scroll через `watch` + `nextTick`
- [ ] `ChatInput.vue` — `autoResize`, `Ctrl/Cmd+Enter`, блокировка при `disabled`
- [ ] `ChatReportBanner.vue` — PrimeVue Message + router-link
- [ ] `ChatSidebarList.vue` — список с активным состоянием, EmptyState
- [ ] `index.ts` — реэкспорт всех компонентов

**Критерий готовности:** компоненты рендерятся изолированно с mock-данными.

---

### Шаг 6b — Страница AiReportsPage

**Файлы:** `src/pages/AiReportsPage/*`

**Задачи:**
- [ ] `composables/useAiReportsPage.ts` — оркестрация из раздела 4.8
- [ ] `index.vue` — двухколоночный layout, `onMounted(init)`
- [ ] `locale/ru.json` и `locale/en.json`
- [ ] `index.ts` — реэкспорт страницы

**Критерий готовности:** страница отображает список чатов слева, открывает историю справа, отправляет сообщения.

---

### Шаг 6c — Страница AiChatPage

**Файлы:** `src/pages/AiChatPage/*`

**Задачи:**
- [ ] `composables/useAiChatPage.ts` — создание чата при первом сообщении
- [ ] `index.vue` — полноширинный layout с EmptyState
- [ ] `locale/ru.json` и `locale/en.json`
- [ ] `index.ts` — реэкспорт страницы

**Критерий готовности:** первое сообщение создаёт чат и отправляет, "Новый чат" сбрасывает состояние.

---

### Шаг 7 — Интеграция

**Файлы:** `src/router/routes/base.ts`, `src/components/Sidebar/Sidebar.vue`, оба locale файла сайдбара

**Задачи:**
- [ ] Добавить маршруты `/ai-reports` и `/ai-chat` в `base.ts` с `meta.roles`
- [ ] Добавить `canUseAi` computed в `Sidebar.vue`
- [ ] Добавить два `router-link` в `<nav>`
- [ ] Обновить `ru.json` и `en.json` сайдбара — добавить ключи `aiReports`, `aiChat`

**Критерий готовности:** пункты сайдбара видны для `analyst`, скрыты для `viewer`, маршруты работают, guard редиректит при нехватке роли.

---

### Итоговый чеклист

- [ ] TypeScript: `vue-tsc --build` проходит без ошибок
- [ ] Lint: `eslint . --ext .ts,.vue` без ошибок
- [ ] Роли: `viewer` не видит AI-разделы в сайдбаре и не может перейти по URL
- [ ] UX: typing indicator показывается пока идёт запрос, input заблокирован
- [ ] Оптимистичный push: сообщение пользователя появляется мгновенно
- [ ] Rollback: при ошибке сервера оптимистичное сообщение исчезает, показывается уведомление
- [ ] Отчёт: `ChatReportBanner` появляется после ответа если `report_id !== null`
- [ ] Auto-scroll: список прокручивается вниз при новых сообщениях
- [ ] i18n: все строки вынесены в locale-файлы, нет хардкода
