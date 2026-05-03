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

CMD=(php artisan questions:sync-all --skip-revalida --estudo-geral-db "${STUDY_GENERAL_DB}")

if [ "${STUDY_GENERAL_LIMIT}" -gt 0 ]; then
  CMD+=(--estudo-geral-limit "${STUDY_GENERAL_LIMIT}")
fi

"${CMD[@]}"
