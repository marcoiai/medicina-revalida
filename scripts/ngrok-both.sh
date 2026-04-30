#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${NGROK_ENV_FILE:-$ROOT_DIR/.env.ngrok}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE"
  echo "Copy .env.ngrok.example to .env.ngrok and fill it."
  exit 1
fi

set -a
source "$ENV_FILE"
set +a

: "${NGROK_AUTHTOKEN:?NGROK_AUTHTOKEN is required in $ENV_FILE}"

FRONTEND_PORT="${NGROK_FRONTEND_PORT:-5173}"
BACKEND_PORT="${NGROK_BACKEND_PORT:-8001}"
FRONTEND_URL="${NGROK_FRONTEND_URL:-}"
BACKEND_URL="${NGROK_BACKEND_URL:-}"

frontend_cmd=(ngrok http --authtoken "$NGROK_AUTHTOKEN")
backend_cmd=(ngrok http --authtoken "$NGROK_AUTHTOKEN")

if [[ -n "$FRONTEND_URL" ]]; then
  frontend_cmd+=(--url="$FRONTEND_URL")
fi

if [[ -n "$BACKEND_URL" ]]; then
  backend_cmd+=(--url="$BACKEND_URL")
fi

frontend_cmd+=("$FRONTEND_PORT")
backend_cmd+=("$BACKEND_PORT")

echo "Starting ngrok frontend tunnel on port $FRONTEND_PORT..."
"${frontend_cmd[@]}" > /tmp/ngrok-frontend.log 2>&1 &
frontend_pid=$!

echo "Starting ngrok backend tunnel on port $BACKEND_PORT..."
"${backend_cmd[@]}" > /tmp/ngrok-backend.log 2>&1 &
backend_pid=$!

cleanup() {
  kill "$frontend_pid" "$backend_pid" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

echo
echo "ngrok started."
echo "Frontend log: /tmp/ngrok-frontend.log"
echo "Backend  log: /tmp/ngrok-backend.log"
echo
echo "To inspect URLs:"
echo "  curl -sS http://127.0.0.1:4040/api/tunnels | jq -r '.tunnels[] | .name + \" -> \" + .public_url'"
echo
echo "Press Ctrl+C to stop both tunnels."

wait "$frontend_pid" "$backend_pid"
