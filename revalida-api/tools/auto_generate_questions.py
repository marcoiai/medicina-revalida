import asyncio
import json
import os
import random
import re
import time
import argparse
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
BATCH_DIR = ROOT / "storage" / "imports" / "json" / "ai_batches"
OUTPUT_PATH = ROOT / "storage" / "imports" / "questions.json"
REJECTED_PATH = ROOT / "storage" / "imports" / "questions.rejected.json"
TEXT_MANIFEST_PATH = ROOT / "storage" / "imports" / "text_manifest.json"

load_dotenv_file(ROOT)

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
REQUEST_TIMEOUT_SECONDS = float(os.getenv("GEMINI_REQUEST_TIMEOUT_SECONDS", "240"))

if not GEMINI_API_KEY:
    raise SystemExit("GEMINI_API_KEY (ou GOOGLE_API_KEY) não configurada.")

client = genai.Client(api_key=GEMINI_API_KEY)
lock = asyncio.Lock()
all_questions = []
rejected_batches = []
completed_batches = 0

QUALITY_APPENDIX = """
REGRAS DE QUALIDADE OBRIGATÓRIAS:
- Cada questão deve ter apenas 1 alternativa correta, sem ambiguidade.
- As alternativas erradas devem ser claramente erradas ou menos adequadas.
- Não misture conceitos conflitantes nem crie pegadinhas semânticas.
- Se houver chance razoável de duas alternativas parecerem corretas, descarte a questão e gere outra.
- As alternativas podem parecer próximas na primeira leitura, mas só 1 deve permanecer tecnicamente defensável após aplicar o dado-chave do caso.

DIFICULDADE:
- Fácil: reconhecimento direto de conceito clássico.
- Média: interpretação clínica ou aplicação objetiva de regra em 1 ou 2 passos.
- Difícil: integração de múltiplos dados ou comparação fina.
- Não rotule como "Média" questão baseada em exceção obscura, detalhe controverso ou mera pegadinha.
- Prefira maioria de questões em nível Médio ou Difícil. Questões fáceis devem ser minoria.

ALTERNATIVAS:
- Escreva 5 alternativas em frases completas.
- Mantenha tamanho e especificidade relativamente equilibrados entre A-E.
- Evite opções curtas, telegráficas ou obviamente descartáveis.
- Sempre que possível, escreva alternativas mais informativas, com o núcleo decisório completo.
- Faça as 5 alternativas no mesmo formato lógico: todas diagnóstico, ou todas conduta, ou todas classificação.
- Use distratores plausíveis, com diferenças finas de prioridade, timing, indicação, contraindicação, gravidade ou fluxo assistencial.
- Use uma informação-pivô no enunciado para sustentar a resposta correta.
- Não crie "alternativas irmãs": duas opções do mesmo tronco diagnóstico ou da mesma base de conduta, separadas apenas por nuance escondida.
- Se a pergunta for de diagnóstico, as alternativas devem competir em diagnóstico.
- Se a pergunta for de conduta, as alternativas devem competir em conduta.
- Evite repetir a mesma doença-base ou a mesma linha de manejo em duas opções com pequenas variações de subtipo, dose, sequência ou tratamento.
- Evite rótulos secos quando for possível escrever uma opção mais completa.
- Prefira alternativas com 1 frase curta ou 2 frases curtas, e não respostas com apenas 1 a 3 palavras.
- Só use respostas muito curtas quando a questão exigir nomenclatura padronizada.
- A alternativa correta não pode ser muito mais longa ou muito mais específica que as demais.
- Evite duas alternativas quase idênticas.
- Não use "todas as anteriores", "nenhuma das anteriores" ou combinações do tipo "I e III".

COMENTÁRIO:
- Explique por que a correta está correta.
- Explique brevemente por que cada uma das outras 4 está errada ou é menos adequada.
- O comentário não pode apenas repetir o enunciado.

TEMAS DE VACINAS, CONTRAINDICAÇÕES E PRECAUÇÕES:
- Diferencie contraindicação absoluta, precaução, adiamento temporário e avaliação individualizada.
- Não transforme precaução em contraindicação.
- Não misture DTP, DTPa e dTpa sem explicitar.

CHECAGEM INTERNA ANTES DE RESPONDER:
1. Há apenas 1 alternativa correta?
2. Alguma errada ficou parcialmente verdadeira ou defensável?
3. O nível de dificuldade está coerente?
4. As alternativas estão equilibradas em tamanho e especificidade?
5. O comentário refuta explicitamente as outras 4?
Se qualquer resposta for "não", regenere a questão antes de responder.
"""

