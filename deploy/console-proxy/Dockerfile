# Console WebSocket proxy (docs/adr/0002-console-websocket-proxy.md).
FROM node:25-alpine

WORKDIR /app
ENV NODE_ENV=production

COPY package.json package-lock.json ./
RUN npm ci --omit=dev && npm cache clean --force

COPY src ./src

USER node
EXPOSE 8090
HEALTHCHECK --interval=30s --timeout=3s CMD wget -qO- http://127.0.0.1:8090/healthz || exit 1

CMD ["node", "src/server.js"]
