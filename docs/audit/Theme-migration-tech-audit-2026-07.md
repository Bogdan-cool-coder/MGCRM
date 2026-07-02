# Theme-migration tech audit — NAVY dark theme (2026-07)

**Scope:** technical audit of migrating the MGCRM frontend theme to the new design
package (`/Users/bogdanadykin/Downloads/exports/mgcrm-package/`), whose headline change
is a **NAVY dark theme** (`tokens/dark.css`). Read-only — no code changed here.

**Verdict up front:** the migration is a **re-valuing exercise, not a re-architecture**.
Our theme already has the exact seam the package needs (semantic dark tokens driven from
one preset). ~**96%** of dark styling re-colors for free from central token changes.
The real hand-work is small and concentrated: one preset file, one ECharts theme file,
~13 files with hardcoded chip tints, and the sidebar/deal-header brand tokens that must
now *adapt* to dark instead of staying frozen navy. **Estimate: M (≈2–3 focused days)**,
dominated by manual QA of both themes, not by code volume.

---

## 0. TL;DR numbers

| Metric | Value |
|---|---|
| `.app-dark` blocks total (parsed) | **803** across **157** files |
| — token-only blocks (re-color for free) | **769 (≈96%)** |
| — blocks with a hardcoded hex (need review) | **34 (≈4%)**, in **20 files** |
| `--mg-*` token usages in repo | **19** (all our own `--mg-surface-card/-hover` helper) |
| `--c-*` page-bridge usages in repo | **0** |
| `color-mix()` chip tints vs OLD dark card `#444547` | **8 lines**, 2 files (TaskCard, MyTasksTable) |
| Files hardcoding `#172747` (brand navy) | **10** |
| ECharts-consuming components | **3** (single theme file drives all) |
| Total `.vue` in tree | 329 |

---

## 1. Current theme architecture (the seam)

Our theme is a **TS `definePreset` + SCSS-var bridge**, NOT the package's `--mg-*` CSS-var
system. Two independent systems that happen to encode the same design. The chain:

```
theme/tokens/colors.ts           ← hex source of truth (primaryPalette, surfacePalette, …)
  → theme/config.ts (appTheme)   ← assembles palette/semantic/… object
  → theme/adapters/primevue/     ← definePreset(Aura, {primitive, semantic, components})
       primitive/colors.ts         emits :root --p-* primitives
       semantic/foundation.ts      ⭐ colorScheme.light / colorScheme.dark  ← DARK LIVES HERE
       preset.ts                   per-component dark overrides (button/datatable/tabs/…)
  → theme/css/appVariables.ts    ← emits --app-* vars, most ALIASED to var(--p-*)
  → theme/scss/foundation/_colors.scss  ← $scss-vars = var(--app-*)  ← what .vue files use
```

**How dark is set today:** `main.ts` mounts PrimeVue with
`{ prefix:'p', darkModeSelector:'.app-dark', cssLayer:true }`; `stores/theme.ts` toggles
`.app-dark` on `<html>`. Dark is realized by **inverting the Aura surface scale** in
`foundation.ts → colorScheme.dark.surface` (`0:surfacePalette[950]` … `950:surfacePalette[0]`),
i.e. `--p-surface-50` becomes a *dark* grey and `--p-surface-900` becomes *near-white*.
Everything else (`--app-*`, `$scss-vars`) aliases to `--p-surface-*`, so components that read
tokens flip automatically. The inverted scale is the single biggest gotcha of the current
system (see §4.1) and is documented at length inside `foundation.ts`.

**Key implication:** the package ships a **navy `--mg-*` / `--c-*` model our code does not
consume** (`--c-*`: 0 usages; `--mg-*`: only our own helper). So we are **not** adopting the
package's token names — we translate its *values* into our preset. The `--mg-*`/`--c-*` files
remain the spec + what the HTML mockups render, per `START-HERE.md`/`README-DARK-THEME.md`.

---

## 2. Dark-override inventory & classification

Parsed all `.app-dark` selector blocks (brace-balanced) across `*.vue` + `*.scss`:

