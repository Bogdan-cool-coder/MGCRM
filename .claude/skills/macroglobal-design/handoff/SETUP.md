# Подключение дизайн-системы к Claude Code (репозиторий MGCRM)

Цель: чтобы Claude Code и сабагенты **всегда** работали по дизайн-системе как по эталону,
а нарушения ловились линтом.

## 1. Положить дизайн-систему в репо как skill
Скопируй ВСЮ дизайн-систему (этот проект) в репозиторий MGCRM:

```
<repo>/.claude/skills/macroglobal-design/
   ├── README.md
   ├── styles.css
   ├── tokens/        (colors/typography/spacing/semantic/fonts .css)
   ├── components/
   ├── ui_kits/crm/
   ├── _adherence.oxlintrc.json
   └── SKILL.md
```

Так система едет вместе с кодом, и агенты читают её по локальному пути.

## 2. Память проекта — `CLAUDE.md`
Положи `CLAUDE.md` (из этой папки `handoff/`) в **корень репозитория**. Claude Code читает
его автоматически в каждой сессии — это и делает систему «главной».

## 3. Адаптация существующих агентов (designer / frontend-specialist / qa-tester)
В репо уже есть зрелые агенты в `.claude/agents/`. **Новых не заводим** — дополняем
существующие блоками из `handoff/agents-patches/` (additive, ничего не ломают):
- `designer.append.md` → в `.claude/agents/designer.md` (наша система = главный эталон, формат макета, обе темы, reuse-first);
- `frontend-specialist.append.md` → в `.claude/agents/frontend-specialist.md` (токены ⇄ SCSS/PrimeVue, reuse, обе темы, `lint:ds`, код EN);
- `qa-tester.append.md` → в `.claude/agents/qa-tester.md` (**обязательный визуальный проход** в light+dark — закрывает дыру «QA игнорит визуал»).

Каждый `*.append.md` указывает, в какую секцию агента вставлять. Применить может сама
main-сессия Claude Code («примени патчи из handoff/agents-patches к агентам») или вставь руками.

См. также `handoff/agents-patches/README.md` — там новая **иерархия эталонов** (визуал →
наша система; код/структура → Vizion+ARCHITECTURE; фичи → contracts) и схема воркфлоу.

## 4. Adherence-проверка (машинный контроль)
Важно понимать охват инструментов:

### 4a. Vue + SCSS (основной код репо) → stylelint
Сгенерированный `_adherence.oxlintrc.json` рассчитан на **JS/TS/JSX** и НЕ видит
`<style>` в `.vue` и `.scss`. Для реального стека MGCRM запрет хардкода в стилях даёт
**stylelint** + `stylelint-declaration-strict-value` (разрешает только `$…` / `var(--…)` /
ключевые слова для цвета, радиуса, теней, размеров шрифта):

```
npm i -D stylelint stylelint-config-standard-scss stylelint-declaration-strict-value
```
**.stylelintrc.json**
```jsonc
{
  "extends": "stylelint-config-standard-scss",
  "plugins": ["stylelint-declaration-strict-value"],
  "rules": {
    "scale-unit/declaration-strict-value": null,
    "declaration-strict-value": [
      ["/color$/", "background", "background-color", "border-color", "fill", "stroke",
       "box-shadow", "border-radius", "font-size", "font-family"],
      { "ignoreValues": ["transparent", "inherit", "currentColor", "none", "unset", "0"],
        "disableFix": true }
    ]
  },
  "ignoreFiles": ["**/theme/**"]  // файлы-определения токенов не проверяем
}
```
**package.json**
```jsonc
{
  "scripts": {
    "lint:ds": "stylelint \"front/src/**/*.{vue,scss}\""
  }
}
```

### 4b. Любой React/TSX-код на компонентах системы → oxlint
Если будете строить новые поверхности на React-компонентах системы, подключите
сгенерированный конфиг (он валидирует пропсы `<Button>/<Tag>/…`, ловит hex/px-литералы,
чужие шрифты). Исключите файлы-определения токенов, чтобы не было ложных срабатываний:
```jsonc
{ "scripts": {
  "lint:ds:react": "oxlint --config .claude/skills/macroglobal-design/_adherence.oxlintrc.json --ignore-pattern '**/theme/**' src"
} }
```

### 4c. Pre-commit + CI
**.husky/pre-commit**
```sh
#!/bin/sh
npm run lint:ds || { echo "❌ Нарушение дизайн-системы. Используй токены, не хардкод."; exit 1; }
```
```
npx husky init && echo 'npm run lint:ds' > .husky/pre-commit
```
**CI**
```yaml
- name: Design-system adherence
  run: npm run lint:ds
```

## 5. Поддерживать систему живой
Дизайн-система выведена из `front/src/theme/tokens/*`. Если меняете брендовые значения —
правьте их в репо И обновляйте дизайн-систему (пере-генерация в OpenMind-проекте), чтобы
эталон и код не разъезжались. README.md системы — место для решений и критики.

---
**TL;DR:** skill в `.claude/skills/`, `CLAUDE.md` в корень, агенты в `.claude/agents/`,
`npm run lint:ds` в pre-commit + CI.
