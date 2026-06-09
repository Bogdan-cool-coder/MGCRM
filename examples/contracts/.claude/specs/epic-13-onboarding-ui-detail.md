# Эпик 13 — UI детали: формы, ContentBlocksBuilder, рендер, matrix, wizard

**Контекст**: дополнение к `epic-13-onboarding-spec.md` (сводный план) и `epic-13-onboarding-workflow-output.json` (synthesis).

**Источник**: designer subagent, 2026-05-31. Покрывает то чего не было в общем плане — конкретные wireframes форм + ContentBlocksBuilder + детали student lesson view + progress matrix + wizard + empty states + soft-gate modal + RU тексты.

---

## 1. Admin Builder

### 1a. CourseForm (`components/Onboarding/Admin/CourseForm.tsx`)

Используется на `/admin/onboarding/courses/new` и `/admin/onboarding/courses/[id]/edit`.

Поля формы:
- **Название** (input required, placeholder «Онбординг: MacroSales для менеджера»)
- **Описание** (textarea 3 rows, опц.)
- **Для кого назначается** (chips multi-select: `Менеджер`/`Юрист`/`Директор`/`Администратор`; chip: `border rounded-full px-3 py-1 text-sm cursor-pointer`, active `bg-primary text-white border-primary`)
- **Обязательность** (radio):
  - `Информационный — можно пропустить, нет ограничений`
  - `Обязательный — bulk-операции заблокированы при просрочке`
- **Дедлайн прохождения** (number input, default 5, `рабочих дней` справа)
- **Минимальный балл для сдачи квиза** (slider 50-100 step 5, default 80)
- **Cover image URL** (input, опц.)

Кнопки footer: `Сохранить черновик` (btn-secondary) / `Сохранить` (btn-primary) / в edit-mode `Опубликовать` (btn-primary) / `Снять с публикации` (btn-secondary text-danger).

**В edit-режиме — right rail (w-72 sticky)**:
- Status badge: Черновик `bg-warning/10 text-warning` / Опубликован `bg-success/10 text-success`
- Список назначенных (user × status)
- Кнопка «Назначить всем подходящим сотрудникам» с confirm

### 1b. CourseStructureBuilder (`components/Onboarding/Admin/CourseStructureBuilder.tsx`)

Иерархия Курс → Модули → Уроки. На странице `/admin/onboarding/courses/[id]/edit` ниже CourseForm.

Layout: каждый модуль — `card` collapsible (раскрыт по умолчанию) с заголовком + кнопками `[↑][↓][✏][🗑]`. Внутри список уроков — `flex items-center gap-3 px-3 py-2 border-b last:border-0 hover:bg-gray-50`. Каждый урок: номер `text-gray-400 text-sm w-5` + название `text-sm flex-1` + badge kind + кнопки `[↑][↓][✏][🗑]`.

Badges kind:
- theory → `bg-info/10 text-info`
- video → `bg-primary/10 text-primary-light`
- quiz → `bg-warning/10 text-warning`

**Кнопки `[↑][↓]`** первого/последнего — disabled (opacity-30 cursor-not-allowed).

**Клик `[✏]`** на модуле → inline edit названия (input на месте, blur/Enter → save).
**Клик `[✏]`** на уроке → открывает `LessonEditorDrawer`.
**Клик `[🗑]`** → Modal confirm с потерей прогресса учеников.

Кнопка `+ Добавить урок ▾` — dropdown с пунктами:
- `Теория (текст + видео)` (bi-text-left)
- `Видео-урок` (bi-camera-video)
- `Квиз` (bi-question-circle)

API: `PATCH /api/admin/onboarding/modules/{id}` или `/lessons/{id}` с `{order_index}`.

### 1c. LessonEditorDrawer (`components/Onboarding/Admin/LessonEditorDrawer.tsx`)

Drawer справа, `w-[560px]`, overlay `bg-black/30`. Z-index поверх страницы.

