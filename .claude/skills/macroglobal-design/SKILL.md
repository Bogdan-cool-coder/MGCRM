---
name: macroglobal-design
description: Use this skill to generate well-branded interfaces and assets for MACRO Global (and its CRM — Сделки/Контакты/Задачи), either for production or throwaway prototypes/mocks/etc. Contains essential design guidelines, colors, type, fonts, assets, and UI kit components for prototyping.
user-invocable: true
---

Read the README.md file within this skill, and explore the other available files.

If creating visual artifacts (slides, mocks, throwaway prototypes, etc), copy assets out and create static HTML files for the user to view. If working on production code, you can copy assets and read the rules here to become an expert in designing with this brand.

If the user invokes this skill without any other guidance, ask them what they want to build or design, ask some questions, and act as an expert designer who outputs HTML artifacts _or_ production code, depending on the need.

Quick facts:
- Brand primary is deep navy `#172747` (+ `#0E172B` dark, `#2B4987` light) with a warm-gray neutral scale `#F1F2F3`→`#272829`. No purple, no gradients, no emoji.
- Font: SF UI Display (brand) → **Inter** web fallback; Roboto for documents. CRM UI base size is 14px.
- Icons: PrimeIcons 7 (`pi pi-*`), CDN `https://unpkg.com/primeicons@7.0.0/primeicons.css`.
- Status pills are soft-tinted (Success `#A7EFAA`, Danger `#FF5A44`, Warning `#FFB38A`, Info `#8DD9FF`).
- Tokens live in `styles.css` → `tokens/*.css` as `--mg-*` custom properties. Components are in `components/`; the full CRM recreation is in `ui_kits/crm/`.
- The product is Russian-language; write UI copy in Russian (terse, sentence-case, polite «вы»). Money: `1 200 000 ₽` (symbol after, space separators).
