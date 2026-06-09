// Next.js instrumentation hook — точка входа Sentry для server/edge рантаймов.
// Конфиги внутри сами проверяют NEXT_PUBLIC_SENTRY_DSN, поэтому без DSN это no-op.
export async function register() {
  if (process.env.NEXT_RUNTIME === "nodejs") {
    await import("./sentry.server.config");
  }
  if (process.env.NEXT_RUNTIME === "edge") {
    await import("./sentry.edge.config");
  }
}

// Перехват ошибок в server components / route handlers (Sentry v8+/v10).
// Реэкспортируем хук; без DSN Sentry.init не отработал — вызов безопасен (no-op).
export { captureRequestError as onRequestError } from "@sentry/nextjs";