**Общая структура**:
- Header: `[✕]` слева + title `Редактировать урок` / `Новый урок` + `[Сохранить]` справа
- Body (scroll):
  - Название (input required)
  - Тип урока (badge readonly — нельзя менять после создания)
  - Длительность (number input + «мин»)
  - Разделитель
  - **Контент** — зависит от kind:
    - **theory** → `<ContentBlocksBuilder />`
    - **video** → short form (источник + URL + auto-parse + iframe preview)
    - **quiz** → `<QuizQuestionsBuilder />`
- Footer sticky: `[Отмена]` + `[Сохранить]`

**Kind=video short form**:
- Source radio: `Google Drive` / `Loom` / `YouTube` / `Vimeo`
- URL input с auto-parse при onBlur (для Drive — конвертация `/file/d/{ID}/view` → `/file/d/{ID}/preview`)
- Error inline если URL невалиден (text-danger text-xs)
- iframe preview если URL валиден (`aspect-video rounded-lg border`)

**Unsaved confirm**: `Есть несохранённые изменения. Закрыть без сохранения?`

### 1d. QuizQuestionsBuilder (`components/Onboarding/Admin/QuizQuestionsBuilder.tsx`)

Внутри LessonEditorDrawer для kind=quiz.

Каждый вопрос — `card p-4 mb-3`:
- Текст вопроса (textarea 2 rows required)
- Тип ответа (radio: `Один правильный` / `Несколько правильных`)
- Варианты ответов: список row'ов с `[✓ radio/checkbox] [input текст] [🗑]`
  - При single — radio (один правильный)
  - При multi — checkbox (несколько правильных)
- Кнопка `+ Добавить вариант`
- Пояснение (textarea опц., показывается студенту после ответа)
- Балл за вопрос (number 1-10)
- Кнопки `[↑][↓][🗑]` справа в header вопроса

**Правила**:
- Минимум 2 варианта (кнопка `[🗑]` варианта disabled при ≤2)
- Минимум 1 правильный (inline error: `Отметь хотя бы один правильный ответ`)
- Переключение single→multi сбрасывает `is_correct` если >1 правильного с confirm

Empty state: «Вопросов пока нет. Минимум 5 вопросов для финального квиза модуля.»

## 2. ContentBlocksBuilder + BlockEditor/* (для theory lesson)

`components/Onboarding/Admin/ContentBlocksBuilder.tsx` оркестратор + 6 типов блоков в `components/Onboarding/Admin/BlockEditor/`.

### Доступные kinds
- `markdown` — текст в Markdown (заголовки/списки/код/цитаты)
- `image` — URL картинки + caption
- `drive_video` — Google Drive embed (auto-parse `/file/d/{ID}/view` → `/preview`)
- `loom_video` — Loom embed
- `youtube_video` — youtube_id (11 chars, не full URL)
- `callout` — выделенный блок (info/warning/success/danger)

### Wireframe ContentBlocksBuilder
- Vertical column of blocks
- Каждый блок — header с badge kind + `[↑][↓][🗑]` + body редактирования
- Внизу dropdown `+ Добавить блок ▾` с 6 пунктами

### MarkdownBlock
- Textarea `font-mono text-sm leading-relaxed`
- Кнопка-ссылка `👁 Предпросмотр` → toggle collapse panel с `react-markdown` render
- Класс preview: `prose prose-sm max-w-none`

### DriveVideoBlock
- Поля: Название / URL / Длительность / iframe preview
- Auto-parse при onBlur через `parseDriveUrl(url)`:
  - `drive.google.com/file/d/{ID}` → extract ID → `https://drive.google.com/file/d/{ID}/preview`
  - Если уже `/preview` → as-is
  - Иначе → null + ошибка inline
- Success state: `bi-check-lg text-success` + «Ссылка распознана»
- iframe: `<iframe src={parsedUrl} className="w-full aspect-video rounded-lg border" allow="autoplay; encrypted-media" allowFullScreen />`

