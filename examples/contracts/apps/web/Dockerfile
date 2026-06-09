FROM node:20-alpine AS deps
WORKDIR /app
COPY package.json ./
RUN npm install --no-audit --no-fund

FROM node:20-alpine AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ENV NEXT_TELEMETRY_DISABLED=1

# --- Sentry (опционально) ---
# NEXT_PUBLIC_* инлайнятся в бандл на этапе сборки (публичны, не секрет).
# Если NEXT_PUBLIC_SENTRY_DSN пуст — Sentry полностью no-op (см. sentry.*.config.ts).
ARG NEXT_PUBLIC_SENTRY_DSN=""
ARG NEXT_PUBLIC_SENTRY_ENV="production"
ENV NEXT_PUBLIC_SENTRY_DSN=$NEXT_PUBLIC_SENTRY_DSN
ENV NEXT_PUBLIC_SENTRY_ENV=$NEXT_PUBLIC_SENTRY_ENV

# Загрузка source-map в Sentry выполняется ТОЛЬКО если заданы все три:
# SENTRY_AUTH_TOKEN + SENTRY_ORG + SENTRY_PROJECT. Иначе аплоад пропускается,
# сборка идёт как раньше (см. next.config.mjs). Токен — секрет, передаётся
# build-arg'ом из CI и не сохраняется в финальном образе (этот слой — builder).
ARG SENTRY_AUTH_TOKEN=""
ARG SENTRY_ORG=""
ARG SENTRY_PROJECT=""
ENV SENTRY_AUTH_TOKEN=$SENTRY_AUTH_TOKEN
ENV SENTRY_ORG=$SENTRY_ORG
ENV SENTRY_PROJECT=$SENTRY_PROJECT

RUN npm run build

FROM node:20-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
RUN addgroup --system --gid 1001 nodejs && adduser --system --uid 1001 nextjs

COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs
EXPOSE 3000
ENV PORT=3000 HOSTNAME=0.0.0.0
CMD ["node", "server.js"]
