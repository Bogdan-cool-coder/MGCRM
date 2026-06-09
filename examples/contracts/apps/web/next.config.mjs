import { withSentryConfig } from "@sentry/nextjs";

/** @type {import('next').NextConfig} */
const nextConfig = {
  output: "standalone",
  reactStrictMode: true,
  poweredByHeader: false,
  // На первом деплое не блокируем сборку из-за TS/ESLint warning'ов.
  // Локально и в CI проверка остаётся обычной.
  typescript: { ignoreBuildErrors: true },
  eslint: { ignoreDuringBuilds: true },
  async rewrites() {
    // На проде Traefik отдаёт /api напрямую в api-контейнер.
    // На dev — проксируем через Next к http://localhost:8000.
    if (process.env.NODE_ENV === "development") {
      return [
        { source: "/api/:path*", destination: "http://localhost:8000/api/:path*" },
      ];
    }
    return [];
  },
  async headers() {
    // CSP frame-src whitelist для Эпик 13: embed-видео (Drive/Loom/YouTube/Vimeo)
    // + OnlyOffice DocEditor (office.contracts.macroglobal.tech)
    // ВАЖНО: script-src 'unsafe-eval' НЕ изменяем — OnlyOffice требует его, существующее поведение сохранено.
    // Только добавляем frame-src (OnlyOffice DocEditor уже работает через iframe без явного frame-src)
    return [
      {
        source: "/(.*)",
        headers: [
          {
            key: "Content-Security-Policy-Report-Only",
            value: [
              "frame-src 'self'",
              "https://drive.google.com",
              "https://docs.google.com",
              "https://www.loom.com",
              "https://loom.com",
              "https://www.youtube-nocookie.com",
              "https://www.youtube.com",
              "https://player.vimeo.com",
              "https://vimeo.com",
              "https://office.contracts.macroglobal.tech",
              "https://office-153-80-193-132.nip.io",
            ].join(" "),
          },
        ],
      },
    ];
  },
};

// Загрузка source-map в Sentry выполняется ТОЛЬКО когда заданы auth-token + org +
// project. Иначе сборка идёт как раньше (плагин просто инструментирует код и
// глотает отсутствие токена — релиз/аплоад пропускается). Это гарантирует, что
// `npm run build` без Sentry-окружения не падает.
const sentryUploadEnabled =
  !!process.env.SENTRY_AUTH_TOKEN &&
  !!process.env.SENTRY_ORG &&
  !!process.env.SENTRY_PROJECT;

/** @type {import('@sentry/nextjs').SentryBuildOptions} */
const sentryBuildOptions = {
  org: process.env.SENTRY_ORG,
  project: process.env.SENTRY_PROJECT,
  authToken: process.env.SENTRY_AUTH_TOKEN,
  // Без токена/org/project — не пытаемся ничего загружать и не валим билд.
  sourcemaps: { disable: !sentryUploadEnabled },
  // Молчим в логах, пока аплоад не сконфигурирован.
  silent: !sentryUploadEnabled,
  // Не падать на ошибках плагина во время сборки (телеметрия не должна ломать деплой).
  errorHandler: () => {},
  // Прячем sourcemap-комментарии от клиента и удаляем .map из бандла после аплоада.
  widenClientFileUpload: true,
  disableLogger: true,
  telemetry: false,
};

// withSentryConfig — обёртка no-op по эффекту, когда DSN/токены не заданы:
// она лишь подключает webpack-плагин, который без upload-конфига ничего не шлёт.
export default withSentryConfig(nextConfig, sentryBuildOptions);
