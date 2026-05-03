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

CMD=(php artisan questions:scale-to-100k)

if [ "${QUESTIONS_SKIP_DISCOVERY:-0}" -eq 1 ]; then
  CMD+=(--skip-discovery)
fi

if [ -n "${QUESTIONS_SOURCES_FILE:-}" ]; then
  CMD+=(--sources-file "${QUESTIONS_SOURCES_FILE}")
fi

if [ -n "${QUESTIONS_DISCOVER_MAX_DEPTH:-}" ]; then
  CMD+=(--discover-max-depth "${QUESTIONS_DISCOVER_MAX_DEPTH}")
fi

if [ -n "${QUESTIONS_DISCOVER_MAX_PAGES:-}" ]; then
  CMD+=(--discover-max-pages "${QUESTIONS_DISCOVER_MAX_PAGES}")
fi

if [ -n "${QUESTIONS_CRAWL_LIMIT_SOURCES:-}" ]; then
  CMD+=(--crawl-limit-sources "${QUESTIONS_CRAWL_LIMIT_SOURCES}")
fi

if [ -n "${QUESTIONS_STUDY_GENERAL_DB:-}" ]; then
  CMD+=(--study-general-db "${QUESTIONS_STUDY_GENERAL_DB}")
fi

if [ -n "${QUESTIONS_STUDY_GENERAL_LIMIT:-}" ]; then
  CMD+=(--study-general-limit "${QUESTIONS_STUDY_GENERAL_LIMIT}")
fi

if [ -n "${QUESTIONS_AUTHORIAL_AMOUNT:-}" ]; then
  CMD+=(--authorial-amount "${QUESTIONS_AUTHORIAL_AMOUNT}")
fi

if [ -n "${QUESTIONS_AUTHORIAL_BATCH_SIZE:-}" ]; then
  CMD+=(--authorial-batch-size "${QUESTIONS_AUTHORIAL_BATCH_SIZE}")
fi

if [ -n "${QUESTIONS_AUTHORIAL_AREA:-}" ]; then
  CMD+=(--authorial-area "${QUESTIONS_AUTHORIAL_AREA}")
fi

if [ -n "${QUESTIONS_AUTHORIAL_RUN_ID:-}" ]; then
  CMD+=(--authorial-run-id "${QUESTIONS_AUTHORIAL_RUN_ID}")
fi

if [ -n "${QUESTIONS_AI_PROVIDER:-${QUESTION_AI_PROVIDER:-}}" ]; then
  CMD+=(--ai-provider "${QUESTIONS_AI_PROVIDER:-${QUESTION_AI_PROVIDER:-}}")
fi

case "${AI_ENABLED:-true}" in
  false|FALSE|0|off|OFF|no|NO)
    CMD+=(--skip-ai)
    ;;
esac

if [ "${QUESTIONS_WITH_AI:-0}" = "1" ]; then
  CMD+=(--with-ai)
fi

"${CMD[@]}"
