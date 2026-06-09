// Next.js client instrumentation (v10): этот файл подхватывается автоматически
// и инициализирует Sentry в браузере. Логика — в sentry.client.config.ts
// (no-op без NEXT_PUBLIC_SENTRY_DSN).
import { onRouterTransitionStart } from "./sentry.client.config";

// Реэкспорт для трейсинга навигаций app-router.
export { onRouterTransitionStart };
