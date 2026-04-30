import asyncio
import json
import os
import random
import time
import argparse
from pathlib import Path

try:
    from google import genai
    from google.genai import types
except ImportError as exc:
    raise SystemExit(
        "Dependência do Gemini não instalada. Rode: pip install -r tools/requirements-ai.txt"
    ) from exc

ROOT = Path(__file__).resolve().parents[1]
BATCH_DIR = ROOT / "storage" / "imports" / "json" / "ai_batches"
OUTPUT_PATH = ROOT / "storage" / "imports" / "questions.json"
REJECTED_PATH = ROOT / "storage" / "imports" / "questions.rejected.json"

GEMINI_API_KEY = (
    os.getenv("GEMINI_API_KEY")
    or os.getenv("GOOGLE_API_KEY")
)
GEMINI_MODEL = os.getenv("GEMINI_MODEL", "gemini-2.5-flash")
MAX_CONCURRENT = int(os.getenv("MAX_CONCURRENT_BATCHES", "6"))
QUESTIONS_PER_BATCH = int(os.getenv("QUESTIONS_PER_BATCH", "25"))
MAX_RETRIES = int(os.getenv("GEMINI_RETRY_ATTEMPTS", "5"))
INITIAL_RETRY_DELAY_SECONDS = float(os.getenv("GEMINI_RETRY_INITIAL_DELAY_SECONDS", "2"))
MAX_RETRY_DELAY_SECONDS = float(os.getenv("GEMINI_RETRY_MAX_DELAY_SECONDS", "30"))

if not GEMINI_API_KEY:
    raise SystemExit("GEMINI_API_KEY (ou GOOGLE_API_KEY) não configurada.")

client = genai.Client(api_key=GEMINI_API_KEY)
lock = asyncio.Lock()
all_questions = []
rejected_batches = []


def extract_json(text: str):
    text = (text or "").strip()

    if "```json" in text:
        text = text.replace("```json", "").replace("```", "").strip()

    start = text.find("[")
    end = text.rfind("]")
    if start == -1 or end == -1 or end <= start:
        return []

    try:
        return json.loads(text[start:end + 1])
    except json.JSONDecodeError:
        return []


def build_prompt(raw_prompt: str) -> str:
    return f"""{raw_prompt}

IMPORTANTE:
- Responda somente com JSON válido.
- Quero {QUESTIONS_PER_BATCH} questões por lote.
- Não use markdown, comentários ou blocos de código.
- O array deve começar com [ e terminar com ].
"""


def is_retryable_generation_error(exc: Exception) -> bool:
    message = str(exc).strip().lower()
    retryable_markers = (
        "503",
        "unavailable",
        "high demand",
        "resource_exhausted",
        "429",
        "rate limit",
        "too many requests",
        "deadline exceeded",
        "timeout",
        "timed out",
        "connection reset",
    )
    return any(marker in message for marker in retryable_markers)


def compute_retry_delay(attempt: int) -> float:
    base_delay = INITIAL_RETRY_DELAY_SECONDS * (2 ** max(0, attempt - 1))
    jitter = random.uniform(0, max(0.25, INITIAL_RETRY_DELAY_SECONDS / 2))
    return min(MAX_RETRY_DELAY_SECONDS, base_delay + jitter)


async def generate_with_retry(final_prompt: str, file_path: Path, sem: asyncio.Semaphore):
    last_exc = None

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            async with sem:
                response = await asyncio.to_thread(
                    client.models.generate_content,
                    model=GEMINI_MODEL,
                    contents=final_prompt,
                    config=types.GenerateContentConfig(
                        temperature=0.6,
                        top_p=0.9,
                        top_k=40,
                        response_mime_type="application/json",
                    ),
                )
            return response, attempt
        except Exception as exc:
            last_exc = exc
            should_retry = attempt < MAX_RETRIES and is_retryable_generation_error(exc)
            if not should_retry:
                raise

            delay = compute_retry_delay(attempt)
            print(
                f"RETRY: {file_path.name} -> tentativa {attempt + 1}/{MAX_RETRIES} "
                f"em {delay:.1f}s ({exc})"
            )
            await asyncio.sleep(delay)

    if last_exc is not None:
        raise last_exc

    raise RuntimeError(f"Falha inesperada ao gerar conteúdo para {file_path.name}")


async def generate_from_file(file_path: Path, sem: asyncio.Semaphore):
    print(f"Processando: {file_path.name}")
    prompt = file_path.read_text(encoding="utf-8")
    final_prompt = build_prompt(prompt)

    try:
        response, attempts = await generate_with_retry(final_prompt, file_path, sem)

        text = getattr(response, "text", "") or ""
        data = extract_json(text)

        async with lock:
            if data:
                all_questions.extend(data)
            else:
                rejected_batches.append({
                    "batch": file_path.name,
                    "reason": "JSON inválido ou vazio",
                    "raw": text[:4000],
                })

        attempt_label = f" em {attempts} tentativa(s)" if attempts > 1 else ""
        print(f"OK: {file_path.name} -> {len(data)} questões{attempt_label}")
    except Exception as exc:
        async with lock:
            rejected_batches.append({
                "batch": file_path.name,
                "reason": str(exc),
                "raw": None,
            })
        print(f"ERRO: {file_path.name} -> {exc}")


async def main():
    parser = argparse.ArgumentParser(description="Gera questões em JSON a partir dos prompts do pipeline")
    parser.add_argument("--limit", type=int, default=0, help="Limita a quantidade de prompts processados")
    args = parser.parse_args()

    files = sorted(BATCH_DIR.glob("*.prompt.txt"))
    if not files:
        raise SystemExit(f"Nenhum prompt encontrado em {BATCH_DIR}")

    if args.limit and args.limit > 0:
        files = files[: args.limit]

    sem = asyncio.Semaphore(MAX_CONCURRENT)
    started = time.time()

    await asyncio.gather(*(generate_from_file(file_path, sem) for file_path in files))

    OUTPUT_PATH.write_text(
        json.dumps(all_questions, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    REJECTED_PATH.write_text(
        json.dumps(rejected_batches, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    elapsed = round(time.time() - started, 1)
    print()
    print(f"Total: {len(all_questions)}")
    print(f"Rejeitados: {len(rejected_batches)}")
    print(f"Tempo: {elapsed}s")
    print(f"Arquivo: {OUTPUT_PATH}")
    print(f"Rejeitados: {REJECTED_PATH}")


asyncio.run(main())