### (a) Token-reading blocks — re-color for FREE — **769 (96%)**
These read `var(--p-*)`, `var(--app-*)` or `$scss-vars`. Once `colorScheme.dark` + the
`--app-*` semantic aliases carry navy values, these need **zero edits**. This is why the
migration is cheap.

### (b) Hardcoded / semi-hardcoded hex blocks — **34 blocks in 20 files** — need review
Top offenders (count of hex-bearing `.app-dark` blocks):

| # | File | Note |
|---|---|---|
| 5 | `pages/MyTasksPage/components/TaskCard.vue` | `color-mix()` task-kind chip tints vs `#444547` |
| 4 | `pages/MyTasksPage/components/MyTasksTable.vue` | same chip tints |
| 3 | `pages/SettingsPage/components/sections/SectionAppearance.vue` | theme-preview swatches (some legit) |
| 3 | `pages/DealsPage/components/DealsListView.vue` | stage chips / row tints |
| 2 | `pages/SettingsPage/components/sections/SectionLanguage.vue` | |
| 2 | `pages/DealsPage/components/DealsKanbanColumn.vue` | column header tints |
| 2 | `pages/CompanyPage/index.vue` | |
| 1 each | `NotificationsButton`, `EntityKpiStrip`, `InfoPanel`, `SidebarNotifications`, `DealInfoTabs`, `SectionChannels`, `SectionProfile`, `AvatarCropModal`, `DealsKanbanCard`, `DealsFilterOverlay`, `DealsToolbar`, `_colors.scss`, `base.scss` | |

**Most dangerous subset — `color-mix()` against the OLD dark card `#444547`** (8 lines,
`TaskCard.vue` + `MyTasksTable.vue`), e.g.
`background: color-mix(in srgb, #2A6FDB 18%, #444547)`. New dark card is **`#111E38`**, so
every one of these tints must be rebased or (better) re-expressed against a token. These are
the clearest "will look wrong until touched" spots.

### (c) Inverted-scale background usages — **59 lines** — silent-risk, verify each
Lines that set a background to `surface-800/900/0` (which are *light* in our inverted dark
scale, used deliberately today as dark backgrounds). Examples: `CommandPalette.vue`,
`TaskExpandedPanel.vue` (`--p-surface-0`), `AvatarCropModal.vue` (`$surface-900`),
`base.scss` datatable body. When navy re-mapping lands these must still resolve to a *dark*
navy — they will if the inverted scale is preserved, but each is a spot to eyeball because the
"which end is dark" intuition changes with the palette.

---

## 3. Mapping plan — where each new value goes

The package's `dark.css` is a flat `--mg-*` override sheet. Translate its values into our
**two** dark surfaces (preset + SCSS). No new token *names* are introduced in code.

### 3.1 Surfaces → `foundation.ts colorScheme.dark.surface` (+ light stays)
The current inverted grey scale is replaced with a **navy scale**. Suggested mapping so
existing `surface-*` consumers keep working:

| `--p-surface-*` (dark) | today (grey) | → NAVY target | package source |
|---|---|---|---|
| 0 | `#000000` | `#0A1426` (app bg / deepest) | `--mg-navy-bg` |
| 50 | `#272829` | `#0F1F3D` | `--mg-gray-50` (dark) |
| 100 | `#444547` (card) | **`#111E38`** (card/panel) | `--mg-navy-surface` |
| 200 | `#616263` (border) | `#172847` / border `#27395C` | `--mg-navy-surface2` |
| 300 | `#7E7F82` | `#3A4F78` (strong border) | `--mg-navy-border-strong` |
| … | … | mid navies | interpolate |
| 800 | `#F1F2F3` (light text) | `#C6D0E2` | `--mg-gray-800` (dark) |
| 900 | `#F9FAFB` (text) | `#EAF0FA` (primary text) | `--mg-text-primary` |

Every place in `foundation.ts` that references `{surface.100}` / `{surface.200}` / `{surface.900}`
for card / border / overlay / modal / formField text (there are many, all commented) then
resolves to navy automatically — **this is the biggest single win**.

