# Аудит домена: Онбординг — курсы, уроки, квизы, назначения, прогресс, сертификаты, AI-тьютор, HR

> Дата аудита: 2026-06-24 · Ключ домена: `onboarding` · Спринт: S3 (S3.1–S3.8)
> Источники: Phase-1 `onboarding.json`, Phase-2 verdicts (`onboarding__b0/b1/b2/majors`), Phase-3 live-QA (`live-qa.md`), live-схема (`schema.sql`), live row counts (`rowcounts.txt`).

## 1. Назначение

Домен — внутренний LMS (корпоративное обучение/онбординг сотрудников MACRO Global). Покрывает полный цикл: HR/админ создаёт курсы → модули → уроки (`text`/`video`/`pdf`/`quiz`), строит и AI-генерирует квизы, назначает курсы сотрудникам; сотрудник проходит уроки, сдаёт квизы, общается с AI-тьютором по уроку и получает PDF-сертификат при завершении; HR следит за прогрессом через дашборд. Это **самый проработанный по backend'у домен**: схема БД совпадает с моделями, реализованы все админ-CRUD, политики и событийная цепочка выдачи сертификата.

**Общая зрелость: каркас в проде / частично готов (backend «зрелый», учебный цикл сотрудника СЛОМАН).** Обоснование: при формально полном backend'е (вся авторская часть, HR-дашборд, событие `CourseCompleted` → выдача сертификата) **сквозной learning loop студента нерабочий end-to-end**. Единственный источник данных урока для студента (`AssignmentDetailResource`) не отдаёт поле `content` и не фильтрует по `is_published` — любой текст/видео/pdf-урок рендерится пустым, а черновые/неопубликованные уроки и сам неопубликованный курс отдаются учащемуся. Это однозначно подтверждено живыми row counts: при наличии 1 курса, 4 уроков, 1 квиза и 3 назначений **все таблицы активности пусты** — `lesson_progress=0`, `quiz_attempts=0`, `certificates=0`, `onboarding_ai_sessions=0`. То есть фичу нельзя пройти, и её ни разу не прошли.

## 2. Карта процессов

| Процесс | Кто (роли) | Где (UI + endpoint) | Как (шаги) | Статус | Примечание |
|---|---|---|---|---|---|
| Авторинг курс → модули → уроки | admin, director | CourseBuilderPage → `admin/onboarding/courses\|modules\|lessons` CRUD | Создать курс; добавить модули; уроки (text/video/pdf/quiz); upload PDF; dnd-reorder; publish-guard ≥1 модуль с ≥1 опубл. уроком | ✅ работает | Схема = модели; guard'ы publish/delete по спеке |
| Построение / правка квиза (вопросы + опции) | admin, director | QuizBuilderDrawer → `quizzes`+`quiz-questions`+`quiz-options` | Создать квиз (1:1 lesson.kind=quiz); добавить вопросы с опциями; при Save новые вопросы создаются, но правка существующих → 404 | 🔴 сломан | FE↔BE mismatch путей: FE шлёт вложенный `/quizzes/{q}/questions/{id}`, BE регистрировал shallow `/quiz-questions/{id}` |
| AI-генерация вопросов квиза | admin, director | QuizBuilderDrawer «Сгенерировать (AI)» → `POST lessons/{lesson}/generate-questions` (202) | Job каскад `quiz_generation`, пишет `is_draft=true` вопросы + статус в `Lesson.content`; FE poll'ит `quiz.ai_generation_status==='completed'` | 🔴 сломан | Resource никогда не отдаёт `ai_generation_status`; вдобавок BE пишет `'done'`, FE ждёт `'completed'` — спиннер крутится вечно |
| HR-ревью AI-черновиков | admin, director | QuizBuilderDrawer / `PATCH quiz-questions` | Спека: HR ревьюит AI-черновики и снимает `is_draft` через PATCH до выхода в прод | 🔴 сломан | `is_draft` не принимается в `UpdateQuizQuestionRequest`, не отдаётся в admin-resource, не фильтруется на студент/score-путях |
| Назначение курса сотрудникам | admin, director | AssignCourseDrawer / OnboardingAssignmentsPage → `POST assignments` (bulkAssign) | Выбрать пользователей + опц. due_date; firstOrCreate; dispatch `CourseAssigned` | 🟡 частично | Назначение создаётся, но: (1) у `CourseAssigned` нет слушателя — нет уведомления; (2) `deadline_days` не применяется → `due_date=null` |
| Студент проходит урок (text/video/pdf) | любой назначенный | CoursePage/LessonView → `GET onboarding/assignments/{id}` | Открыть курс; навигация по дереву; читать тело; mark complete | 🔴 сломан | LIVE-подтверждено: payload урока без `content`/`duration` → пустое тело. Объясняет `lesson_progress=0` |
| Студент сдаёт квиз | любой назначенный | useQuizAttempt → `GET lessons/{id}/quiz`; `start`; `submit` | Получить квиз; start (идемпотентно); submit; computeScore exact set-match | 🔴 сломан | `computeScore` по НЕфильтрованным вопросам (вкл. `is_draft`); нет publish/draft-gate; неопубл. квиз 4 отдаёт 200. `quiz_attempts=0` |
| Студент использует AI-тьютор | любой назначенный | AiTutorDrawer/useAiTutor → `POST/GET/DELETE lessons/{id}/ai-tutor` | Синхронный Sonnet-каскад; история (10 пар) server-side в `onboarding_ai_sessions`; 503 при ошибке AI | ⚪ не верифицировано | Vault smoke-test прошёл; авторизация слабая (200 вместо 403 на чужой урок); `onboarding_ai_sessions=0` — в этой среде не запускалось |
| Выдача сертификата при завершении | system, назначенный | `CourseCompleted` → `GenerateCertificateListener` → `GenerateCertificateJob` → `CertificateService` | На all-lessons complete: событие; idempotency guard; резерв `CERT-{YYYY}-{N}`; PHPWord → Gotenberg → PDF; строка Certificate | ⚪ не верифицировано | Слушатель ЗАрегистрирован; vault smoke-test (`CERT-2026-0001`, 34KB PDF). `certificates=0` — реального завершения не было (loop сломан выше) |
| Перегенерация сертификата (admin) | admin, director | `POST certificates/{assignment}/regenerate` → Job | Authorize роли; удалить строку cert; dispatch Job | 🟡 частично | Нет completion-guard: админ может выпустить нумерованный PDF (сжигая sequence-номер) для незавершённого назначения |
| HR дашборд прогресса | admin, director | HrProgressPage → `GET progress`, `progress/summary` | Таблица по сотрудникам + KPI + status-pie + top-courses bar; completion_rate live COUNT; is_overdue PHP; avg_quiz_score AVG только passed | ✅ работает | Функционально готов и протестирован; но N+1 per-row и hardcode hex в графиках (нарушение DS). Показывает 0/пусто т.к. активности нет |

