#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ADMIN_DIR="$SCRIPT_DIR/chatbot-admin"

if ! command -v npm >/dev/null 2>&1; then
  echo "npm was not found. Install Node.js, then run this script again."
  exit 1
fi

if [ ! -f "$ADMIN_DIR/node_modules/next/package.json" ]; then
  echo "Installing chatbot admin dependencies..."
  (cd "$ADMIN_DIR" && npm install)
fi

echo "Starting Chatbot Admin at http://localhost:3010/local-admin"
(cd "$ADMIN_DIR" && npm run dev) &
SERVER_PID=$!

cleanup() {
  kill "$SERVER_PID" 2>/dev/null || true
}
trap cleanup INT TERM EXIT

sleep 3
if command -v open >/dev/null 2>&1; then
  open "http://localhost:3010/local-admin"
elif command -v xdg-open >/dev/null 2>&1; then
  xdg-open "http://localhost:3010/local-admin" >/dev/null 2>&1 || true
else
  echo "Open http://localhost:3010/local-admin in your browser."
fi

wait "$SERVER_PID"
