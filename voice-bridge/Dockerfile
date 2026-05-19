FROM node:22-alpine

RUN apk add --no-cache ffmpeg \
 && npm install -g pnpm@11

WORKDIR /app
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile --prod

COPY src/ ./src/

CMD ["node", "src/index.mjs"]
