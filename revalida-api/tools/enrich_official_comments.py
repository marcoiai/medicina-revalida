#!/usr/bin/env python3
import argparse
import asyncio
import json
import os
import random
import re
from pathlib import Path

from env_loader import load_dotenv_file

try:
    from google import genai
    from google.genai import types
except ImportError as exc:
    raise SystemExit(
        "Dependência do Gemini não instalada. Rode: pip install -r tools/requirements-ai.txt"
    ) from exc

ROOT = Path(__file__).resolve().parents[1]
INPUT_PATH = ROOT / "storage" / "imports" / "questions.json"

load_dotenv_file(ROOT)

GEMINI_API_KEY = os.getenv("GEMINI_API_KEY") or os.getenv("GOOGLE_API_KEY")
COMMENT_MODEL = os.getenv("QUESTION_COMMENT_MODEL", "gemini-2.5-flash-lite")
COMMENT_BATCH_SIZE = max(1, int(os.getenv("QUESTION_COMMENT_BATCH_SIZE", "4")))
MAX_CONCURRENT = max(1, int(os.getenv("QUESTION_COMMENT_MAX_CONCURRENT", "2")))
REQUEST_TIMEOUT_SECONDS = float(os.getenv("QUESTION_COMMENT_REQUEST_TIMEOUT_SECONDS", "120"))
MAX_RETRIES = max(1, int(os.getenv("QUESTION_COMMENT_RETRY_ATTEMPTS", "4")))
MAX_OUTPUT_TOKENS = max(256, int(os.getenv("QUESTION_COMMENT_MAX_OUTPUT_TOKENS", "1400")))

if not GEMINI_API_KEY:
    raise SystemExit("GEMINI_API_KEY (ou GOOGLE_API_KEY) não configurada para enriquecer comentários.")

client = genai.Client(api_key=GEMINI_API_KEY)

GENERIC_COMMENT_PREFIX = "gabarito definitivo oficial do inep:"


def normalize_space(text: str) -> str:
    return " ".join((text or "").split())


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


def needs_comment_enrichment(item: dict) -> bool:
    if not isinstance(item, dict):
        return False

    if str(item.get("origin", "")).strip() != "official_verbatim":
        return False

    if str(item.get("question_type", "multiple_choice")).strip() != "multiple_choice":
        return False

    comment = normalize_space(str(item.get("comentario", ""))).lower()
    return comment == "" or comment.startswith(GENERIC_COMMENT_PREFIX)


def build_payload(questions: list[dict]) -> list[dict]:
    payload = []
    for index, question in enumerate(questions):
        payload.append({
            "index": index,
            "enunciado": question.get("enunciado", ""),
            "alternativas": question.get("alternativas", {}),
            "gabarito": question.get("gabarito", ""),
            "reference": question.get("reference", ""),
        })
    return payload


