#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

INTERVAL_SECONDS="${QUESTIONS_SYNC_INTERVAL_SECONDS:-86400}"

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

while true; do
  started_at="$(date '+%Y-%m-%d %H:%M:%S')"
  echo "[$started_at] Iniciando pipeline de questões..."

  if bash tools/run_questions_pipeline.sh; then
    finished_at="$(date '+%Y-%m-%d %H:%M:%S')"
    echo "[$finished_at] Pipeline concluído."
  else
    failed_at="$(date '+%Y-%m-%d %H:%M:%S')"
    echo "[$failed_at] Pipeline finalizado com erro."
  fi

  echo "Aguardando ${INTERVAL_SECONDS}s para a próxima execução..."
  sleep "${INTERVAL_SECONDS}"
done