### YouTubeVideoBlock
- `parseYoutubeId(url)` — принимает `youtube.com/watch?v=ID`, `youtu.be/ID`, `youtube.com/embed/ID`
- Сохраняет только `{kind: "youtube_video", data: {youtube_id: "..."}}`
- Preview: `<img src="https://img.youtube.com/vi/{id}/hqdefault.jpg" />` (не iframe в редакторе — оптимизация)

### LoomVideoBlock
- `parseLoomId(url)` — принимает `loom.com/share/{ID}` или `loom.com/embed/{ID}`
- Сохраняет `{kind: "loom_video", data: {loom_id: "..."}}`

### CalloutBlock
- Select: `info` / `warning` / `success` / `danger`
- Text input
- Live preview прямо под полями: `rounded-lg border-l-4 p-3 text-sm` с цветовой полосой и иконкой
- Иконки: `bi-info-circle-fill text-info` / `bi-exclamation-triangle-fill text-warning` / `bi-check-circle-fill text-success` / `bi-exclamation-circle-fill text-danger`

### ImageBlock
- URL + Caption fields
- При валидном URL: `<img src={url} alt={caption} className="w-full rounded-lg max-h-64 object-contain border" />`
- При broken: placeholder `bi-image text-gray-300 text-4xl`

Empty state: «Урок пустой. Добавь первый блок: текст, видео или выделенный блок.»

## 3. Student Lesson View — рендер ContentBlocks

`components/Onboarding/Student/ContentBlockRenderer.tsx` + readonly views в `blocks/`:

- `MarkdownBlockView` — `<div className="prose prose-sm max-w-prose mb-6"><ReactMarkdown>{content}</ReactMarkdown></div>`
- `DriveVideoBlockView` — iframe `src={drive_url}` с `aspect-video rounded-lg shadow-md border` + `allow="autoplay; encrypted-media"` + `sandbox="allow-same-origin allow-scripts allow-popups"`
- `LoomVideoBlockView` — `src="https://www.loom.com/embed/{loom_id}?hide_owner=true&hide_share=true"`
- `YouTubeVideoBlockView` — `src="https://www.youtube-nocookie.com/embed/{youtube_id}?rel=0&modestbranding=1"` (no-cookie для privacy)
- `CalloutBlockView` — `border-l-4 p-4 rounded-r-lg flex gap-3 items-start` + цвет по style
- `ImageBlockView` — `<img>` + caption italic

### CSP `frame-src` whitelist (next.config.mjs)
```js
"frame-src 'self' https://drive.google.com https://docs.google.com https://www.loom.com https://loom.com https://www.youtube-nocookie.com https://www.youtube.com https://player.vimeo.com"
```

**ОТКРЫТЫЙ ВОПРОС**: Может конфликтовать с OnlyOffice DocEditor — нужно добавить `https://office.contracts.macroglobal.tech` и проверить совместимость с `script-src 'unsafe-eval'` (OnlyOffice требует).

## 4. Admin Progress Matrix

`components/Onboarding/Admin/ProgressMatrix.tsx` + `ProgressMatrixCell.tsx` + `UserCourseDetailsDrawer.tsx`.

### Таблица
Фильтры: `Все курсы` / `Все роли` / `Все статусы` + кнопка `Сбросить`.

Колонки: Сотрудник | Курс А | Курс Б | ... | Avg %
Ячейка ProgressMatrixCell:
- Прогресс-бар `h-1.5 rounded-full bg-gray-200 mt-1` с цветом по статусу:
  - completed → `bg-success`
  - in_progress → `bg-primary-light`
  - overdue → `bg-danger`
  - not_started → `bg-gray-300`
- Процент `text-xs font-medium tabular-nums`
- Badge статуса `text-[10px] px-1.5 py-0.5`
- `unassigned` → курсивный `—`
- Hover: `bg-gray-50 cursor-pointer`
- Click → открыть `UserCourseDetailsDrawer`

