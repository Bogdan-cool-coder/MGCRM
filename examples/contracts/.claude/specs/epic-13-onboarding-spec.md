# Эпик 13: Онбординг — полный design+product spec

**Источники (2026-05-31):**
- Web research best practices: Trainual, WorkRamp, Lessonly + microlearning principles 2026 (3-6 min lessons, video 5-8 min, +17% retention с встроенными knowledge checks, captions обязательны, personalized paths)
- PM план (product-manager): scope MVP vs v2 vs v3, бизнес-цели, KPI, integration с эпиками 4/8/11
- Designer концепт (designer): полный UX для admin builder + student UI + onboarding wizard + RBAC матрица + 8 wireframes + RU тексты

## TL;DR
Онбординг = новая подсистема MACRO CRM для онбординга новых sales/lawyer/director/admin без участия Богдана. Курсы → модули → уроки (theory/video/quiz) с auto-assign по роли. MVP 5-7 дней работы, 8-10 таблиц, миграция 0031. Контент видео — embed (Loom/Vimeo/YouTube), без own storage.

## Бизнес-цели
- Снять Богдана с 2-4ч ручного онбординга каждого новичка
- Стандартизация знаний (сейчас передаётся устно — каждый по-своему)
- Time-to-first-deal новичка с ~5 дней до 2 дней
- Подготовить инфру к массовому переобучению при switch с AmoCRM (Эпик 9)
- Compliance-ready (когда понадобится — audit trail квизов уже будет)

## 4 роли — 4 курса в первой партии

| Роль | Курс | Длительность | Приоритет |
|---|---|---|---|
| manager | MacroSales: продажи в MACRO CRM | ~60 мин | P0 |
| lawyer | Документооборот (шаблоны + OnlyOffice + ApprovalRoute) | ~75 мин | P1 |
| director | Customer Success реестр + lifecycle | ~60 мин | P2 |
| admin | Администрирование платформы (Automations + Webhooks + API + Custom Fields + Users/Roles) | ~120 мин | P3 |

## Архитектура данных

```
Course (target_roles[], is_published, passing_score_pct)
├── CourseModule (order)
│   └── CourseLesson (kind: theory | video | quiz, content JSON, duration_min, order)
│       └── LessonQuizQuestion (kind: single|multi, options, correct_answers, points, order)
│
UserCourseAssignment (user_id, course_id, assigned_by, due_at, is_mandatory)
UserCourseProgress (status: not_started|in_progress|completed|overdue, percent)
UserLessonProgress (lesson_id, completed_at, attempts_count, best_score_pct)
QuizAttempt (started_at, finished_at, score_pct, answers JSON, passed)
```

8 таблиц + индексы на горячих read-путях (user_id, course_id+status). Миграция 0031 (advisory-lock seed для дефолтной автоматизации user.created → assign_courses).

## API контракт (high-level)

### Студент
- `GET /api/onboarding/my-courses` — список + прогресс
- `GET /api/onboarding/courses/{id}` — курс + модули + уроки (без правильных ответов на quiz)
- `GET /api/onboarding/lessons/{id}` — контент урока
- `POST /api/onboarding/lessons/{id}/complete` — отметить theory/video
- `POST /api/onboarding/lessons/{id}/quiz/start` — начать попытку
- `POST /api/onboarding/lessons/{id}/quiz/submit` — сдать ответы
- `GET /api/onboarding/quiz-attempts/{id}` — результат
- `GET /api/onboarding/courses/{id}/certificate` — PDF (если completed; через docxtpl)
- `PATCH /api/users/me/onboarding` — сохранить crm_experience_level + профиль

### Admin (admin/director)
- CRUD `/api/admin/onboarding/courses`
- `POST /api/admin/onboarding/courses/{id}/publish` / `unpublish`
- `POST /api/admin/onboarding/modules` + reorder
- `POST /api/admin/onboarding/lessons` (kind в body) + reorder
- `POST /api/admin/onboarding/lessons/{id}/questions`
- `POST /api/admin/onboarding/assign` (user_ids[] или role)
- `GET /api/admin/onboarding/progress` — матрица user × course
- `POST /api/admin/quiz-attempts/{id}/reset` — сбросить попытки

## UI страницы

### Student
- `/onboarding` — карточки курсов с прогрессом, продолжить с места
- `/onboarding/courses/[id]` — двухколоночный layout (sidebar модулей + контент урока), sticky nav снизу (Пред/След), прогресс-полоса сверху, gating квизами
- Lesson views:
  - **Theory** — Markdown render (react-markdown + Tailwind Typography), кнопка «Отметить прочитано»
  - **Video** — iframe embed с auto-detect provider (YouTube/Loom/Vimeo), aspect-video, опц. transcript collapse, кнопка «Я посмотрел»
  - **Quiz** — вопрос за вопросом (не весь сразу), single/multi-choice radio/checkbox, прогресс-бар вопросов, счётчик попыток, кнопка «Далее» disabled до выбора
