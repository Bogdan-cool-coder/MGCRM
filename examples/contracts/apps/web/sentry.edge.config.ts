// Sentry — инициализация в edge-рантайме (middleware / edge route handlers).
// ПОЛНЫЙ no-op без NEXT_PUBLIC_SENTRY_DSN.
import * as Sentry from "@sentry/nextjs";
import { scrubEvent } from "./sentry.scrub";

const DSN = process.env.NEXT_PUBLIC_SENTRY_DSN;

if (DSN) {
  Sentry.init({
    dsn: DSN,
    environment: process.env.NEXT_PUBLIC_SENTRY_ENV ?? "production",
    tracesSampleRate: 0.05,
    sendDefaultPii: false,
    beforeSend: (event) => scrubEvent(event),
  });
}
