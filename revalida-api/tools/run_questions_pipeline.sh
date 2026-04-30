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

CMD=(python3 tools/run_web_pipeline.py --full --import-json)

if [ "${LIMIT_SOURCES}" -gt 0 ]; then
  CMD+=(--limit-sources "${LIMIT_SOURCES}")
fi

if [ "${GENERATE_LIMIT}" -gt 0 ]; then
  CMD+=(--generate-limit "${GENERATE_LIMIT}")
fi

"${CMD[@]}"
