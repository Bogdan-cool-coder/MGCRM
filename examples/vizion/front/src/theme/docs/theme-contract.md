# Theme Contract

- App-owned tokens live in `src/theme/tokens`.
- In component and page styles, the default public theme API is SCSS variables from `src/theme/scss`.
- Direct `var(--app-...)` usage in `.vue` styles should be treated as an exception, not the default.
- Direct `--p-*` usage in app code is not allowed.
- Repeated spacing and radius values should prefer shared SCSS tokens before introducing new raw literals.
- PrimeVue consumes app tokens through `src/theme/adapters/primevue`.
- `--app-*` variables are infrastructure-level theme plumbing that backs the SCSS API.