- **Quiz result** — pass/fail card, разбор ответов под спойлером, попытка/попытки исчерпаны

### Admin
- `/admin/courses` — список + filter (роль/статус) + табы «Курсы / Прогресс команды»
- `/admin/courses/[id]/edit` — Builder: course settings card + module accordion + lesson rows + inline drawer редактирования урока справа (theory/video/quiz)
- **Quiz builder** в drawer'е: список вопросов + add question + variant rows с radio «правильный»
- **Progress matrix** (таб) — user × course с прогресс-барами, click → drawer с детальным прогрессом

### Wizard первого логина
1. Welcome modal (приветствие + «Начать обучение» / «Пропустить»)
2. (если нет full_name/phone) — Profile modal
3. (опц.) Опыт с CRM (none/basic/advanced) — для будущей персонализации

### Sidebar
- **Обучение** секция (для всех ролей): «📚 Мои курсы» (`bi-mortarboard-fill`) с badge непройденных
- **Настройки** (admin/director): «Курсы и онбординг» (`bi-collection-fill`)

## RBAC матрица

| Действие | admin | director | manager | lawyer |
|---|---|---|---|---|
| CRUD курсов | ✓ | ✓ | — | — |
| Опубликовать | ✓ | ✓ | — | — |
| Назначить пользователю | ✓ | ✓ | — | — |
| Просмотр своих курсов | ✓ | ✓ | ✓ | ✓ |
| Просмотр прогресса команды | ✓ | ✓ | — | — |
| Скачать сертификат | ✓ | ✓ | ✓ | ✓ |
| Сбросить попытки quiz | ✓ | ✓ | — | — |

## Integration с эпиками

- **Эпик 4 PipelineAutomation**: новый системный триггер `user.created` + action `assign_courses(by_role=true)`. Seeded автоматизация в миграции 0031.
- **Эпик 8 Audit Log**: `lesson_viewed`, `quiz_attempt_submitted`, `course_completed`, `course_published`, `course_assigned`
- **Эпик 11 Webhooks** (v2): outbound events `user.onboarding_completed`, `quiz.failed`, `quiz.passed`
- **Эпик 7 TG-бот** (когда не отложен): `course_assigned`, `course_overdue`, `quiz_failed_max_attempts`

## Что НЕ в MVP

1. Сертификаты PDF (v2 — простой docxtpl шаблон)
2. TG-уведомления (зависит от Эпика 7)
3. Gamification (badges/XP/leaderboard)
4. Версионирование курсов
5. RBAC-блокировка «нельзя продавать до курса»
6. Аналитика drop-off/когорты
7. Текстовые ответы в квизах (regex/AI проверка)
8. SCORM/xAPI
9. Own video storage (S3 + transcode)
10. Mobile app
11. AI-генерация квизов
12. Многоязычие
13. Markdown WYSIWYG (MVP — textarea с live preview)
14. Drag-and-drop reorder (MVP — кнопки ↑↓)
15. Перемешивание вопросов
16. Ветвления (адаптивный flow)
17. Комментарии к урокам

## Open questions (для решения Богдана перед стартом)

1. **Хостинг видео**: Loom (рекомендуется — корпоративный standard) / Vimeo unlisted / YouTube unlisted?
2. **Кто пишет курсы**: Богдан / senior manager / нанятый методолог?
3. **Обязательность курсов**: блокирующая (нельзя продавать) или информационная (только badge)? → MVP informational, v2 решит
4. **Существующие сотрудники**: ручное назначение admin'ом ИЛИ one-time seeder в миграции 0031?
5. **Дедлайн default**: 3 / 5 / 7 рабочих дней без дедлайна?
6. **Сертификат PDF**: включить в MVP (простой шаблон) или v2?
7. **Markdown editor**: textarea с preview (MVP) или CodeMirror (отдельный эпик)?

## Метрики успеха (MVP)

| Метрика | Цель | Action trigger |
|---|---|---|
| % сотрудников с обязательными курсами за 3 дня | ≥ 80% | <60% → курсы слишком длинные |
| Средний балл по квизам | ≥ 85% | <75% на квизе → переписать урок |
| Drop-off rate на уроке | <20% | >30% → узкое место |
| Кол-во попыток quiz avg | ≤2 | >3 → квиз слишком сложен |
| Time-to-first-deal (manager) | <= 2 дня | baseline ~5 дней |
| Богданово время на онбординг | <= 30 мин | baseline ~3 часа/новичок |
| NPS обучения (опрос) | ≥ 4.0/5 | <3.5 → редизайн |

## Estimate

MVP — **5-7 дней** марафонного темпа:
- День 1-2: Backend (миграция 0031 + 8 моделей + 25+ endpoints + automation триггер + pytest)
- День 2-3: Designer уже сделал ТЗ — переход к frontend сразу
- День 3-5: Admin Builder UI (course builder + lesson drawer + quiz builder)
- День 4-6: Student UI (страницы + lesson views + quiz UI + wizard первого логина)
- День 5-6: Integration + QA smoke
- День 6-7: Деплой
