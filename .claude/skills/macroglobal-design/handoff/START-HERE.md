# 🚀 START HERE — Handoff в Claude Code (MGCRM)

Это корневой файл пакета. Здесь: **куда что положить в репозитории**, **по каким путям**
Claude Code найдёт дизайн-систему, и **готовый промпт** для запуска работы.

---

## 1. Раскладка в репозитории

Распакуй содержимое этого архива в корень репозитория MGCRM так:

```
<repo>/
├── CLAUDE.md                                  ← из handoff/CLAUDE.md (память проекта, читается всегда)
│
├── .claude/
│   ├── skills/
│   │   └── macroglobal-design/                ← ВСЯ дизайн-система (эталон визуала)
│   │       ├── README.md                      (readme.md из пакета)
│   │       ├── SKILL.md
│   │       ├── styles.css                      ← корневой импорт токенов
│   │       ├── tokens/                          colors · typography · spacing · semantic · fonts (.css)
│   │       ├── components/                       forms · data · crm  (+ .d.ts / .prompt.md / .card.html)
│   │       ├── ui_kits/crm/                      интерактивный UI-kit (Sidebar/Shell/Deals/Contacts/Tasks)
│   │       ├── guidelines/                       специмены (цвета/типографика/спейсинг/бренд)
│   │       ├── assets/                           логотипы
│   │       └── _adherence.oxlintrc.json         конфиг линта для React/TSX-поверхностей
│   │
│   └── agents/                                ← дополнить патчами (НЕ заводить новых агентов)
│       ├── designer.md            ← + handoff/agents-patches/designer.append.md
│       ├── frontend-specialist.md ← + handoff/agents-patches/frontend-specialist.append.md
│       └── qa-tester.md           ← + handoff/agents-patches/qa-tester.append.md
│
└── design-handoff/                            ← макеты-эталоны + ТЗ (рабочие материалы)
    └── redesign/
        ├── contacts.html                       эталон раздела «Контакты»
        ├── entity-card.html                    эталон карточки контакта/компании
        ├── Contacts-spec.md                    ТЗ по «Контактам» (размеры/колонки/токены)
        ├── EntityCard-spec.md                  ТЗ по карточке сущности
        ├── tweaks-panel.jsx                    панель Tweaks (для превью макетов)
        └── styles.css                          копия токенов (чтобы макеты открывались автономно)
```

> Дизайн-система едет вместе с кодом как **skill** → агенты читают её по локальному пути
> `.claude/skills/macroglobal-design/`. `CLAUDE.md` в корне делает её «главной» в каждой сессии.

---

## 2. 📍 Пути, которые нужно знать Claude Code

Скажи Claude Code, что **источник правды по визуалу** лежит здесь:

| Что | Путь в репозитории |
|---|---|
| **Дизайн-система (токены/компоненты/UI-kit)** | `.claude/skills/macroglobal-design/` |
| Корневой CSS с токенами | `.claude/skills/macroglobal-design/styles.css` |
| Токены (значения) | `.claude/skills/macroglobal-design/tokens/*.css` |
| README системы (решения + критика) | `.claude/skills/macroglobal-design/README.md` |
| **Макет «Контакты»** (эталон) | `design-handoff/redesign/contacts.html` |
| **ТЗ «Контакты»** | `design-handoff/redesign/Contacts-spec.md` |
| **Макет карточки** (эталон) | `design-handoff/redesign/entity-card.html` |
| **ТЗ карточки** | `design-handoff/redesign/EntityCard-spec.md` |
| Целевой код раздела | `front/src/pages/ContactsPage/` + `useContactsView` |

Подробная настройка skill / линта / pre-commit — в `handoff/SETUP.md`.

---

## 3. ✅ Готовый промпт для Claude Code

Открой репозиторий в Claude Code и вставь (под нужный раздел):

> **Контекст:** дизайн-система — источник правды по визуалу, лежит в
> `.claude/skills/macroglobal-design/` (токены в `styles.css` + `tokens/*.css`). Эталоны
> макетов и ТЗ — в `design-handoff/redesign/`.
>
> **Задача:** реализуй раздел «Контакты» строго по `design-handoff/redesign/Contacts-spec.md`,
> визуальный эталон — `design-handoff/redesign/contacts.html`. Используй **только токены**
> дизайн-системы (никакого хардкода цветов/радиусов/размеров). Сверься с текущим кодом
> `front/src/pages/ContactsPage/` и `useContactsView`, переиспользуй существующие
> компоненты PrimeVue. Поддержи **обе темы** (светлая/тёмная). По завершении прогони
> `npm run lint:ds` и визуально проверь оба режима.

Для карточки сущности — то же, с `EntityCard-spec.md` / `entity-card.html` и
`front/src/pages/ContactPage/` · `front/src/pages/CompanyPage/` (+ `front/src/components/crm/entity/`).

---

## 4. Порядок применения (TL;DR)
1. Распакуй архив по раскладке из §1.
2. Убедись, что `CLAUDE.md` — в корне, а система — в `.claude/skills/macroglobal-design/`.
3. Примени патчи агентов из `handoff/agents-patches/` (или попроси об этом main-сессию Claude Code).
4. Подключи линт по `handoff/SETUP.md` (stylelint для `.vue/.scss`, oxlint для React).
5. Вставь промпт из §3 — и проверяй результат в обеих темах.
