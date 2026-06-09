# --- Сборка статики (используется в prod) ---
FROM node:22-alpine AS build
WORKDIR /app
# Build-time feature flag for the Documents section. Default empty == ON (Vite
# treats unset / non-"false" as enabled). dev / prod CI passes
# `--build-arg VITE_FEATURE_DOCUMENTS=false` to hide the section while Gotenberg
# is not provisioned there. The local owner-stack rebuild omits the arg → ON.
ARG VITE_FEATURE_DOCUMENTS
ENV VITE_FEATURE_DOCUMENTS=${VITE_FEATURE_DOCUMENTS}
COPY front/package*.json ./
RUN npm ci
COPY front/ ./
RUN npm run build

# --- Dev: vite dev server ---
FROM node:22-alpine AS dev
WORKDIR /app
COPY front/package*.json ./
RUN npm install
COPY front/ ./
EXPOSE 5173
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]

# --- Prod: nginx раздаёт статику ---
FROM nginx:alpine AS production
COPY --from=build /app/dist /usr/share/nginx/html
COPY docker/nginx/frontend.conf /etc/nginx/conf.d/default.conf
EXPOSE 5173