### UserCourseDetailsDrawer
Header: `{full_name} — {course_title}`.
Body:
- Прогресс-бар крупный + badge статус
- `Назначен: {дата}` / `Дедлайн: {дата} (осталось/просрочено N дн.)`
- Список модулей с раскрытием уроков (accordion)
- Иконки статуса: `bi-check-circle-fill text-success` / `bi-circle-half text-info` / `bi-circle text-gray-300`
- Для quiz — кол-во попыток + кнопка `Сбросить` (с confirm)
- Footer: `Снять назначение` (btn-secondary text-danger)

## 5. WelcomeWizard 3-шаговый

`components/Onboarding/WelcomeWizard.tsx`.

### Шаг 1 — Welcome Modal (всегда при первом логине если `is_onboarding_wizard_shown=false`)
Центрированный иконка `bi-mortarboard-fill text-5xl text-primary`.
Heading: «Добро пожаловать в MACRO CRM!»
Subtext: «Чтобы быстро влиться в работу, пройди короткое обучение. Это займёт около часа.»
CTA: `Начать обучение →` → PATCH `/api/users/me/onboarding-wizard {wizard_step: "completed"}` → redirect `/onboarding`
Skip: `Пропустить сейчас` → PATCH `{dismissed_until: now()+24h}`
Hint: «Ты всегда найдёшь курсы в разделе Обучение»

### Шаг 2 — Profile Modal (опц. если `full_name == null || phone == null`)
Полное имя* / Телефон с placeholder `+7 900 000-00-00`.

### Шаг 3 — Опыт с CRM (опц.)
Radio: `Никогда не работал с CRM` / `Базовый опыт` / `Продвинутый пользователь` → PATCH `{crm_experience_level: "none|basic|advanced"}`.

### Sidebar badge
В `Sidebar.tsx` для пункта `/onboarding` — круглый badge:
- overdue_count > 0 → `bg-danger text-white text-[10px] font-bold` с числом (>9 → "9+")
- in_progress_count > 0 → `bg-info text-white` с числом
- иначе ничего

Хук `useOnboardingBadge()` → SWR `/api/onboarding/my-courses?summary=true` с `refreshInterval: 60_000`.

## 6. Empty States (готовые тексты)

| Где | icon | title | desc | CTA |
|---|---|---|---|---|
| `/onboarding` пустой | `bi-mortarboard-fill` | «Курсов пока нет» | «Как только администратор назначит обучение — оно появится здесь» | — |
| `/admin/onboarding/courses` пустой | `bi-collection-fill` | «Курсов пока нет» | «Создай первый курс для онбординга команды» | `+ Создать курс` |
| Lesson editor без блоков | `bi-layout-text-window` | «Урок пустой» | «Добавь первый блок: текст, видео или изображение» | `+ Добавить блок ▾` |
| Quiz editor без вопросов | `bi-question-circle` | «Вопросов пока нет» | «Минимум 5 вопросов для финального квиза модуля» | `+ Добавить вопрос` |

## 7. OnboardingRequiredModal (soft-gate)

`components/Onboarding/OnboardingRequiredModal.tsx`. Перехват 403 `{code: "onboarding_required"}` в bulk-компонентах.

Wireframe:
- Иконка `bi-mortarboard-fill text-warning text-5xl` центр
- Heading: «Сначала заверши обязательные курсы»
- Body: «У тебя есть просроченные курсы онбординга. Bulk-операции временно недоступны.»
- CTA: `Открыть обучение →` (full-width btn-primary) → `router.push("/onboarding")`
- Cancel: `Отмена` (btn-ghost)

Интеграция в `components/Bulk/BulkDocumentModal.tsx`:
```tsx
catch (err) {
  if (err?.code === "onboarding_required") {
    setOnboardingModalOpen(true)
    return
  }
  // обычная обработка
}
```

