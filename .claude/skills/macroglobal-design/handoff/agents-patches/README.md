# Патчи агентов под воркфлоу MACRO Global CRM

> **STATUS: APPLIED 2026-06-22.** Все три `.append.md` уже вмержены в живые файлы
> `.claude/agents/designer.md`, `.claude/agents/frontend-specialist.md`,
> `.claude/agents/qa-tester.md`. **Повторно применять НЕ нужно** — эти файлы оставлены
> как исторический референс источника патча.

Эти блоки **дополняли** существующие агенты в `.claude/agents/`, не заменяя их
(additive). Каждый файл указывает, **куда** вставлялся блок.

| Файл | В какой агент | Что добавляет |
| --- | --- | --- |
| `designer.append.md` | `.claude/agents/designer.md` | Наша дизайн-система = главный эталон; формат выхода (готовый макет + поведение); обе темы; reuse-first. |
| `frontend-specialist.append.md` | `.claude/agents/frontend-specialist.md` | Токены системы ⇄ SCSS/PrimeVue репо; строгий reuse; обе темы; `lint:ds`; код EN / пояснения RU. |
| `qa-tester.append.md` | `.claude/agents/qa-tester.md` | **Обязательный визуальный проход** против дизайн-системы в light+dark — закрывает дыру «QA игнорит визуал». |

## Иерархия эталонов (важно — она поменялась)
1. **Визуал / бренд / токены / компоненты** → `.claude/skills/macroglobal-design/` — **единственный источник истины** (наша дизайн-система). Перебивает vault-спеку `MG CRM 2026` и «визуальный» Vizion.
2. **Структура кода / паттерны / архитектура** → Vizion (`./examples/vizion/`) + `ARCHITECTURE.md` — без изменений.
3. **Состав фич / поведение** → `./examples/contracts/` — без изменений.

> Конфликт «как выглядит» → дизайн-система. Конфликт «как устроен код» → Vizion/ARCHITECTURE.

## Воркфлоу (как договорились)
`юзер (текст+скрин / рисуем на канвасе)` → апрув макета → `designer` пишет ТЗ →
`frontend-specialist` реализует → `qa-tester` (функц. **И** визуальный проход, light+dark) →
FAIL → назад к фронту; PASS → `product-manager` → пуш по правилам репо.
