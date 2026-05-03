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
EXAM_SCOPE="${QUESTIONS_EXAM_SCOPE:-Revalida}"
PHASE_SCOPE="${QUESTIONS_PHASE_SCOPE:-first}"
IMPORT_CATEGORY="${QUESTIONS_IMPORT_CATEGORY:-revalida}"
AI_ENABLED_FLAG="${AI_ENABLED:-true}"
AI_PROVIDER="${QUESTIONS_AI_PROVIDER:-${QUESTION_AI_PROVIDER:-}}"

CMD=(python3 tools/run_web_pipeline.py --full --import-json --exam "${EXAM_SCOPE}" --phase "${PHASE_SCOPE}" --category "${IMPORT_CATEGORY}")

if [ "${LIMIT_SOURCES}" -gt 0 ]; then
  CMD+=(--limit-sources "${LIMIT_SOURCES}")
fi

if [ "${GENERATE_LIMIT}" -gt 0 ]; then
  CMD+=(--generate-limit "${GENERATE_LIMIT}")
fi

if [ -n "${AI_PROVIDER}" ]; then
  CMD+=(--ai-provider "${AI_PROVIDER}")
fi

case "${AI_ENABLED_FLAG}" in
  false|FALSE|0|off|OFF|no|NO)
    CMD+=(--skip-ai)
    ;;
esac

"${CMD[@]}"
