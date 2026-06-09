/// <reference types="vite/client" />

interface ImportMetaEnv {
  /**
   * Build-time toggle for the "Documents" section (HTML КП + docx templates).
   *
   * Vite inlines `import.meta.env.VITE_*` at build time. The flag defaults to
   * ON: the section is only hidden when the value is explicitly `'false'`.
   * Leaving the variable unset (local dev, ad-hoc builds) keeps Documents
   * visible. dev / prod images bake `VITE_FEATURE_DOCUMENTS=false` via the
   * Dockerfile build-arg.
   *
   * Read exclusively through `@/shared/featureFlags` — do not consume
   * `import.meta.env.VITE_FEATURE_DOCUMENTS` directly elsewhere.
   */
  readonly VITE_FEATURE_DOCUMENTS?: string

  /** Enables the MSW mock layer in dev (`main.ts`). */
  readonly VITE_MOCK_API?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