Сводно по статусам: **✅ 2 · 🟡 3 · 🔴 5 · ⚪ 2** (из 12 процессов).

## 3. Модель данных и реальность БД

| Модель | Таблица | Назначение | Строк в живой БД | Статус |
|---|---|---|---|---|
| Course | `courses` | Курс онбординга | 1 | built — единственный курс, **`is_published=false`** |
| CourseModule | `course_modules` | Модуль внутри курса | 2 | built |
| Lesson | `lessons` | Урок (text/video/pdf/quiz) | 4 | built — опубликован только урок 1 (text); 2(video)/3(pdf)/4(quiz) — draft |
| Quiz | `quizzes` | Квиз 1:1 с уроком kind=quiz | 1 | built — `UNIQUE(lesson_id)` |
| QuizQuestion | `quiz_questions` | Вопрос квиза | 3 | **partial** — колонка `is_draft` есть, но не фильтруется/не очищается/не отдаётся |
| QuizOption | `quiz_options` | Вариант ответа | 12 | built |
| CourseAssignment | `course_assignments` | Назначение курса сотруднику | 3 | built — **3 назначения на НЕопубликованный курс** (артефакт seed либо publish→unpublish) |
| LessonProgress | `lesson_progress` | Прогресс по урокам | **0** | built, пусто — студент-флоу неюзабелен (пустой контент) |
| QuizAttempt | `quiz_attempts` | Попытки сдачи квиза | **0** | built, пусто — квиз-урок неопубликован, loop сломан выше |
| Certificate | `certificates` | Выданный сертификат | **0** | built, пусто — ни одного завершения до `CourseCompleted` |
| CertificateNumberSequence | `certificate_number_sequences` | Погодовой счётчик `SELECT FOR UPDATE` | **0** | built, никогда не задействован |
| OnboardingAiSession | `onboarding_ai_sessions` | Сессия AI-тьютора (1 на user+lesson, messages JSONB) | **0** | built, никогда не задействован |

**Расхождения migration ↔ live-schema ↔ model и пустые-при-наличии-кода таблицы:**

- **`quiz_questions.is_draft`** — миграция `2026_06_14_300012_add_is_draft_to_quiz_questions_table.php` (boolean NOT NULL default false), в live-schema присутствует, в модели в casts/fillable, выставляется `QuizGenerationService:70` в `true`. **Drift:** колонка пишется, но НЕ читается на студент/score-путях, НЕ валидируется в `UpdateQuizQuestionRequest`, НЕ отдаётся в `QuizQuestionAdminResource` — фактически осиротевшая под свою цель (HR-ревью).
- **`lessons.content` (статус генерации)** — JSONB default `'{}'`, присутствует. AI-статус (pending/running/done/failed) пишется в `content` через `LessonService:317`. **Drift:** студенческий `AssignmentDetailResource` вообще не отдаёт `content`; FE AI-поллинг ждёт `ai_generation_status` на ресурсе КВИЗА, а BE пишет в `lesson.content` — split-brain по месту хранения статуса.
- **`course_assignments.due_date` vs `course.deadline_days`** — обе колонки nullable, присутствуют. **Drift:** `deadline_days` никогда не пропагируется в `due_date`; назначения создаются с `due_date=null`, хотя UI намекает на применение дефолта.
- **`quiz_attempts.assignment_id` FK** — `ON DELETE SET NULL`. **Drift:** `AssignmentService::delete` гардит только наличие `LessonProgress`; назначение с попытками (но без прогресса) можно физически удалить → осиротевшие `quiz_attempts` c `assignment_id=null`.
- **Заполнение таблиц активности** — `lesson_progress`/`quiz_attempts`/`certificates`/`onboarding_ai_sessions` созданы, но по 0 строк. **Built-but-never-exercised:** journey ни разу не завершён (blocker пустого контента). 3 `course_assignments` на неопубликованном курсе — seed-артефакт или publish→unpublish-регрессия.

## 4. Эндпоинты и покрытие фронтом