### 3.2 Brand accent in dark — the headline inversion
Today: `foundation.ts colorScheme.dark.primary.color = {primary.400}` (`#6f87bc`), and the
**filled** button is force-pinned to brand navy `#172747` in `preset.ts` (both light+dark).
Package wants dark accent to **lighten to `#4C7DF0`** (hover `#6E99FF`, active `#3D6AD8`).
Action:
- Add a small navy-accent ramp to `colors.ts` (or reuse `primary.400`-band) so
  `colorScheme.dark.primary.color = #4C7DF0`, `hover = #6E99FF`, `active = #3D6AD8`.
- **Decision needed (designer):** the current rule pins *filled buttons* to navy in dark
  (`preset.ts button.colorScheme.dark.root.primary.background = {primary.900}`). Package says
  accent must lighten so it doesn't vanish on navy. On a `#0A1426` bg a `#172747` filled button
  nearly disappears → we should switch dark filled primary to `#4C7DF0`. This is a deliberate
  behavior change, not a bug — flag to `designer` before flipping.

### 3.3 Status triads → `semantic.ts` + `appVariables.ts` dark values
Today status tints come from static light palettes (`greenPalette[100]` etc.) and don't dark-adapt.
Package supplies dark triads (soft same-hue tint on navy, light ink): success text `#8FD3A0` on
`rgba(123,196,140,.16)`, danger `#F4A293`, warning `#E6B98C`, info `#94C2EC`. Our status vars are
emitted once in `:root` (light only). To dark-adapt we must **add `.app-dark` overrides for
`--app-status-*`** in `appVariables.ts` (or a dedicated dark block) — this is *new* work the
current theme doesn't do (status pills today just keep light tint in dark). Extended funnel
statuses (`reserve/mdeal/done`) get the same treatment.

### 3.4 Sidebar & deal-header — from frozen to dark-adaptive
Currently **brand-invariant** and hardcoded: `_colors.scss` `$sidebar-bg:#172747`,
`$brand-header-bg:#172747` (consumed by `AppSidebar.vue`, `EntityInfoHeader.vue`,
`DealInfoHeader.vue`). Package changes the contract:
- Sidebar **darkens to `#091020`** in dark (stays navy), active bar → `#6E99FF`.
- Deal-header bg → `#111E38` in dark.

So these can no longer be single static SCSS constants — they need a light value **and** an
`.app-dark` override. Small change but touches the two most brand-sensitive surfaces, so QA
carefully. (10 files hardcode `#172747` directly — audit each: most are legit brand chrome,
a few may be incidental.)

### 3.5 Shadows → `theme/tokens/shadows.ts` (+ dark override)
Package wants deeper dark shadows (`0 4px 14px rgba(3,8,20,.55)` etc.). Today shadows are one
static set. Add `.app-dark` shadow values — low-risk, cosmetic.

### 3.6 ECharts → `plugins/echarts.ts` (single file)
Well isolated: `buildMacroCrmTheme(isDark)` with explicit `*_DARK` constants, re-registered
reactively by `useMacroCrmEchartsTheme.ts`. Update `TEXT_PRIMARY_DARK`, `TEXT_MUTED_DARK`,
`AXIS_LINE_DARK`, `SPLIT_LINE_DARK`, `TOOLTIP_BG_DARK`, and the pie/line `borderColor` dark
value (`#2C2C2C` → navy card `#111E38`) to navy. The series color palette
(`MACRO_ECHARTS_PALETTE`) can stay brand — optionally brighten for navy contrast (designer call).
Only **3** components consume it, so one file covers all charts.

---

## 4. Risk zones

### 4.1 Inverted surface scale (highest conceptual risk)
The whole dark theme relies on `colorScheme.dark.surface` being the *inverse* of light. Two
options for navy:
- **Keep inversion, swap to navy hexes** (recommended): least churn — all `{surface.100}`
  references in `foundation.ts`/`preset.ts` keep meaning "card bg", now navy. 59 `surface-800/900/0`
  bg-usages keep working. Risk: navy isn't a clean monotonic invert of light grey, so the
  "middle" steps (300–700) need hand-picked navies, and a few components that lean on a specific
  grey step may look slightly off.
- **Rewrite to a direct navy scale** (not inverted): cleaner mental model but **breaks the 59
  inverted-scale usages** and every `{surface.X}` comment in the preset → much larger blast
  radius. **Not recommended for this pass.**