def build_prompt(batch: list[dict]) -> str:
    data = json.dumps(build_payload(batch), ensure_ascii=False, indent=2)
    return f"""Você está complementando comentários de questões objetivas oficiais do Revalida.

TAREFA:
- Para cada questão, escreva um comentário curto e técnico em português do Brasil.
- Use apenas as informações do enunciado e das alternativas.
- Explique por que a alternativa correta é a melhor resposta.
- Explique brevemente por que as demais alternativas estão erradas, contraindicadas ou menos adequadas.
- Não invente exames, sintomas ou dados que não estejam na questão.
- Não use markdown, bullets nem títulos.
- Mantenha cada comentário entre 70 e 140 palavras, em um único parágrafo.
- Se não houver segurança suficiente para justificar a resposta sem inventar contexto, retorne "comentario": null para aquela questão.

FORMATO DE SAÍDA:
- Responda somente com JSON válido.
- Retorne um array de objetos no formato:
  [{{"index": 0, "comentario": "..."}}]

QUESTÕES:
{data}
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
    base_delay = 1.5 * (2 ** max(0, attempt - 1))
    jitter = random.uniform(0.2, 0.9)
    return min(20.0, base_delay + jitter)


async def generate_batch(batch: list[dict], batch_number: int, total_batches: int, sem: asyncio.Semaphore) -> list[dict]:
    prompt = build_prompt(batch)
    last_exc = None

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            async with sem:
                response = await asyncio.wait_for(
                    asyncio.to_thread(
                        client.models.generate_content,
                        model=COMMENT_MODEL,
                        contents=prompt,
                        config=types.GenerateContentConfig(
                            temperature=0.2,
                            top_p=0.8,
                            top_k=20,
                            max_output_tokens=MAX_OUTPUT_TOKENS,
                            response_mime_type="application/json",
                        ),
                    ),
                    timeout=REQUEST_TIMEOUT_SECONDS,
                )

            data = extract_json(getattr(response, "text", "") or "")
            if isinstance(data, list):
                print(f"OK comentário [{batch_number}/{total_batches}] -> {len(batch)} questão(ões)", flush=True)
                return data

            raise RuntimeError("Resposta da IA fora do formato JSON esperado.")
        except Exception as exc:
            last_exc = exc
            should_retry = attempt < MAX_RETRIES and is_retryable_generation_error(exc)
            if not should_retry:
                break

            delay = compute_retry_delay(attempt)
            print(
                f"RETRY comentário [{batch_number}/{total_batches}] -> tentativa {attempt + 1}/{MAX_RETRIES} em {delay:.1f}s ({exc})",
                flush=True,
            )
            await asyncio.sleep(delay)

    raise RuntimeError(f"Falha ao enriquecer comentário do lote {batch_number}: {last_exc}")


async def enrich_comments(questions: list[dict], limit: int) -> tuple[int, int]:
    eligible_indexes = [index for index, item in enumerate(questions) if needs_comment_enrichment(item)]
    if limit > 0:
        eligible_indexes = eligible_indexes[:limit]

    if not eligible_indexes:
        print("Nenhuma questão oficial elegível para enriquecimento de comentário.", flush=True)
        return 0, 0

    batches = [
        eligible_indexes[i:i + COMMENT_BATCH_SIZE]
        for i in range(0, len(eligible_indexes), COMMENT_BATCH_SIZE)
    ]
    sem = asyncio.Semaphore(MAX_CONCURRENT)
    updated = 0
    attempted = len(eligible_indexes)

    for batch_number, index_batch in enumerate(batches, start=1):
        batch = [questions[index] for index in index_batch]
        generated_items = await generate_batch(batch, batch_number, len(batches), sem)

        for generated in generated_items:
            if not isinstance(generated, dict):
                continue

            relative_index = generated.get("index")
            comment = generated.get("comentario")
            if not isinstance(relative_index, int):
                continue
            if relative_index < 0 or relative_index >= len(index_batch):
                continue
            if not isinstance(comment, str) or not normalize_space(comment):
                continue

            target_index = index_batch[relative_index]
            questions[target_index]["comentario"] = normalize_space(comment)
            updated += 1

    return attempted, updated


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Complementa comentários de questões oficiais usando IA apenas onde não há explicação oficial."
    )
    parser.add_argument("--input", default=str(INPUT_PATH), help="Arquivo JSON de entrada.")
    parser.add_argument("--output", default="", help="Arquivo JSON de saída. Se omitido, sobrescreve o de entrada.")
    parser.add_argument("--limit", type=int, default=0, help="Limita quantas questões elegíveis terão comentário enriquecido.")
    args = parser.parse_args()

    input_path = Path(args.input)
    output_path = Path(args.output) if args.output else input_path

    if not input_path.exists():
        raise SystemExit(f"Arquivo não encontrado: {input_path}")

    try:
        questions = json.loads(input_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise SystemExit("Arquivo de questões inválido.") from exc

    if not isinstance(questions, list):
        raise SystemExit("O arquivo de questões precisa ser um array JSON.")

    attempted, updated = asyncio.run(enrich_comments(questions, limit=args.limit))

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(questions, ensure_ascii=False, indent=2), encoding="utf-8")

    print(
        f"\nOK: comentários enriquecidos em {updated} de {attempted} questão(ões) elegíveis.",
        flush=True,
    )
    print(f"Arquivo salvo em: {output_path}", flush=True)


if __name__ == "__main__":
    main()