| Метод + Path | Контроллер@метод | Авторизация | Вызывается FE? | Примечание |
|---|---|---|---|---|
| GET/POST/PATCH/DELETE `/api/admin/onboarding/courses(+{id},publish,unpublish)` | CourseController | CoursePolicy admin/director | Да | OnboardingAdminCoursesPage, CourseBuilderPage |
| GET/POST/PATCH/DELETE `/api/admin/onboarding/courses/{id}/modules(+reorder)` | CourseModuleController | admin/director | Да | CourseBuilderPage |
| GET/POST/PATCH/DELETE `/api/admin/onboarding/modules/{m}/lessons(+reorder,publish/unpublish,upload)` | LessonController | admin/director | Да | CourseBuilderPage |
| POST `/api/admin/onboarding/lessons/{lesson}/generate-questions` | LessonController@generateQuestions | admin/director | Да (useAiQuizGeneration) | 202 async; **FE poll-target сломан** |
| GET/POST/PATCH/DELETE `/api/admin/onboarding/quizzes(+{quiz})` | QuizController | QuizPolicy admin/director | Да | QuizBuilderDrawer; resource не отдаёт `ai_generation_status` |
| GET/POST `/api/admin/onboarding/quizzes/{quiz}/questions(+reorder)` | QuizQuestionController index/store/reorder | admin/director | createQuestion — да; reorderQuestions — экспортирован без вызовов | index/store рабочие |
| PATCH/DELETE `/api/admin/onboarding/quiz-questions/{question}` | QuizQuestionController update/destroy | admin/director | **НЕТ** — FE зовёт `/quizzes/{q}/questions/{id}` (404) | **Мёртвый endpoint со стороны FE** — реальный путь BE, но FE на него не ходит |
| GET/POST `/api/admin/onboarding/quiz-questions/{question}/options(+reorder)` | QuizOptionController index/store/reorder | admin/director | **НЕТ** — FE зовёт вложенный `/quizzes/{q}/questions/{id}/options` (404) | Мёртвый со стороны FE |
| PATCH/DELETE `/api/admin/onboarding/quiz-options/{option}` | QuizOptionController update/destroy | admin/director | **НЕТ** — FE зовёт вложенный путь (404) | Мёртвый со стороны FE |
| GET/POST/PATCH/DELETE `/api/admin/onboarding/assignments(+{id},archive)` | AssignmentController | AssignmentPolicy admin/director | Да | OnboardingAssignmentsPage, AssignCourseDrawer |
| GET `/api/admin/onboarding/progress(+/summary)` | ProgressController index/summary | AssignmentPolicy::viewAny admin/director | Да | HrProgressPage |
| GET `/api/admin/onboarding/certificates/{assignment}` | CertificateController@adminShow | admin/director | НЕТ (нет во flow) | Потенциально мёртвый — не встречен в FE-флоу |
| POST `/api/admin/onboarding/certificates/{assignment}/regenerate` | CertificateController@regenerate | regenerate / viewAny (только роль) | (admin) | **Нет completion-status guard** |
| GET `/api/onboarding/my-courses` | StudentCourseController@index | auth:sanctum, own assignments | Да | MyCoursesPage |
| GET `/api/onboarding/assignments/{assignment}` | StudentCourseController@show | owner (AssignmentPolicy::view) | Да | CoursePage/useCoursePage — **единственный источник данных уроков; без `content`, без publish-фильтра** |
| POST `/api/onboarding/lessons/{lesson}/complete` | LessonController@complete | owner | Да | CoursePlayer — но тело урока пустое, путь почти недостижим |
| GET `/api/onboarding/lessons/{lesson}/quiz` | QuizController@showForStudent | resolveAssignment (только ownership) | Да | useQuizAttempt getStudentQuiz — **без publish/is_draft-фильтра** |
| POST `/api/onboarding/lessons/{lesson}/quiz/start` | QuizAttemptController@start | owner, идемпотентно | Да | useQuizAttempt |
| POST/GET `/api/onboarding/quiz-attempts/{attempt}/submit, /{attempt}` | QuizAttemptController submit/show | owner | Да | useQuizAttempt — **computeScore по НЕфильтрованным (вкл. draft) вопросам** |
| POST/GET/DELETE `/api/onboarding/lessons/{lesson}/ai-tutor(+/history)` | AiTutorController ask/history/clearHistory | auth:sanctum, фильтр user_id (**нет authorize('view', lesson)** — 200 вместо 403) | Да | useAiTutor / AiTutorDrawer |
| GET `/api/onboarding/my-certificates` | CertificateController@index | own | Да | MyOnboardingCertificatesPage |
| GET `/api/onboarding/certificates/{assignment}(+/download)` | CertificateController show/download | owner + admin/director | Да | CoursePage completion-poll, MyOnboardingCertificatesPage |

**Orphaned FE-вызовы (FE → несуществующий BE, 404):** `patchQuestion`, `deleteQuestion`, `createOption`, `patchOption`, `deleteOption`, `reorderOptions` в `front/src/api/onboardingAdmin.ts:190–221`. Из них реально вызывается только `patchQuestion` (в `QuizBuilderDrawer.submit()`); остальные 5 — экспортированы без callers.
**Мёртвые BE-endpoint'ы (со стороны FE):** shallow `quiz-questions/{question}` (PATCH/DELETE), `quiz-questions/{question}/options`, `quiz-options/{option}` — корректно зарегистрированы, но FE на них не ходит (зовёт вложенные пути). `certificates/{assignment}` adminShow — не встречен во FE-флоу.

## 5. RBAC домена

