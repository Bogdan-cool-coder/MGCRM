# MACRO Global CRM — Design System

A design system reverse-engineered from the **MACRO Global** CRM — a Russian-language
B2B sales & onboarding platform built on **Laravel + Vue 3 + PrimeVue 4**. This system
captures the brand foundations (color, type, spacing, logo), reusable UI primitives, and
faithful recreations of the core product surfaces — **Сделки (Deals)**, **Контакты
(Contacts)** and **Задачи (Tasks)** — so design agents can build new MACRO-branded
interfaces and assets.

> **Language:** the product is Russian-first (RU/EN i18n). Copy specimens and UI kits are
> in Russian to match the real product.

---

## Sources used to build this system

| Source | What it provided |
| --- | --- |
| **Codebase** `MGCRM/` (Laravel API + `front/` Vue 3 SPA) | The source of truth. Theme tokens (`front/src/theme/tokens/*`), SCSS foundations, and the real Deals/Contacts/Tasks page components. |
| **GitHub** [`Bogdan-cool-coder/MGCRM`](https://github.com/Bogdan-cool-coder/MGCRM) | Same project on GitHub — browse it for deeper component logic, the PrimeVue preset adapters (`front/src/theme/adapters/primevue/`), and API/entity shapes when building higher-fidelity work. |
| **Brand book** `MACRO-Global-Brandbook.pdf` (24 pp.) | Authoritative color palette (pp. 14–15), typography (SF UI Display + Roboto), logo usage & clear-space rules, the web-prototyping type scale and 12-column grid (§04). |
| **Logo** `Logo-primary-Light.svg` / `macroglobal-logo-primary-light.svg` | Primary wordmark — "MACRO" + descriptor + vertical rule, navy `#172747`. |
| **QA screenshots** `MGCRM/qa_screenshots_s3/*` | Rendered reference of the real Deals board and Contacts table (used for layout fidelity only — code was the primary reference). |

The reader is encouraged to open the **GitHub repo** above to study the real components
(the PrimeVue Aura preset, kanban drag logic, saved views, bulk toolbars) when a task needs
more than this system's cosmetic recreations.

---

## Brand & product context

**MACRO Global** ("Global Technologies") ships software for the construction/development
sector in Kazakhstan & CIS. The brandbook references two product logos — **MacroWeb** and
**MacroSales** — built from the simplified MACRO mark + product name in the two-color brand
palette. The CRM in this repo is the internal sales + HR-onboarding platform: pipelines,
deals, contacts/companies, tasks/activities, documents, approval routes, and an onboarding
LMS (courses, assignments, HR progress).

The aesthetic is **dense, utilitarian, enterprise**: a permanent dark-navy sidebar, white
work surface, 14px base type, soft-tinted status pills, and a color-coded pipeline. It is
brand-correct and consistent — but visually conservative (see the assessment below).

---

## CONTENT FUNDAMENTALS — how MACRO writes

- **Language & register:** Russian, professional, neutral-formal. The implicit address is
  the polite **«вы»**; UI rarely speaks in first person. Microcopy is **terse and
  action-led** — verbs as button labels: *«Создать сделку», «Применить», «Сбросить»,
  «Поиск и фильтр», «Дедуп»*.
- **Casing:** Sentence case everywhere — *«Создать контакт»*, not Title Case. No ALL-CAPS
  except tiny uppercase section eyebrows in the sidebar (*«ОНБОРДИНГ»*) with wide
  letter-spacing.
- **Tone:** Direct and functional, never playful or salesy. The login tagline is the rare
  flourish: *«Управляйте продажами умнее и быстрее»*. Empty states are plain and helpful:
  *«Записи не найдены»*, *«Дубликаты не найдены»*.
- **Numbers & money:** Russian formatting — space thousands separators, currency symbol
  **after** the amount with a space: *«1 200 000 ₽»*, *«3 500 000 ₸»*, *«10 $»*. Large sums
  abbreviate: *«≈ 6,8 млн ₽»*, *«180 тыс. ₽»* (decimal comma). Dates are `ДД.ММ.ГГ`.
- **Domain vocabulary:** Сделка (deal), Воронка (pipeline), Этап (stage), Контакт/Компания,
  Физлицо/Юрлицо, Ответственный (owner), Задача/Активность, Согласование (approval),
  Онбординг. Pipeline health language: *«Нет задачи», «Просрочено», «N дней в работе»*.
- **Emoji:** **Never.** No emoji anywhere in product or brand. Icons carry all visual
  shorthand.

---

## VISUAL FOUNDATIONS

### Color
- **Brand primary is a single deep navy `#172747`** (Primary), with `#0E172B` (dark) and
  `#2B4987` (light). This navy is the spine of the system: sidebar, primary buttons, links,
  amounts, focus rings, deal-card header. There is **no purple, no blue→purple gradient** —
  the brand is resolutely navy + neutral gray.
- **Neutrals** are a 9-step warm-gray scale (`#F1F2F3` → `#272829`). Page background is
  Gray-100 `#F1F2F3`; cards are pure white `#FFFFFF`; text is Gray-900 / 700 / 600.
- **Status** colors are pastel solids from the brandbook — Success `#A7EFAA`, Danger
  `#FF5A44`, Warning `#FFB38A`, Info `#8DD9FF` — used as **soft-tinted pills** (light bg +
  dark text), almost never as saturated fills.
- **Pipeline stages** use a separate vivid palette (teal / blue / amber / pink / purple) for
  kanban column headers only — full-color header fill with white text (amber gets dark text
  for contrast).

### Type
- **SF UI Display** is the brand typeface (brandbook §03). It is **not webfont-licensed**, so
  the product — and this system — render in **Inter** (the documented fallback in
  `typography.ts`). **Roboto** is the secondary face for official documents.
  ⚠ *Substitution flagged: SF UI Display → Inter. Provide SF UI Display web files to close
  this gap.*
- **Two scales coexist.** The **CRM interface** is dense: **14px base**, page title 20px,
  section 24px, body/cell 14px, meta 12px. The **brand web guideline** (§04, for
  marketing/web) is larger: 16px body, h1 40 → h6 16, Display 1–4 (72/64/58/52). Use the
  14px scale for app UI; the web scale for landing/marketing.
- Weights: 400 / 500 / 600 / 700. Titles are 600 (semibold); 700 reserved for amounts,
  kanban column names, badges.

### Spacing, radius, elevation
- **4px spacing grid:** 4 / 8 / 12 / 16 / 20 / 24 / 28 / 32. 24px is the standard page gutter
  (Bootstrap heritage); 16px the standard card padding.
- **Radii:** 4px (input, tag, badge, cell), **6px (button, card, dropdown — the default
  workhorse)**, 8px (dialog, drawer, large card), 12px (avatar plate). Nothing is pill-round
  except avatars and count badges.
- **Elevation** is restrained and low-contrast: `sm` (1px), `card` `0 2px 8px /10%`, `md`,
  `lg`. Cards rest on a hairline border + the `card` shadow; hover lifts to `md`. No glows,
  no colored shadows.

### Surfaces, borders, layout
- Cards/panels: **white, 1px `#E3E4E6` border, 6–8px radius, soft shadow.** This is the
  universal container. No left-accent-border-only cards.
- The **sidebar is brand-invariant** — always navy `#172747`, never themed, white logo
  (achieved via `filter: brightness(0) invert(1)`), 240px wide (56px rail when collapsed).
  Active nav item = subtle white-8% pill + a 3px white left indicator bar.
- The **deal-card header is also brand-invariant navy** by design decree.
- Fixed chrome: 60px top bar, 56px page header, both white with a bottom hairline.

### Motion & states
- **Quiet motion.** Durations 0.2s (fast) / 0.3s / 0.5s, easing `ease-in-out`. Transitions
  are background/border/box-shadow fades — **no bounces, no springy scale, no decorative
  loops.** Skeletons pulse opacity 0.18↔0.32.
- **Hover:** surfaces go one gray step lighter/darker (cards → Gray-50, nav → white-5%);
  buttons darken one step (primary 900→800); text/outline buttons get a faint tinted bg.
- **Press/active:** primary button steps to 700; no shrink/scale.
- **Focus:** navy border + a 2px `--mg-primary-100` ring on inputs/selects.
- **Dark mode** exists in the product (PrimeVue colorScheme, inverted surface palette) but is
  secondary; this system is authored light-first.

### Imagery
- The product is **chrome + data, not imagery** — no hero photography, no illustration, no
  texture or pattern, no gradients. Visual interest comes from the navy/white contrast,
  color-coded pipeline headers, and status pills. Keep new work in that register.

---

## ICONOGRAPHY

- **Two icon stories, reconciled:** the **brandbook recommends Bootstrap Icons**
  (`icons.getbootstrap.com`), but the **shipped app uses [PrimeIcons](https://primeicons.org)
  7.0** (`pi pi-*`), bundled with PrimeVue. Both are open-source outline sets with a similar
  thin single-weight stroke, so they read as one family.
- **This system standardizes on PrimeIcons** for fidelity to the real UI — every card and UI
  kit loads it from CDN: `https://unpkg.com/primeicons@7.0.0/primeicons.css`, used as
  `<i className="pi pi-search" />`. Components take icons as a class string, e.g.
  `icon="pi-plus"`.
- **Style:** outline, ~18px in nav / 14px inline, single navy or muted-gray tone. Common
  glyphs: `pi-home pi-users pi-briefcase pi-check-square pi-building pi-box pi-phone
  pi-calendar pi-clock pi-plus pi-search pi-filter pi-th-large pi-list pi-ellipsis-h
  pi-bell pi-cog pi-verified`.
- **No emoji. No unicode-glyph icons.** SVGs are used only for the logo. See the
  *Iconography* card in the Design System tab for the working set.
- *If you need Bootstrap Icons for a strictly brandbook-compliant marketing piece, link
  `bootstrap-icons` from CDN instead — flag the swap.*

---

## Professional assessment — Deals, Contacts, Tasks (what reads dated in 2026)

> The user asked for a candid critique of where the current UI looks weak. This is an honest,
> brand-respecting read of the three core pages. None of it is applied to the UI kit (which
> faithfully recreates *today's* product) — it is the agenda for the next iteration.

**Cross-cutting**
1. **Sparse, low-density canvas.** Acres of empty Gray-100 on Deals/Contacts. A single-record
   Contacts table looks broken. Empty columns/states need real composition, not whitespace.
2. **The top bar is wasted.** 60px of white holding four right-aligned icons and no global
   search, breadcrumb, or context. A command bar / global search belongs here.
3. **Flat, undifferentiated hierarchy.** Page header, toolbar, and filter band are three
   near-identical white strips stacked with hairlines — the eye can't find the primary action.
4. **Pastel status pills are low-contrast** and small; severity is hard to scan at a glance.

**Сделки (Deals)**
- Kanban cards are functional but cramped; the amount is *smaller* than the title — the most
  important number should lead. Owner shows as a raw `@MG C. · 0 дней` string in the live build.
- Stage headers are loud full-bleed color blocks competing with the cards; a calmer header +
  a color accent would let cards breathe.
- No board-level KPIs (conversion, weighted forecast, stuck deals) despite the data existing.

**Контакты (Contacts)**
- A plain striped table with a frozen name column — serviceable but generic. No avatars,
  engagement signal, or last-activity context in the row. Filters as a separate band add a
  third stacked strip.
- Type pill + tags are the only color; rows feel like a spreadsheet, not a CRM.

**Задачи (Tasks)**
- Kanban-by-status is the right idea; execution is plain. Type tags, due urgency, and the
  linked deal need a clearer visual rhythm; overdue should be unmistakable.

**Modernization directions** (to explore together): a denser, more confident layout; a global
command bar in the top strip; larger amounts + calmer stage headers on the board; richer
Contacts rows (avatar + engagement + last touch); board KPI headers; bolder, higher-contrast
status treatment — all **within** the existing navy/white/14px brand, not a re-skin.

---

## Index — what's in this system

**Foundations** (root)
- `styles.css` — the entry point consumers link (import-only).
- `tokens/colors.css` · `typography.css` · `spacing.css` · `semantic.css` · `fonts.css` —
  all `--mg-*` custom properties + the Inter/Roboto webfont imports.
- `guidelines/*.html` — foundation specimen cards (Colors, Type, Spacing, Brand) shown in the
  Design System tab.
- `assets/` — `macroglobal-logo-primary-light.svg` (primary wordmark),
  `macroglobal-logo.svg`, `oldcrm-logo-primary.svg`.

**Components** (`components/`) — React primitives, `window.MACROGlobalCRMDesignSystem_2f42e6.*`
- `forms/` — **Button**, **Input**, **Select**, **Checkbox**
- `data/` — **Tag**, **Badge**, **Avatar**, **Card**
- `crm/` — **KanbanCard** (the pipeline deal card)
- Each has a `.d.ts` (props), `.prompt.md` (usage), and a `*.card.html` specimen.

**UI kit** (`ui_kits/crm/`) — faithful, interactive recreation of the product
- `index.html` — full app shell; click **Контакты / Сделки / Мои задачи** in the sidebar to
  switch surfaces; toggle Канбан/Список in the header.
- `Sidebar.jsx`, `Shell.jsx` (TopBar + PageHeader + ViewSwitch), `DealsView.jsx`,
  `ContactsView.jsx`, `TasksView.jsx`.

**Other**
- `SKILL.md` — Agent-Skill manifest for using this system in Claude Code.
- `_ref/` — scratch reference (brandbook PDF, screenshots); not part of the shipped system.

---

## Using this system

Consumers link one file: `styles.css`. Then reference components via the global namespace
`window.MACROGlobalCRMDesignSystem_2f42e6` after loading `_ds_bundle.js` (auto-generated).
Tokens are plain CSS custom properties — `var(--mg-primary-900)`, `var(--mg-space-4)`,
`var(--mg-radius-md)` — usable anywhere. Load PrimeIcons from CDN for glyphs.
