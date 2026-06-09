/**
 * Build-time feature flags (Vite-inlined `import.meta.env.VITE_*`).
 *
 * These are resolved once at module load — they are NOT reactive and cannot
 * change at runtime (Vite replaces `import.meta.env.VITE_*` with a string
 * literal during `npm run build`). Read them through the named getters below,
 * never `import.meta.env.VITE_*` directly, so the default-ON semantics stay in
 * one place.
 *
 * ── Documents section ──────────────────────────────────────────────────────
 * `VITE_FEATURE_DOCUMENTS` gates the entire "Documents" surface (the library
 * page, the document editor, the nav item, the AI document-generation modal and
 * its action-marker CTA). Default = ON: the section is hidden ONLY when the
 * variable is the exact string `'false'`. An unset / empty / any-other value
 * means ON, so local development and existing builds keep Documents working
 * even when the variable is never defined.
 *
 * dev / prod images are built with `VITE_FEATURE_DOCUMENTS=false` (passed as a
 * Docker build-arg, see `docker/frontend.Dockerfile` + the CI build step) while
 * Gotenberg is not yet provisioned there. The local owner stack
 * (`vizion.lazarewww.ru`) is rebuilt without the build-arg, so Documents stays
 * ON there.
 */

const isExplicitlyDisabled = (value: string | undefined): boolean => value === 'false'

/**
 * Whether the Documents section is enabled in this build. ON by default —
 * `false` only when `VITE_FEATURE_DOCUMENTS` is explicitly `'false'`.
 */
export const DOCUMENTS_FEATURE_ENABLED = !isExplicitlyDisabled(
  import.meta.env.VITE_FEATURE_DOCUMENTS,
)
