#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ -f ".venv/bin/activate" ]; then
  # shellcheck disable=SC1091
  source ".venv/bin/activate"
fi

if [ -f ".env" ]; then
  set -a
  # shellcheck disable=SC1091
  source ".env"
  set +a
fi

LIMIT_SOURCES="${QUESTIONS_LIMIT_SOURCES:-0}"
GENERATE_LIMIT="${QUESTIONS_GENERATE_LIMIT:-0}"
AI_ENABLED_FLAG="${AI_ENABLED:-true}"

CMD=(php artisan questions:sync-study-general-web)

if [ "${LIMIT_SOURCES}" -gt 0 ]; then
  CMD+=(--limit-sources "${LIMIT_SOURCES}")
fi

if [ "${GENERATE_LIMIT}" -gt 0 ]; then
  CMD+=(--generate-limit "${GENERATE_LIMIT}")
fi

case "${AI_ENABLED_FLAG}" in
  false|FALSE|0|off|OFF|no|NO)
    CMD+=(--skip-ai)
    ;;
esac

"${CMD[@]}"
