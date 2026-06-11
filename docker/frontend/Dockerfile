# Frontend image — PLACEHOLDER.
# The Vue 3 + PrimeVue SPA is scaffolded in M0.5 (front/). Until then this
# Dockerfile only exists so `docker compose config` resolves the `frontend`
# service's build context. The `frontend` service is gated behind the
# `frontend` compose profile and is NOT started by `docker compose up`.
#
# Replace with the real multi-stage node build (npm ci → vite build → nginx
# static serve) when front/ lands.
FROM node:22-alpine AS frontend

WORKDIR /app

# front/ does not exist yet (M0.5). This is intentionally a no-op base image.
CMD ["sh", "-c", "echo 'frontend not yet scaffolded — see M0.5' && sleep infinity"]
