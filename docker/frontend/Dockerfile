# syntax=docker/dockerfile:1
# =============================================================================
# Frontend — multi-stage Dockerfile (Vue 3 + Vite → nginx:alpine SPA)
#
# Pattern: direct copy from Vizion examples/vizion/docker/frontend.Dockerfile
# with MGCRM-specific naming.
#
# Stages:
#   build      — node:22-alpine, npm ci + vite build → /app/dist
#   production — nginx:alpine, copies dist, serves SPA + proxies /api
#
# Sentry source-map upload:
#   Non-secret build vars (DSN, org, project, release) come in via ARG.
#   SENTRY_AUTH_TOKEN is a BuildKit secret (never written to any image layer).
#   The secret is mounted only during `npm run build` and discarded after.
# =============================================================================

# --- Stage 1: build ---
FROM node:22-alpine AS build

WORKDIR /app

COPY front/package*.json ./
RUN npm ci

COPY front/ ./

# Non-secret Sentry build-time vars (safe to embed in build args).
# VITE_SENTRY_DSN ends up in the public JS bundle intentionally.
ARG VITE_SENTRY_DSN=""
ARG VITE_SENTRY_RELEASE=""
ARG SENTRY_ORG=""
ARG SENTRY_PROJECT=""
# Optional: set to "true" to emit sourcemaps (default off in local builds).
ARG VITE_SOURCEMAP="false"

# Laravel Echo / Reverb connection vars — baked into the JS bundle at build time.
# All four are non-secret (public app key + connection coords).
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT="8080"
ARG VITE_REVERB_SCHEME="https"

ENV VITE_SENTRY_DSN=${VITE_SENTRY_DSN}
ENV VITE_SENTRY_RELEASE=${VITE_SENTRY_RELEASE}
ENV VITE_SOURCEMAP=${VITE_SOURCEMAP}
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV VITE_REVERB_HOST=${VITE_REVERB_HOST}
ENV VITE_REVERB_PORT=${VITE_REVERB_PORT}
ENV VITE_REVERB_SCHEME=${VITE_REVERB_SCHEME}

# SENTRY_AUTH_TOKEN is injected ONLY via BuildKit secret-mount.
# It is NEVER declared as ARG or ENV — it never appears in any image layer.
# The token is available to the build process through /run/secrets/sentry_auth_token
# and is read by @sentry/vite-plugin during the build, then discarded.
RUN --mount=type=secret,id=sentry_auth_token,env=SENTRY_AUTH_TOKEN \
    SENTRY_ORG="${SENTRY_ORG}" \
    SENTRY_PROJECT="${SENTRY_PROJECT}" \
    npm run build

# --- Stage 2: serve SPA ---
FROM nginx:alpine AS production

# SPA + /api proxy config (history-mode fallback + asset caching)
COPY docker/nginx/frontend.conf /etc/nginx/conf.d/default.conf

# Copy Vite build output
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
