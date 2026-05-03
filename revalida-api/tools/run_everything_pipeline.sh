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

STUDY_GENERAL_DB="${QUESTIONS_STUDY_GENERAL_DB:-revalida_pre_reset_check}"
STUDY_GENERAL_LIMIT="${QUESTIONS_STUDY_GENERAL_LIMIT:-0}"
LIMIT_SOURCES="${QUESTIONS_LIMIT_SOURCES:-0}"
GENERATE_LIMIT="${QUESTIONS_GENERATE_LIMIT:-0}"
AI_ENABLED_FLAG="${AI_ENABLED:-true}"
ONLY_STUDY_GENERAL="${QUESTIONS_ONLY_STUDY_GENERAL:-0}"
AI_PROVIDER="${QUESTIONS_AI_PROVIDER:-${QUESTION_AI_PROVIDER:-}}"

CMD=(php artisan questions:sync-everything --study-general-db "${STUDY_GENERAL_DB}")

if [ "${STUDY_GENERAL_LIMIT}" -gt 0 ]; then
  CMD+=(--study-general-limit "${STUDY_GENERAL_LIMIT}")
fi

if [ "${LIMIT_SOURCES}" -gt 0 ]; then
  CMD+=(--limit-sources "${LIMIT_SOURCES}")
fi

if [ "${GENERATE_LIMIT}" -gt 0 ]; then
  CMD+=(--generate-limit "${GENERATE_LIMIT}")
fi

if [ -n "${AI_PROVIDER}" ]; then
  CMD+=(--ai-provider "${AI_PROVIDER}")
fi

case "${ONLY_STUDY_GENERAL}" in
  true|TRUE|1|on|ON|yes|YES)
    CMD+=(--only-study-general)
    ;;
esac

case "${AI_ENABLED_FLAG}" in
  false|FALSE|0|off|OFF|no|NO)
    CMD+=(--skip-ai)
    ;;
esac

"${CMD[@]}"