ALT_STOPWORDS = {
    "a", "ao", "aos", "as", "com", "como", "da", "das", "de", "do", "dos", "e",
    "em", "entre", "na", "nas", "no", "nos", "o", "os", "ou", "para", "por",
    "se", "sem", "um", "uma",
}


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
- Respeite a quantidade pedida no prompt-base. Se ele não fixar uma quantidade, gere {QUESTIONS_PER_BATCH} questões.
- Não use markdown, comentários ou blocos de código.
- O array deve começar com [ e terminar com ].

{QUALITY_APPENDIX}
"""


def normalize_space(text: str) -> str:
    return " ".join((text or "").split())


def normalize_key(text: str) -> str:
    return normalize_space(text).lower()


def alternative_content_tokens(text: str) -> list[str]:
    tokens = re.findall(r"\b[\wº°/-]+\b", normalize_key(text))
    return [token for token in tokens if token not in ALT_STOPWORDS]


def leading_token_overlap(text_a: str, text_b: str) -> int:
    tokens_a = alternative_content_tokens(text_a)
    tokens_b = alternative_content_tokens(text_b)
    overlap = 0

    for token_a, token_b in zip(tokens_a, tokens_b):
        if token_a != token_b:
            break
        overlap += 1

    return overlap


def batch_base_name(file_path: Path) -> str:
    return file_path.name.split("_batch_", 1)[0]


def load_text_manifest_index() -> dict[str, dict]:
    if not TEXT_MANIFEST_PATH.exists():
        return {}

    try:
        manifest = json.loads(TEXT_MANIFEST_PATH.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}

    index: dict[str, dict] = {}
    for item in manifest:
        text_path = item.get("text_path")
        if not text_path:
            continue
        index[Path(text_path).stem] = item

    return index


def detect_revalida_phase(source_meta: dict | None) -> str:
    if not source_meta:
        return "unknown"

    label = normalize_key(" ".join([
        str(source_meta.get("filename", "")),
        str(source_meta.get("pdf_url", "")),
        str(source_meta.get("link_text", "")),
        str(source_meta.get("local_path", "")),
        str(source_meta.get("text_path", "")),
    ]))

    if "objetiva" in label or "discursiva" in label:
        return "first"

    if (
        "habilidades_clinicas" in label
        or "habilidades clinicas" in label
        or "padrao esperado de procedimentos" in label
        or ("pep" in label and "discursiva" not in label)
    ):
        return "second"

    return "unknown"


def matches_scope(source_meta: dict | None, exam_scope: str, phase_scope: str) -> bool:
    if not source_meta:
        return False

    exam_scope = normalize_key(exam_scope or "revalida")
    phase_scope = normalize_key(phase_scope or "first")

    if exam_scope != "all":
        exam_label = normalize_key(" ".join([
            str(source_meta.get("source_name", "")),
            str(source_meta.get("institution", "")),
            str(source_meta.get("exam", "")),
        ]))
        if exam_scope not in exam_label:
            return False

    if phase_scope == "all":
        return True

    if "revalida" not in normalize_key(str(source_meta.get("exam", ""))):
        return True

    return detect_revalida_phase(source_meta) == phase_scope


def enrich_questions(data: list[dict], source_meta: dict | None) -> list[dict]:
    if not source_meta:
        return data

    phase = detect_revalida_phase(source_meta)
    source_payload = {
        "name": source_meta.get("source_name"),
        "institution": source_meta.get("institution"),
        "exam": source_meta.get("exam"),
        "year": source_meta.get("year"),
        "url": source_meta.get("pdf_url") or source_meta.get("page_url"),
        "file_path": source_meta.get("local_path"),
    }

    phase_tag = None
    if phase == "first":
        phase_tag = "primeira_fase"
    elif phase == "second":
        phase_tag = "segunda_fase"

    for item in data:
        if not isinstance(item, dict):
            continue

        item["question_source"] = source_payload

        tags = item.get("tags")
        if not isinstance(tags, list):
            tags = []

        normalized_tags = {normalize_key(str(tag)) for tag in tags if str(tag).strip()}
        final_tags = [str(tag).strip() for tag in tags if str(tag).strip()]

        for extra_tag in filter(None, [normalize_key(str(source_meta.get("exam", ""))), phase_tag]):
            if extra_tag not in normalized_tags:
                final_tags.append(extra_tag)
                normalized_tags.add(extra_tag)

        if final_tags:
            item["tags"] = final_tags

        reference_parts = [
            part for part in [
                item.get("reference"),
                source_meta.get("pdf_url"),
                source_meta.get("local_path"),
            ]
            if isinstance(part, str) and normalize_space(part)
        ]
        if reference_parts:
            item["reference"] = " | ".join(dict.fromkeys(reference_parts))

    return data


def validate_generated_batch(data: list) -> list[str]:
    issues: list[str] = []

    if not isinstance(data, list) or not data:
        return ["Lote vazio ou fora do formato de array."]

    for index, item in enumerate(data, start=1):
        if not isinstance(item, dict):
            issues.append(f"Questão {index}: item não é objeto JSON.")
            continue

        for field in ("area", "tema", "dificuldade", "enunciado", "comentario"):
            if not normalize_space(str(item.get(field, "") if item.get(field) is not None else "")):
                issues.append(f"Questão {index}: campo obrigatório vazio -> {field}.")

        alternativas = item.get("alternativas")
        if not isinstance(alternativas, dict):
            issues.append(f"Questão {index}: alternativas ausentes ou inválidas.")
            continue

        for key in ("A", "B", "C", "D", "E"):
            value = normalize_space(str(alternativas.get(key, "")))
            if not value:
                issues.append(f"Questão {index}: alternativa {key} vazia.")

        for pos, key_a in enumerate(("A", "B", "C", "D", "E")):
            text_a = normalize_space(str(alternativas.get(key_a, "")))
            if not text_a:
                continue
            for key_b in ("A", "B", "C", "D", "E")[pos + 1:]:
                text_b = normalize_space(str(alternativas.get(key_b, "")))
                if not text_b:
                    continue
                overlap = leading_token_overlap(text_a, text_b)
                if overlap >= 3:
                    issues.append(
                        f"Questão {index}: alternativas {key_a} e {key_b} parecem 'irmãs' "
                        f"(mesmo tronco diagnóstico/conduta com variação escondida)."
                    )

        gabarito = str(item.get("gabarito", ""))
        if gabarito not in {"A", "B", "C", "D", "E"}:
            issues.append(f"Questão {index}: gabarito inválido.")

        comentario = normalize_space(str(item.get("comentario", "")))
        if comentario and len(comentario) < 120:
            issues.append(f"Questão {index}: comentário curto demais para auditoria segura.")

    return issues


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
                response = await asyncio.wait_for(
                    asyncio.to_thread(
                        client.models.generate_content,
                        model=GEMINI_MODEL,
                        contents=final_prompt,
                        config=types.GenerateContentConfig(
                            temperature=0.6,
                            top_p=0.9,
                            top_k=40,
                            response_mime_type="application/json",
                        ),
                    ),
                    timeout=REQUEST_TIMEOUT_SECONDS,
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
            , flush=True)
            await asyncio.sleep(delay)

    if last_exc is not None:
        raise last_exc

    raise RuntimeError(f"Falha inesperada ao gerar conteúdo para {file_path.name}")


async def generate_from_file(file_path: Path, sem: asyncio.Semaphore, source_meta: dict | None):
    global completed_batches

    print(f"Processando: {file_path.name}", flush=True)
    prompt = file_path.read_text(encoding="utf-8")
    final_prompt = build_prompt(prompt)

    try:
        response, attempts = await generate_with_retry(final_prompt, file_path, sem)

        text = getattr(response, "text", "") or ""
        data = extract_json(text)
        data = enrich_questions(data, source_meta) if isinstance(data, list) else data
        batch_issues = validate_generated_batch(data)

        async with lock:
            if data and not batch_issues:
                all_questions.extend(data)
            else:
                rejected_batches.append({
                    "batch": file_path.name,
                    "reason": "JSON inválido, vazio ou com falhas mínimas de qualidade",
                    "issues": batch_issues[:20],
                    "raw": text[:4000],
                })
            completed_batches += 1
            current_progress = completed_batches

        attempt_label = f" em {attempts} tentativa(s)" if attempts > 1 else ""
        if batch_issues:
            print(
                f"REJEITADO [{current_progress}]: {file_path.name} -> {len(batch_issues)} problema(s){attempt_label}",
                flush=True,
            )
        else:
            print(
                f"OK [{current_progress}]: {file_path.name} -> {len(data)} questões{attempt_label}",
                flush=True,
            )
    except Exception as exc:
        async with lock:
            rejected_batches.append({
                "batch": file_path.name,
                "reason": str(exc),
                "raw": None,
            })
            completed_batches += 1
            current_progress = completed_batches
        print(f"ERRO [{current_progress}]: {file_path.name} -> {exc}", flush=True)


async def main():
    parser = argparse.ArgumentParser(description="Gera questões em JSON a partir dos prompts do pipeline")
    parser.add_argument("--limit", type=int, default=0, help="Limita a quantidade de prompts processados")
    parser.add_argument("--input-dir", default=None, help="Diretório customizado com prompts .prompt.txt")
    parser.add_argument("--output-file", default=None, help="Arquivo JSON de saída customizado")
    parser.add_argument("--rejected-file", default=None, help="Arquivo JSON de rejeitados customizado")
    parser.add_argument("--exam", default=os.getenv("QUESTIONS_EXAM_SCOPE", "Revalida"))
    parser.add_argument("--phase", default=os.getenv("QUESTIONS_PHASE_SCOPE", "first"))
    parser.add_argument(
        "--filter-prompts-by-scope",
        action="store_true",
        help="Restringe os prompts ao escopo exam/phase usando o text_manifest atual.",
    )
    args = parser.parse_args()

    batch_dir = Path(args.input_dir).expanduser().resolve() if args.input_dir else BATCH_DIR
    output_path = Path(args.output_file).expanduser().resolve() if args.output_file else OUTPUT_PATH
    rejected_path = Path(args.rejected_file).expanduser().resolve() if args.rejected_file else REJECTED_PATH

    source_index = load_text_manifest_index()
    candidate_files = sorted(batch_dir.glob("*.prompt.txt"))

    if args.input_dir or not args.filter_prompts_by_scope:
        files = candidate_files
    else:
        files = [
            file_path
            for file_path in candidate_files
            if matches_scope(source_index.get(batch_base_name(file_path)), args.exam, args.phase)
        ]

    if not candidate_files:
        raise SystemExit(f"Nenhum prompt encontrado em {batch_dir}")

    if not files:
        raise SystemExit(
            f"Nenhum prompt compatível com exam={args.exam} phase={args.phase} foi encontrado em {batch_dir}. "
            "Verifique o text_manifest.json atual ou regenere os prompts."
        )

    if args.limit and args.limit > 0:
        files = files[: args.limit]

    sem = asyncio.Semaphore(MAX_CONCURRENT)
    started = time.time()
    print(
        f"Iniciando geração automática: {len(files)} prompt(s), concorrência={MAX_CONCURRENT}, "
        f"modelo={GEMINI_MODEL}, timeout_por_lote={REQUEST_TIMEOUT_SECONDS:.0f}s",
        flush=True,
    )

    await asyncio.gather(*(
        generate_from_file(file_path, sem, source_index.get(batch_base_name(file_path)))
        for file_path in files
    ))

    output_path.parent.mkdir(parents=True, exist_ok=True)
    rejected_path.parent.mkdir(parents=True, exist_ok=True)

    output_path.write_text(
        json.dumps(all_questions, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    rejected_path.write_text(
        json.dumps(rejected_batches, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    elapsed = round(time.time() - started, 1)
    print(flush=True)
    print(f"Total: {len(all_questions)}", flush=True)
    print(f"Rejeitados: {len(rejected_batches)}", flush=True)
    print(f"Tempo: {elapsed}s", flush=True)
    print(f"Arquivo: {output_path}", flush=True)
    print(f"Rejeitados: {rejected_path}", flush=True)


asyncio.run(main())
