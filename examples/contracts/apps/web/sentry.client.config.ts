// Sentry — инициализация в браузере (client).
// ПОЛНЫЙ no-op, если NEXT_PUBLIC_SENTRY_DSN не задан: Sentry.init не вызывается,
// поэтому в dev и в текущем prod-билде (без DSN) поведение не меняется.
//
// Session Replay ВЫКЛЮЧЕН по умолчанию (чтобы не собирать PII с экранов).
import * as Sentry from "@sentry/nextjs";
import { scrubEvent } from "./sentry.scrub";

const DSN = process.env.NEXT_PUBLIC_SENTRY_DSN;

if (DSN) {
  Sentry.init({
    dsn: DSN,
    environment: process.env.NEXT_PUBLIC_SENTRY_ENV ?? "production",
    tracesSampleRate: 0.05,
    // НЕ собираем дефолтные PII (IP, заголовки, тело запроса по умолчанию).
    sendDefaultPii: false,
    // Session Replay отключён намеренно: нулевые сэмплы = не пишем сессии.
    replaysSessionSampleRate: 0,
    replaysOnErrorSampleRate: 0,
    // Финальная очистка каждого события от секретов/PII.
    beforeSend: (event) => scrubEvent(event),
  });
}

// Навигационные переходы app-router (нужно для трейсинга на клиенте в v10+).
export const onRouterTransitionStart = Sentry.captureRouterTransitionStart;
