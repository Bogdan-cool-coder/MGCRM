# =============================================================================
# Frontend — multi-stage Dockerfile (Vue 3 + Vite → nginx:alpine SPA)
#
# Pattern: direct copy from Vizion examples/vizion/docker/frontend.Dockerfile
# with MGCRM-specific naming.
#
# Stages:
#   build      — node:22-alpine, npm ci + vite build → /app/dist
#   production — nginx:alpine, copies dist, serves SPA + proxies /api
# =============================================================================

# --- Stage 1: build ---
FROM node:22-alpine AS build

WORKDIR /app

COPY front/package*.json ./
RUN npm ci

COPY front/ ./
RUN npm run build

# --- Stage 2: serve SPA ---
FROM nginx:alpine AS production

# SPA + /api proxy config (history-mode fallback + asset caching)
COPY docker/nginx/frontend.conf /etc/nginx/conf.d/default.conf

# Copy Vite build output
COPY --from=build /app/dist /usr/share/nginx/html

EXPOSE 80