| Возможность | Роли | Где реально проверяется | Дыра / примечание |
|---|---|---|---|
| Авторинг курсов/модулей/уроков/квизов, publish, assign, AI-gen, HR-дашборд | admin, director | Policies (`CoursePolicy`/`QuizPolicy`/`AssignmentPolicy`) + router meta `roles:['admin','director']` | Консистентный write-gate; HR-чтение тоже admin/director |
| Прохождение своих назначенных курсов/уроков/квизов, AI-тьютор, сертификаты | любой authenticated | ownership (`assignment.user_id`) / `resolveAssignment` / route owner-checks | MyCoursesPage/CoursePage без role-meta — любой авторизованный; доступ скоупится владением назначения. **Не cross-user breach** (не-владелец получает 403 через `AssignmentPolicy::view`) |
| Гейт доступа студента по publish-статусу | любой authenticated | **ОТСУТСТВУЕТ** | Студенческие пути проверяют только ownership, никогда `is_published` на уроке/курсе → неопубликованный контент достижим (LIVE-подтверждено) |
| Авторизация AI-тьютора по уроку | любой authenticated | только фильтр user_id | Нет `authorize('view', lesson)` → 200 (пусто) вместо 403 на не-назначенном уроке; **утечки данных нет** (сессии per-user), неверный статус |

Итого: write-периметр (авторинг/HR) закрыт корректно и единообразно. Дыра RBAC — **отсутствие publish-gate** на студенческих путях (контент-корректность, не конфиденциальность) и **слабая авторизация AI-тьютора** (неверный код ответа без утечки).

## 6. Бэклог проблем

### Сводная таблица

| # | Severity (FINAL) | Тип | Заголовок | Проверка |
|---|---|---|---|---|
| 1 | 🔴 blocker | BUG | Плеер урока студента рендерит ПУСТО (text/video/pdf) — `content` не отдаётся | ✅ подтверждено (live + DB probe) / 🌐 подтверждено в браузере |
| 2 | 🔴 blocker | DEAD-CODE | Правка существующего вопроса квиза → 404; FE patch/delete + всё option-CRUD на несуществующих вложенных путях | ✅ подтверждено (live probe 404 vs 405) |
| 3 | 🔴 blocker | BUG | AI-черновики отдаются студентам и учитываются в score без HR-ревью; `is_draft` не фильтруется/не очищается/не отображается | ✅ подтверждено (live: admin-resource без `is_draft`; статический разбор всех путей) |
| 4 | 🟠 major | SECURITY | Студент видит неопубл./draft-уроки и неопубл. курс — нет publish-gate на студ-путях | 🌐 подтверждено в браузере (owner probe) |
| 5 | 🟠 major | DEAD-CODE | Спиннер AI-генерации не завершается — FE поллит `quiz.ai_generation_status`, которого resource не отдаёт | ✅ подтверждено (live probe: ключа нет) |
| 6 | 🟠 major | BUG | Quiz builder молча теряет правки опций у существующих вопросов | ⚠️ частично (подтверждено в коде/note b1; отдельной live-проверки не было) |
| 7 | 🔵 minor | BUG | `deadline_days` рекламируется в drawer, но не применяется → назначения без due_date | не верифицировано (Phase-1) |
| 8 | 🔵 minor | BUG | Перегенерация сертификата без completion-guard — админ выпускает cert (сжигая sequence) на незавершённое | не верифицировано (Phase-1) |
| 9 | 🔵 minor | DEAD-CODE | У `CourseAssigned` нет слушателя — уведомление о назначении не доставляется | не верифицировано (Phase-1) |
| 10 | 🔵 minor | SECURITY | AI-тьютор без per-lesson authorize — 200 (пусто) вместо 403 на не-назначенном уроке | не верифицировано (Phase-1) |
| 11 | 🔵 minor | STUB | `CompletionPolicy::SoftGate`, `course.deadline_days`, `AssignmentStatus::Failed` хранятся/возвращаются, но не применяются | не верифицировано (Phase-1) |
| 12 | 🔵 minor | PERF | N+1 расчёт прогресса в студ- и HR-ресурсах | не верифицировано (Phase-1) |
| 13 | 🔵 minor | DATA-INCONSISTENCY | `quiz_attempts` осиротевают при удалении назначения; таблицы активности пусты = флоу не завершался | не верифицировано (Phase-1) |
| 14 | 🔵 minor | CONVENTION | Hardcoded hex в HR-графиках — нарушение дизайн-системы (обе темы) | не верифицировано (Phase-1) |
| 15 | 🔵 minor | CONVENTION | Хардкод RU-строк в студ-тостах/конфирмах в обход i18n | не верифицировано (Phase-1) |
| 16 | ⚪ trivial | CONVENTION | AssignCourseDrawer тянет `/api/users` инлайн и назначает любому без role-скоупинга | не верифицировано (Phase-1) |
| NEW-8 | 🔵 minor | BUG | Кнопка «Продолжить» в MyCoursesPage не навигирует | 🌐 подтверждено в браузере (live-QA) |

### Развёрнутые блоки (blocker / major)

---

