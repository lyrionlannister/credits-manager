# syntax=docker/dockerfile:1
FROM docker.io/library/node:26-alpine AS client-builder
WORKDIR /app

ARG VITE_API_URL
ENV VITE_API_URL=$VITE_API_URL

ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME
ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

# Node 26 ya no trae corepack preinstalado.
RUN npm install -g corepack@latest && corepack enable && corepack prepare pnpm@11.13.0 --activate

COPY client/package.json client/pnpm-lock.yaml ./
RUN --mount=type=cache,target=/root/.local/share/pnpm/store \
    pnpm install --frozen-lockfile

COPY client/ .
RUN pnpm run build

FROM docker.io/library/nginx:alpine

RUN apk upgrade --no-cache

COPY nginx/nginx.conf /etc/nginx/conf.d/default.conf
COPY api/public /var/www/html/public
RUN ln -sf /var/www/html/storage/app/public /var/www/html/public/storage
COPY --from=client-builder /app/dist /var/www/html/app