## 8. Файлы для создания (полный inventory)

### Pages (5)
- `app/(app)/onboarding/page.tsx`
- `app/(app)/onboarding/courses/[id]/page.tsx`
- `app/(app)/admin/onboarding/courses/page.tsx`
- `app/(app)/admin/onboarding/courses/new/page.tsx`
- `app/(app)/admin/onboarding/courses/[id]/edit/page.tsx`

### Admin компоненты (16)
- `components/Onboarding/Admin/CourseForm.tsx`
- `components/Onboarding/Admin/CourseStructureBuilder.tsx`
- `components/Onboarding/Admin/LessonEditorDrawer.tsx`
- `components/Onboarding/Admin/QuizQuestionsBuilder.tsx`
- `components/Onboarding/Admin/ContentBlocksBuilder.tsx`
- `components/Onboarding/Admin/BlockEditor/MarkdownBlock.tsx`
- `components/Onboarding/Admin/BlockEditor/ImageBlock.tsx`
- `components/Onboarding/Admin/BlockEditor/DriveVideoBlock.tsx`
- `components/Onboarding/Admin/BlockEditor/LoomVideoBlock.tsx`
- `components/Onboarding/Admin/BlockEditor/YouTubeVideoBlock.tsx`
- `components/Onboarding/Admin/BlockEditor/CalloutBlock.tsx`
- `components/Onboarding/Admin/ProgressMatrix.tsx`
- `components/Onboarding/Admin/ProgressMatrixCell.tsx`
- `components/Onboarding/Admin/UserCourseDetailsDrawer.tsx`

### Student компоненты (7)
- `components/Onboarding/Student/ContentBlockRenderer.tsx`
- `components/Onboarding/Student/blocks/MarkdownBlockView.tsx`
- `components/Onboarding/Student/blocks/ImageBlockView.tsx`
- `components/Onboarding/Student/blocks/DriveVideoBlockView.tsx`
- `components/Onboarding/Student/blocks/LoomVideoBlockView.tsx`
- `components/Onboarding/Student/blocks/YouTubeVideoBlockView.tsx`
- `components/Onboarding/Student/blocks/CalloutBlockView.tsx`

### Shared (2)
- `components/Onboarding/WelcomeWizard.tsx`
- `components/Onboarding/OnboardingRequiredModal.tsx`

### Utils (1)
- `lib/video-parsers.ts` — `parseDriveUrl`, `parseYoutubeId`, `parseLoomId` (без `any`)

### Модификации
- `Sidebar.tsx` — useOnboardingBadge + badge на «Мои курсы» + ADMIN_ITEMS пункт «Курсы и онбординг»
- `next.config.mjs` — `headers()` с CSP `frame-src`
- `components/Bulk/BulkDocumentModal.tsx` — перехват 403 onboarding_required
- `lib/types.ts` — union type `ContentBlock = MarkdownBlock | ImageBlock | DriveVideoBlock | LoomVideoBlock | YouTubeVideoBlock | CalloutBlock`

## 9. Открытые вопросы

1. **CSP + OnlyOffice** — потенциальный конфликт `frame-src` с OnlyOffice DocEditor домена `office.contracts.macroglobal.tech` + `script-src 'unsafe-eval'`. Требует тестирования перед мержем.
2. **react-markdown** — добавить в package.json `react-markdown@^9` (ESM, Next 14 совместимо).
3. **video-parsers.ts utility** — общие для Editor + View. Типизация строго без `any`.
4. **useOnboardingBadge SWR** — `refreshInterval: 60_000` (раз в минуту).
5. **ContentBlock TypeScript union** — в `lib/types.ts` discriminated union по `kind`.
6. **Bulk-компоненты для перехвата 403** — frontend-specialist должен найти все места где есть bulk операции (BulkDocumentModal + где ещё в `/registry`/`/counterparties`).