#### #1 · Плеер урока студента рендерит ПУСТО для text/video/pdf
**Severity: 🔴 blocker · Тип: BUG · Проверка: ✅ подтверждено (live + DB probe), 🌐 подтверждено в браузере (live-QA A.5 / onboarding#0)**

**Файлы:**
- `src/app/Http/Resources/Onboarding/AssignmentDetailResource.php:49-56` (lesson-map отдаёт только id/title/kind/is_published/completed; нет content/duration_minutes)
- `src/app/Http/Controllers/Onboarding/StudentCourseController.php:45-52` (единственный студ-endpoint детали → AssignmentDetailResource)
- `src/routes/api.php:807-837` (в студ-блоке нет `GET lessons/{lesson}` content-route)
- `front/src/api/onboardingStudent.ts:22-25` (только `getAssignment`; нет per-lesson fetch)
- `front/src/pages/CoursePage/composables/useCoursePage.ts:67,78-80` (модули из `a.course.modules`; навигация только сетит id, без fetch)
- `front/src/pages/CoursePage/components/LessonView.vue:58-71` (читает `lesson.content.markdown/.url/.path` → всегда undefined)
- `front/src/pages/CoursePage/components/LessonViewText.vue` (renderedContent → '' при null → пустой v-html)
- `front/src/entities/course.ts:53-61` (тип Lesson объявляет `content`+`duration_minutes`, которых BE не шлёт — контракт-mismatch)

**Что происходит (evidence):** Единственный источник данных урока для студента — `GET /api/onboarding/assignments/{id}` → `AssignmentDetailResource`, чей lesson-map (49-56) эмитит только `id/title/kind/is_published/completed`, без `content` и `duration_minutes`. Выделенного студ-route для тела урока нет. Live-проба (admin@mgcrm.test): `GET /api/onboarding/assignments/1` — каждый объект урока имеет ключи ровно `[id,title,kind,is_published,completed]`, `has_content=false`, при том что урок 1 опубликован и в БД содержит полный markdown (`SELECT content FROM lessons` → урок 1 `{"markdown":"# Welcome to MACRO CRM..."}`, урок 2 `{"url":youtube}`, урок 3 `{"url":pdf}`). Контент существует в БД, но не сериализуется студенту. Браузер (live-QA A.5): урок «Welcome to MACRO CRM» (kind=text) — тело пустое. Это объясняет `lesson_progress=0`.

**Repro:** Войти назначенным пользователем → `/onboarding/my-courses` → открыть курс → тело первого text-урока пустое; video/pdf — то же.

**Предлагаемый фикс:** Добавить `content` + `duration_minutes` в lesson-map `AssignmentDetailResource`, ИЛИ завести выделенный `GET /api/onboarding/lessons/{lesson}` (published-only) и фетчить на `navigateToLesson`. Привести в соответствие тип `front/src/entities/course.ts`.

---

#### #2 · Правка существующего вопроса квиза 404; FE patch/delete + всё option-CRUD на несуществующих путях
**Severity: 🔴 blocker · Тип: DEAD-CODE · Проверка: ✅ подтверждено (live probe: 404 vs 405)**

**Файлы:**
- `front/src/api/onboardingAdmin.ts:190-192` `patchQuestion` → `PATCH /quizzes/{quizId}/questions/{questionId}` (не существует)
- `front/src/api/onboardingAdmin.ts:195-197` `deleteQuestion` (тот же неверный путь; zero callers)
- `front/src/api/onboardingAdmin.ts:205-221` `createOption/patchOption/deleteOption/reorderOptions` → `/quizzes/{quizId}/questions/{questionId}/options/...` (не существуют; zero callers)
- `src/routes/api.php:725` prefix `admin/onboarding`
- `src/routes/api.php:788-789` BE shallow `quiz-questions/{question}` update/delete (единственные существующие update/delete)
- `src/routes/api.php:793-799` BE option-routes под `quiz-questions/{question}` + shallow `quiz-options/{option}`
- `front/src/pages/CourseBuilderPage/components/QuizBuilderDrawer.vue:458` `submit()` зовёт `patchQuestion` для каждого существующего вопроса

**Что происходит (evidence):** Live read-only проба (admin@mgcrm.test): `GET /api/admin/onboarding/quizzes/1/questions/1` → **HTTP 404** (FE-путь не зарегистрирован), `GET /api/admin/onboarding/quiz-questions/1` → **HTTP 405** (BE shallow-путь существует, метод не тот), control `GET /api/admin/onboarding/quizzes/1/questions` → **HTTP 200** (вложенный index существует, как у рабочего `createQuestion`). В Laravel 404 не зависит от метода → PATCH/DELETE по FE-пути тоже 404. `patchQuestion` зашит в `QuizBuilderDrawer.submit()` (458) и срабатывает на каждый сохранённый существующий вопрос → правка квиза с сохранёнными вопросами гарантированно даёт 404 + generic-toast и тихую потерю правок. Остальные 5 функций (deleteQuestion + 4 option-CRUD) экспортированы с нулём callers — мёртвый код с теми же неверными путями.

**Repro:** CourseBuilder → открыть квиз с сохранёнными вопросами → изменить текст вопроса → Save → generic error toast; в network 404 на `/quizzes/{id}/questions/{qid}`.

**Предлагаемый фикс:** Перенаправить `patchQuestion/deleteQuestion` на `/quiz-questions/{question}`; option-CRUD на `/quiz-questions/{question}/options` и `/quiz-options/{option}`; удалить либо подключить осиротевшие экспорты; реализовать option-sync для существующих вопросов (см. #6).

---

#### #3 · AI-черновики отдаются студентам и учитываются в score без HR-ревью; `is_draft` не фильтруется/не очищается/не отображается
**Severity: 🔴 blocker · Тип: BUG · Проверка: ✅ подтверждено (live: admin-resource без `is_draft`; полный статический разбор всех путей)**

> Примечание по severity: Phase-2 b2 предлагал понизить до major (харм латентен: в живой БД 0 строк `is_draft=true`), но финальный adversarial-pass (`onboarding__majors.json` i=3) сохранил **blocker** — отсутствующие фильтры безусловны, и любой AI-сгенерированный черновик мгновенно идёт в прод и в score без возможности ревью. Принимаем blocker, харм латентен до первого AI-прогона.

**Файлы:**
- `src/app/Domain/Onboarding/Models/Quiz.php:52-55` (`questions()` без is_draft-фильтра, только orderBy)
- `src/app/Domain/Onboarding/Services/QuizService.php:51-57` (`listByLesson` грузит все вопросы)
- `src/app/Domain/Onboarding/Services/QuizAttemptService.php:126-131` (`computeScore` по `$quiz->questions`, черновики включены)
- `src/app/Http/Controllers/Onboarding/QuizController.php:95-108` (`showForStudent` → QuizResource, без фильтра)
- `src/app/Http/Resources/Onboarding/QuizResource.php` + `QuizQuestionResource.php` (студенту is_draft не виден — что верно, но он и не отфильтрован)
- `src/app/Http/Resources/Onboarding/QuizQuestionAdminResource.php:18-29` (HR не видит is_draft)
- `src/app/Http/Requests/Onboarding/UpdateQuizQuestionRequest.php:21-26` (is_draft не принимается)
- `src/app/Domain/Onboarding/Services/QuizQuestionService.php:57-81` (update не пишет is_draft)
- `src/app/Domain/Onboarding/Services/QuizGenerationService.php:70` (ставит is_draft=true)
- `src/database/migrations/2026_06_14_300012_add_is_draft_to_quiz_questions_table.php:12` (заявляет намерение: HR-ревью до выхода в прод)

**Что происходит (evidence):** Намеченный HR-review gate (миграция + докблоки `QuizGenerationService` оба заявляют, что AI-вопросы `is_draft=true` и требуют HR-ревью) полностью не реализован: (1) is_draft ставится в `true` `QuizGenerationService:70`; (2) **черновики ОТДАЮТСЯ:** `listByLesson` (51-57) грузит `questions.options` без фильтра → student-QuizResource их эмитит; `showForStudent` без фильтра; (3) **черновики СЧИТАЮТСЯ:** `computeScore` (158-210) итерирует каждый вопрос, без skip is_draft; (4) **нельзя ОЧИСТИТЬ:** `UpdateQuizQuestionRequest` (21-26) + `QuizQuestionService::update` (57-81) опускают is_draft; (5) **HR не ВИДИТ:** live-проба `GET /api/admin/onboarding/quizzes/1` вернула 3 вопроса с ключами `[id,text,kind,sort_order,points,explanation,options]` — поля is_draft нет. Grep по `where('is_draft')` / `addGlobalScope` в домене — 0 хитов. В живой БД сейчас 0 строк `is_draft=true`, поэтому харм латентен, но фильтры отсутствуют безусловно.

**Repro:** AI-сгенерировать вопросы для урока → они немедленно появляются в студенческом квизе и идут в score; у HR нет способа увидеть/одобрить.

**Предлагаемый фикс:** фильтр `is_draft=false` на студ-`listByLesson` + источнике `computeScore` (либо в relation `Quiz::questions()` для студ-пути); добавить `is_draft` в `UpdateQuizQuestionRequest` + `QuizQuestionService::update`; отдавать `is_draft` в `QuizQuestionAdminResource` для HR-ревью.

---

#### #4 · Студент видит неопубл./draft-уроки и неопубликованный курс — нет publish-gate на студ-путях
**Severity: 🟠 major · Тип: SECURITY · Проверка: 🌐 подтверждено в браузере (owner probe)**

**Файлы:**
- `src/app/Http/Resources/Onboarding/AssignmentDetailResource.php:43-56` (мапит каждый урок, вкл. is_published:false)
- `src/app/Http/Controllers/Onboarding/QuizController.php:95-108` (`showForStudent` — только resolveAssignment)
- `src/app/Http/Controllers/Onboarding/StudentCourseController.php:45-52` (eager-load `course.modules.lessons` без is_published-ограничения)
- `front/src/pages/CoursePage/components/CourseSidebar.vue:17-31` (v-for без publish-фильтра)
- `src/app/Domain/Onboarding/Policies/AssignmentPolicy.php:21-24` (`view` — только ownership, без publish)

**Что происходит (evidence):** Студ-пути проверяют только ownership, никогда `is_published`. Live как владелец (manager1@mgcrm.test): `GET /api/onboarding/assignments/1` вернул курс id=1 (`is_published=false` по БД) с уроками 2(video)/3(pdf)/4(quiz) все `is_published:false` рядом с опубликованным уроком 1. `GET /api/onboarding/lessons/4/quiz` (неопубл. квиз на неопубл. курсе) → **HTTP 200** с полным квизом. Все уроки рендерятся в `CourseSidebar` (v-for без фильтра) и открываемы. **Нюанс severity:** это publish-gate / wrong-content gap, НЕ cross-user breach — контент скоупится владением назначения (не-владелец получает 403 через `AssignmentPolicy::view`). Утечка — корректность контента, не конфиденциальность. Усугубляет #3 (draft-скоринг).

**Repro:** Открыть назначение, курс которого имеет draft-уроки (или сам неопубликован) → draft-уроки в сайдбаре и открываемы; неопубл. квиз даёт 200.

**Предлагаемый фикс:** фильтровать уроки по `is_published` в BE eager-load/resource (предпочтительно); 404/403 на неопубл. урок/курс в студ-GET; защитный FE-фильтр в CourseSidebar/useCoursePage.

---

#### #5 · Спиннер AI-генерации не завершается — FE поллит `quiz.ai_generation_status`, которого resource не отдаёт
**Severity: 🟠 major · Тип: DEAD-CODE · Проверка: ✅ подтверждено (live probe: ключа нет)**

**Файлы:**
- `front/src/pages/CourseBuilderPage/composables/useAiQuizGeneration.ts:46-47` (poll `getQuiz(quizId)` каждые 3с, резолв только при `ai_generation_status==='completed'/'failed'`)
- `front/src/entities/quiz.ts:14` (тип ждёт `ai_generation_status`)
- `src/app/Http/Resources/Onboarding/QuizAdminResource.php:18-33` (toArray НЕ включает `ai_generation_status`)
- `src/app/Domain/Onboarding/Services/LessonService.php:317-326` (`setAiGenerationStatus` пишет в `Lesson.content` JSONB, не на квиз; success-токен `'done'`)
- `front/src/pages/CourseBuilderPage/components/QuizBuilderDrawer.vue:376,389` (await triggerAiGenerate; draftQuestions не наполняются)

**Что происходит (evidence):** Live `GET /api/admin/onboarding/quizzes/1` (ровно то, что бьёт `getQuiz`) вернул ключи `id,lesson_id,title,description,pass_score_pct,time_limit_minutes,created_by_user_id,created_at,updated_at,questions[]` — **без `ai_generation_status`**. То есть `quiz.ai_generation_status` всегда undefined → `setInterval` крутится вечно, awaited-Promise в `triggerAiGenerate` (QuizBuilderDrawer:376) никогда не резолвится, `draftQuestions` не наполняются (389). **Двойной mismatch:** (а) BE пишет статус в `Lesson.content` (LessonService:317), а FE поллит ресурс КВИЗА; (б) BE пишет success-токен `'done'`, FE ждёт `'completed'` (useAiQuizGeneration:47) — расходятся и место, и значение. Job, вероятно, сохраняет черновики (vault smoke-test), но FE их не показывает. Фича не student-loop → major.

**Repro:** CourseBuilder → text/pdf-урок → открыть квиз → «Сгенерировать (AI)» → спиннер крутится бесконечно; черновики не появляются, хотя Job их сохраняет.

**Предлагаемый фикс:** отдавать `ai_generation_status` (и сохранённые черновики) в `QuizAdminResource`, ИЛИ поллить урок, где статус реально живёт, ИЛИ возвращать сохранённые черновики синхронно; согласовать FE poll-target и значение (`done` vs `completed`) с BE.

---

#### #6 · Quiz builder молча теряет правки опций у существующих вопросов
**Severity: 🟠 major · Тип: BUG · Проверка: ⚠️ частично (подтверждено в коде и в note вердикта b1; отдельной live-пробы не проводилось)**

**Файлы:**
- `front/src/pages/CourseBuilderPage/components/QuizBuilderDrawer.vue:458` (для существующего вопроса зовётся `patchQuestion` — который сам 404, см. #2)
- `front/src/pages/CourseBuilderPage/components/QuizBuilderDrawer.vue:464` (комментарий `// Sync options: not implemented in detail`)

**Что происходит (evidence):** В `submit()` для существующего вопроса (`lq.id`) код зовёт `patchQuestion` (который 404), затем — только комментарий «Sync options: not implemented in detail». `QuestionPatchPayload` не несёт опций, и ни один per-option create/patch/delete не отправляется. Добавление/удаление/переименование опций или флип `is_correct` у сохранённого вопроса не персистится. Новые вопросы ОТПРАВЛЯЮТ вложенные опции через `createQuestion`. Это подтверждено в коде и зафиксировано в note вердикта b1 («QuizBuilderDrawer.vue:464 явно комментирует 'Sync options: not implemented'»), но отдельной браузерной пробы конкретно по опциям не было — отсюда тег «частично».

**Repro:** Открыть сохранённый квиз: изменить текст опции или её correct-флаг → Save → переоткрыть → изменение пропало.

**Предлагаемый фикс:** реализовать option-diffing через исправленные option-endpoint'ы (после фикса #2), либо отправлять полный replace-payload «вопрос-с-опциями», который BE принимает.

---

### minor / trivial (компактно, тег «не верифицировано (Phase-1)»)

- **#7 (minor · BUG):** `deadline_days` рекламируется в AssignCourseDrawer (`:75`, `:149`), но `bulkAssign` (`AssignmentService:57`) его не применяет → назначения с `due_date=null`. Фикс: дефолтить пикер на `today+deadline_days` или применять в `bulkAssign` при null.
- **#8 (minor · BUG):** перегенерация сертификата (`CertificateController:103`, `CertificateService:48`) без completion-guard — админ выпускает нумерованный PDF (сжигая sequence-номер) для незавершённого назначения. Фикс: guard `status===Completed`, иначе 422.
- **#9 (minor · DEAD-CODE):** `CourseAssigned` диспатчится (`AssignmentService:68`), но в `AppServiceProvider:285` зарегистрирован только `CourseCompleted → GenerateCertificateListener` — уведомление о назначении не доставляется. Фикс: слушатель для `CourseAssigned` или удалить мёртвое событие.
- **#10 (minor · SECURITY):** `AiTutorController` ask/history без `authorize('view', lesson)` — 200 (пусто) вместо 403 на не-назначенном уроке (утечки нет, сессии per-user). Фикс: добавить authorize/resolveAssignment.
- **#11 (minor · STUB):** `CompletionPolicy::SoftGate` (`Enums/CompletionPolicy.php:14`), `course.deadline_days`, `AssignmentStatus::Failed` хранятся/возвращаются, но ничего не гейтят/не выставляются. Фикс: реализовать или явно задокументировать как informational-only.
- **#12 (minor · PERF):** N+1 расчёт прогресса — `MyCoursesResource:27` (calcProgress per row) и `ProgressService:35` (enrichRow COUNT+AVG per row). Фикс: batch-расчёт grouped-запросом, кеш счётчиков уроков на курс.
- **#13 (minor · DATA-INCONSISTENCY):** `AssignmentService::delete` (`:155`) гардит только наличие `LessonProgress`; `quiz_attempts.assignment_id` ON DELETE SET NULL → осиротевшие попытки. Плюс все таблицы активности пусты — флоу не завершался. Фикс: расширить guard на `quiz_attempts`; QA-засидить полностью опубликованный курс и прогнать полный journey.
- **#14 (minor · CONVENTION):** хардкод hex в HR-графиках — `HrStatusPieChart.vue:45,58`, `HrTopCoursesChart.vue:69,76` (палитра, текст, оси). Нарушает DS (обе темы); `lint:ds` не ловит инлайн-JS ECharts. Фикс: резолвить цвета из CSS custom properties / palette-helper с переключением по isDark.
- **#15 (minor · CONVENTION):** хардкод RU-строк в студ-композаблах — `useCoursePage.ts:111,123`, `useQuizAttempt.ts:52`, `useAiTutor.ts:85`, `useMyCertificatesPage.ts:29` (тосты/конфирмы мимо `t()`). Фикс: вынести в `en.json`/`ru.json` под `onboarding.*`.
- **#16 (trivial · CONVENTION):** `AssignCourseDrawer` тянет глобальный `/api/users` инлайн и назначает любому без role-скоупинга (можно назначить курс админу/системному). Фикс: скоупленный role-aware assignable-users endpoint/composable.

### Из live-QA (source = live-QA)

- **NEW-8 (minor · BUG · 🌐 подтверждено в браузере):** кнопка «Продолжить» на карточке курса в `/onboarding/my-courses` не навигирует — URL не меняется, пользователь должен знать `/onboarding/assignments/:id` вручную. Вероятная причина: `router.push` с неверным route-name или не передан `assignment_id`. Файл: `front/src/pages/MyCoursesPage/`. Учитывая #1 (пустой плеер), даже при исправлении навигации студент попадает на пустой урок.

## 7. Расхождения со спекой (vault) и предложения по актуализации

Документ: **`2. Модули/Onboarding — Курсы и обучение.md`**.

1. **Заголовочный статус `done-s3.1-s3.8-FULL` + строка S3.8 `DONE (PM APPROVE)`.**
   - Спека говорит: все под-шаги S3.1–S3.8 DONE/FULL; S3.5 live smoke-test подтверждает работу AI-черновиков с is_draft-ревью; S3.8 фронт-shell завершён.
   - Реальность: студенческий learning loop сломан end-to-end — контент урока не отдаётся студенту (пустой плеер, live-подтверждено), неопубл./draft-уроки и курс отдаются, FE-route правки квиза 404, AI-генерация не резолвится на FE, is_draft HR-ревью нерабочий. Все таблицы активности пусты.
   - Предложение: понизить статус до **«S3.1–S3.7 backend done; S3.8 frontend INCOMPLETE — student flow broken»**. Добавить раздел **«Known blockers (post-audit 2026-06-24)»** с перечислением: пустой контент урока, FE↔BE mismatch route правки вопроса, AI-draft review gate, publish gate, AI-gen poll-target.

2. **S3.5 — `#is_draft = true ... HR ревьюит и снимает флаг через PATCH (S3.2-эндпоинты)` (≈line 194).**
   - Спека: AI-вопросы `is_draft=true`, HR снимает флаг через PATCH (S3.2 endpoints) до выхода в прод.
   - Реальность: is_draft опущен в `UpdateQuizQuestionRequest` (нельзя очистить), в `QuizQuestionAdminResource` (HR не видит) и не фильтруется на студ/score-путях — черновики live и считаются мгновенно, review gate отсутствует.
   - Предложение: пометить HR-review gate как **НЕ реализован**; задачи: добавить is_draft в `UpdateQuizQuestionRequest`+`QuizService/QuizQuestionService::update`, отдать в admin-resource, фильтровать на студ `listByLesson`+`computeScore`.

3. **S3.3/S3.1 — `Course.deadline_days` + `CompletionPolicy soft_gate`.**
   - Спека: `deadline_days` и `completion_policy (soft_gate)` — first-class настройки курса, потребляемые назначениями/прогрессом.
   - Реальность: `deadline_days` не применяется к `due_date` (UI рекламирует дефолт, `bulkAssign` игнорирует); `CompletionPolicy::SoftGate` ничего не гейтит; `AssignmentStatus::Failed` никогда не выставляется.
   - Предложение: задокументировать `deadline_days`/`SoftGate`/`Failed` как **«stored but not yet enforced»** либо добавить задачи на реализацию.

4. **S3.8 — `AssignmentDetailResource — исправлен ключ id, добавлен user_id`.**
   - Спека: `AssignmentDetailResource` — студ-payload детали курса, поправлен в S3.8.
   - Реальность: всё ещё опускает `lesson.content` и `duration_minutes` и включает неопубл. уроки — крупнейший blocker. Выделенного студ-route для тела урока нет.
   - Предложение: задача — расширить `AssignmentDetailResource` (published-only уроки + content/duration) ЛИБО завести `GET /api/onboarding/lessons/{lesson}`.

**Для `5. Планы` (Master Roadmap):** статус M12/Онбординг в роадмапе следует синхронизировать с пунктом 1 выше — backend готов, frontend learning loop требует доработки до прод-готовности; AI-тьютор и cert-пайплайн помечены «не верифицированы вживую» (требуют живого AI-ключа + Gotenberg + полного journey).
