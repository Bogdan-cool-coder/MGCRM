# /app

This template should help get you started developing with Vue 3 in Vite.

## Recommended IDE Setup

[VS Code](https://code.visualstudio.com/) + [Vue (Official)](https://marketplace.visualstudio.com/items?itemName=Vue.volar) (and disable Vetur).

## Recommended Browser Setup

- Chromium-based browsers (Chrome, Edge, Brave, etc.):
  - [Vue.js devtools](https://chromewebstore.google.com/detail/vuejs-devtools/nhdogjmejiglipccpnnnanhbledajbpd)
  - [Turn on Custom Object Formatter in Chrome DevTools](http://bit.ly/object-formatters)
- Firefox:
  - [Vue.js devtools](https://addons.mozilla.org/en-US/firefox/addon/vue-js-devtools/)
  - [Turn on Custom Object Formatter in Firefox DevTools](https://fxdx.dev/firefox-devtools-custom-object-formatters/)

## Type Support for `.vue` Imports in TS

TypeScript cannot handle type information for `.vue` imports by default, so we replace the `tsc` CLI with `vue-tsc` for type checking. In editors, we need [Volar](https://marketplace.visualstudio.com/items?itemName=Vue.volar) to make the TypeScript language service aware of `.vue` types.

## Customize configuration

See [Vite Configuration Reference](https://vite.dev/config/).

## Project Setup

```sh
npm install
```

### Compile and Hot-Reload for Development

```sh
npm run dev
```

### Type-Check, Compile and Minify for Production

```sh
npm run build
```

## Frontend Architecture

- `src/api/*`: low-level HTTP clients and DTO contracts.
- `src/services/*`: infrastructure services and data adapters used across the app.
- `src/application/*`: application services and use-case orchestration such as bootstrap and session actions.
- `src/coordination/*`: long-lived coordinators/managers for cross-cutting runtime flows.
- `src/storage/*`: local and session storage adapters.
- `src/stores/*`: persisted UI/application state plus simple state transitions. Stores should not own page-level CRUD orchestration.
- `src/pages/*/composables/*`: page wiring, local orchestration, and UI flow for a specific screen.
- `src/features/*`: reusable feature-level models and UI helpers.

## Naming Conventions

- `*Service`: infrastructure service or external adapter.
- `*Coordinator`: long-lived orchestrator for a cross-cutting flow.
- `*Storage`: local/session storage adapter.
- `use*Data`: loading and read-model composition.
- `use*Actions`: user-triggered commands for a page or feature.
- `*State`: pure state helpers without side effects.

## Import Rules

- Prefer `@/application`, `@/coordination`, and `@/storage` indexes over deep imports into those layers.
- Avoid reintroducing top-level `appServices`; use `application/*` and `coordination/*` instead.
- Keep direct `api/*` imports inside `api/` and infrastructure service layers unless a file only needs shared DTO types.