### 4.2 `color-mix()` chip tints vs `#444547` (TaskCard/MyTasksTable, 8 lines)
Will render wrong until rebased to `#111E38` or, better, to `var(--p-surface-100)` /
`--mg-surface-card` so they never hardcode the card bg again. Small but visible.

### 4.3 EntityAvatar `#fff` inverse
`components/crm/entity/EntityAvatar.vue` uses white initials + rgba ring as an *allowed brand
invariant* (comment L67). White-on-color avatars stay correct on navy — **no change needed**,
but include in the visual QA pass.

### 4.4 Brand hardcodes (`#172747`) in 10 files
Mostly legit sidebar/deal-header/merge-dialog chrome. Two now need dark variants (§3.4); the
rest audit to confirm they're brand chrome and not accidental.

### 4.5 `lint:ds` interplay
The DS lint bans raw hex/px. Rebasing chip tints to tokens will *help* the lint; but any new
`.app-dark` status/shadow overrides must use tokens/`var()` (or stylelint-disable where a raw
navy is unavoidable, matching the existing `--mg-surface-hover:#3a3b3d` disable in `_colors.scss`).
Run `npm run lint:ds` after each zone.

### 4.6 `.app-dark &` compile gotcha (from memory, still live)
Any *new* scoped dark overrides must use `.app-dark &` (NOT `:global(.app-dark) &`, which
mis-compiles to a bare global) and separate BEM modifier classes for active states. Applies if
we add per-component dark rules for chip tints.

---

## 5. Cost estimate by zone (S/M/L)

| Zone | Effort | Why |
|---|---|---|
| Central preset: `colorScheme.dark.surface` navy remap | **M** | one file, but needs hand-picked navy ramp + regression across all overlay/card/formField comments |
| Dark brand accent (`primary.color` + filled-button decision) | **S** | few lines; needs a designer decision (§3.2) |
| Status triads dark-adapt (`appVariables.ts`/`semantic.ts`) | **M** | *new* dark behavior the theme doesn't do today; 4 core + 3 extended triads |
| Sidebar + deal-header dark variants | **S** | ~4 tokens, but brand-critical → careful QA |
| Shadows dark | **S** | cosmetic |
| ECharts navy | **S** | single file, 3 consumers |
| `color-mix()` chip tints rebase (TaskCard/MyTasksTable) | **S** | 8 lines, ideally token-ize |
| Sweep 34 hardcoded-hex blocks in 20 files | **M** | mostly grep-and-verify; a handful are real edits |
| Verify 59 `surface-800/900/0` bg usages | **M** | no edits if inversion kept, but each must be eyeballed in dark |
| **Full visual QA, both themes, every redesigned screen** | **L** | the real cost — computed-styles check per `qa-tester` gate |

**Screens that re-color essentially FREE** (token-only, no hardcode): the vast majority —
contacts list, entity/company/contact cards, most DealPage groups, settings shells, dashboard
widgets (via ECharts file), forms/inputs/overlays (driven by preset). **Screens needing hand
work:** MyTasks (TaskCard/MyTasksTable chip tints), Deals board/list (kanban column + stage
chips), Settings appearance preview, sidebar/deal-header chrome.

---

## 6. Recommended execution order

1. **`colors.ts` / `foundation.ts`** — add navy dark surface ramp + navy accent (biggest win,
   unblocks everything). Keep the inversion approach (§4.1 option 1).
2. **`preset.ts`** — re-point component dark overrides / confirm filled-button decision (§3.2).
3. **`appVariables.ts`** — add `.app-dark` overrides for status triads + shadows (new behavior).
4. **`_colors.scss`** — sidebar/deal-header dark variants (§3.4).
5. **`plugins/echarts.ts`** — navy dark constants.
6. **Sweep** the 34 hardcoded-hex blocks; token-ize the 8 `color-mix()` chip tints first.
7. **`npm run type-check` + `npm run lint:ds`**, then full **both-theme visual QA**.

> Designer decisions to confirm before coding: (a) dark filled-primary navy vs `#4C7DF0`
> (§3.2); (b) whether to brighten the ECharts series palette for navy contrast (§3.6);
> (c) exact navy values for surface steps 300–700 (interpolation targets, §3.1).
