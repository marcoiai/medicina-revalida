#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
import os
import re
import unicodedata
from collections import Counter, defaultdict
from dataclasses import asdict, dataclass, field
from difflib import SequenceMatcher
from pathlib import Path
from typing import Any

try:
    from google import genai
    from google.genai import types
except ImportError:
    genai = None
    types = None

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INPUT = ROOT / "storage" / "imports" / "questions.json"
ALT_KEYS = ("A", "B", "C", "D", "E")
VALID_DIFFICULTIES = {"Fácil", "Media", "Média", "Difícil", "Dificil"}
META_OPTION_PATTERNS = (
    re.compile(r"\btodas?\s+as\s+(alternativas|anteriores)\b", re.IGNORECASE),
    re.compile(r"\bnenhuma\s+das\s+(alternativas|anteriores)\b", re.IGNORECASE),
    re.compile(r"\bapenas\s+(i|ii|iii|iv)\b", re.IGNORECASE),
    re.compile(r"\b(i|ii|iii|iv)\s*(e|,)\s*(i|ii|iii|iv)\b", re.IGNORECASE),
    re.compile(r"\balternativas?\s+[a-e](\s*(e|,)\s*[a-e])+\b", re.IGNORECASE),
)
QUESTION_WORD_PATTERNS = (
    re.compile(r"\?$"),
    re.compile(r"\bassinale\b", re.IGNORECASE),
    re.compile(r"\bqual\b", re.IGNORECASE),
    re.compile(r"\bindique\b", re.IGNORECASE),
    re.compile(r"\bmarque\b", re.IGNORECASE),
    re.compile(r"\bescolha\b", re.IGNORECASE),
)
NEGATIVE_STEM_PATTERNS = (
    re.compile(r"\bn[aã]o\b", re.IGNORECASE),
    re.compile(r"\bexceto\b", re.IGNORECASE),
    re.compile(r"\bincorreta\b", re.IGNORECASE),
    re.compile(r"\bfalsa\b", re.IGNORECASE),
    re.compile(r"\bcontraindicad", re.IGNORECASE),
)
PROMPT_LEAK_PATTERNS = (
    re.compile(r"\bjson\b", re.IGNORECASE),
    re.compile(r"\bmarkdown\b", re.IGNORECASE),
    re.compile(r"\btexto-base\b", re.IGNORECASE),
    re.compile(r"\bquatro alternativas\b", re.IGNORECASE),
    re.compile(r"\b4 alternativas\b", re.IGNORECASE),
)
STOPWORDS = {
    "a", "ao", "aos", "as", "com", "como", "da", "das", "de", "do", "dos", "e",
    "em", "entre", "essa", "esse", "esta", "este", "ha", "mais", "na", "nas",
    "no", "nos", "o", "os", "ou", "para", "pela", "pelas", "pelo", "pelos",
    "por", "qual", "que", "se", "sem", "ser", "sua", "suas", "seu", "seus",
    "uma", "um",
}


@dataclass
class Issue:
    severity: str
    code: str
    message: str
    details: dict[str, Any] | None = None


@dataclass
class LlmAudit:
    status: str
    summary: str | None = None
    issues: list[Issue] = field(default_factory=list)
    raw: dict[str, Any] | None = None


@dataclass
class ValidationResult:
    index: int
    question_id: str
    decision: str
    content_hash: str
    issues: list[Issue] = field(default_factory=list)
    llm_audit: LlmAudit | None = None


def strip_accents(text: str) -> str:
    normalized = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in normalized if not unicodedata.combining(ch))


def normalize_space(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip()


def normalize_text(text: Any) -> str:
    return normalize_space(strip_accents(str(text or "")).lower())


def similarity(a: str, b: str) -> float:
    return SequenceMatcher(None, normalize_text(a), normalize_text(b)).ratio()


def stable_question_id(index: int, question: dict[str, Any]) -> str:
    signature = json.dumps(
        {
            "enunciado": normalize_space(str(question.get("enunciado", ""))),
            "alternativas": {
                key: normalize_space(str((question.get("alternativas") or {}).get(key, "")))
                for key in ALT_KEYS
            },
            "gabarito": str(question.get("gabarito", "")),
        },
        ensure_ascii=False,
        sort_keys=True,
    )
    suffix = hashlib.sha1(signature.encode("utf-8")).hexdigest()[:10]
    return f"q{index:05d}_{suffix}"


def add_issue(
    issues: list[Issue],
    severity: str,
    code: str,
    message: str,
    details: dict[str, Any] | None = None,
) -> None:
    issues.append(Issue(severity=severity, code=code, message=message, details=details))


def has_question_shape(enunciado: str) -> bool:
    clean = normalize_space(enunciado)
    return any(pattern.search(clean) for pattern in QUESTION_WORD_PATTERNS)


def has_negative_stem(enunciado: str) -> bool:
    clean = normalize_space(enunciado)
    return any(pattern.search(clean) for pattern in NEGATIVE_STEM_PATTERNS)


def validate_question(index: int, question: Any) -> ValidationResult:
    if not isinstance(question, dict):
        issues = [
            Issue(
                severity="error",
                code="invalid_question_type",
                message="A questão não é um objeto JSON.",
            )
        ]
        return ValidationResult(
            index=index,
            question_id=f"q{index:05d}_invalid",
            decision="rejected",
            content_hash="invalid",
            issues=issues,
        )

    question_id = stable_question_id(index, question)
    content_hash = hashlib.sha256(
        json.dumps(question, ensure_ascii=False, sort_keys=True).encode("utf-8")
    ).hexdigest()
    issues: list[Issue] = []

    for field_name in ("area", "tema", "enunciado", "comentario"):
        value = question.get(field_name)
        if not isinstance(value, str) or not normalize_space(value):
            add_issue(
                issues,
                "error",
                "missing_text_field",
                f"Campo obrigatório ausente ou vazio: {field_name}.",
                {"field": field_name},
            )

    difficulty = question.get("dificuldade")
    if not isinstance(difficulty, str) or not normalize_space(difficulty):
        add_issue(issues, "error", "missing_difficulty", "Campo 'dificuldade' ausente ou vazio.")
    elif normalize_space(difficulty) not in VALID_DIFFICULTIES:
        add_issue(
            issues,
            "warning",
            "unexpected_difficulty",
            "Valor de dificuldade fora do padrão esperado.",
            {"value": difficulty},
        )

    enunciado = str(question.get("enunciado", "") or "")
    comentario = str(question.get("comentario", "") or "")
    reference = str(question.get("reference", "") or "")

    if enunciado and len(normalize_space(enunciado)) < 60:
        add_issue(
            issues,
            "warning",
            "short_enunciado",
            "Enunciado muito curto para uma questão clínica típica.",
            {"length": len(normalize_space(enunciado))},
        )

    if comentario and len(normalize_space(comentario)) < 80:
        add_issue(
            issues,
            "warning",
            "short_comentario",
            "Comentário curto demais para justificar o gabarito com segurança.",
            {"length": len(normalize_space(comentario))},
        )

    if has_negative_stem(enunciado):
        comment_lower = normalize_text(comentario)
        if len(normalize_space(comentario)) < 180:
            add_issue(
                issues,
                "warning",
                "negative_stem_needs_stronger_commentary",
                "Questões com negação, exceção ou contraindicação precisam de comentário mais robusto.",
                {"length": len(normalize_space(comentario))},
            )
        if not re.search(r"\b(errad|incorret|demais|outras|precauc|contraindic|gabarito|correta)\b", comment_lower):
            add_issue(
                issues,
                "warning",
                "negative_stem_without_disambiguation",
                "O comentário não deixa claro por que as outras alternativas não são a melhor resposta.",
            )

    if enunciado and not has_question_shape(enunciado):
        add_issue(
            issues,
            "warning",
            "weak_question_shape",
            "O enunciado não tem forma interrogativa clara nem comando típico de múltipla escolha.",
        )

    for pattern in PROMPT_LEAK_PATTERNS:
        if enunciado and pattern.search(enunciado):
            add_issue(
                issues,
                "warning",
                "prompt_leak",
                "O enunciado parece conter restos de instruções do prompt.",
                {"pattern": pattern.pattern},
            )
            break

    if not normalize_space(reference):
        add_issue(
            issues,
            "warning",
            "missing_reference",
            "A questão não informa referência ou fonte.",
        )

    if comentario and similarity(comentario, enunciado) > 0.92:
        add_issue(
            issues,
            "warning",
            "commentary_too_similar_to_stem",
            "Comentário muito parecido com o enunciado; pode ter sido reciclado sem justificativa real.",
        )

    alternativas = question.get("alternativas")
    normalized_alternatives: dict[str, str] = {}

    if not isinstance(alternativas, dict):
        add_issue(
            issues,
            "error",
            "invalid_alternativas",
            "Campo 'alternativas' deve ser um objeto com A-E.",
        )
    else:
        missing_keys = [key for key in ALT_KEYS if key not in alternativas]
        extra_keys = [key for key in alternativas.keys() if key not in ALT_KEYS]
        if missing_keys:
            add_issue(
                issues,
                "error",
                "missing_alternatives",
                "Faltam alternativas obrigatórias.",
                {"missing": missing_keys},
            )
        if extra_keys:
            add_issue(
                issues,
                "warning",
                "extra_alternatives",
                "Existem chaves extras em 'alternativas'.",
                {"extra": extra_keys},
            )

        seen_texts: dict[str, str] = {}
        alt_lengths: dict[str, int] = {}
        for key in ALT_KEYS:
            raw_value = alternativas.get(key, "")
            if not isinstance(raw_value, str) or not normalize_space(raw_value):
                add_issue(
                    issues,
                    "error",
                    "empty_alternative",
                    "Alternativa ausente ou vazia.",
                    {"alternative": key},
                )
                continue

            clean = normalize_space(raw_value)
            normalized = normalize_text(clean)
            normalized_alternatives[key] = normalized
            alt_lengths[key] = len(clean)

            if normalized in seen_texts:
                add_issue(
                    issues,
                    "error",
                    "duplicate_alternative",
                    "Existem alternativas duplicadas.",
                    {"alternative": key, "duplicate_of": seen_texts[normalized]},
                )
            else:
                seen_texts[normalized] = key

            for pattern in META_OPTION_PATTERNS:
                if pattern.search(clean):
                    add_issue(
                        issues,
                        "warning",
                        "meta_alternative",
                        "Alternativa usa metalinguagem típica de item ambíguo.",
                        {"alternative": key, "pattern": pattern.pattern},
                    )
                    break

        for i, key_a in enumerate(ALT_KEYS):
            if key_a not in alternativas or key_a not in normalized_alternatives:
                continue
            text_a = str(alternativas[key_a])
            for key_b in ALT_KEYS[i + 1:]:
                if key_b not in alternativas or key_b not in normalized_alternatives:
                    continue
                text_b = str(alternativas[key_b])
                if min(len(text_a), len(text_b)) < 25:
                    continue
                score = similarity(text_a, text_b)
                if score >= 0.94:
                    add_issue(
                        issues,
                        "warning",
                        "similar_alternatives",
                        "Duas alternativas são parecidas demais e podem gerar ambiguidade.",
                        {"alternatives": [key_a, key_b], "similarity": round(score, 3)},
                    )

        if alt_lengths:
            ordered_lengths = sorted(alt_lengths.values())
            median_length = ordered_lengths[len(ordered_lengths) // 2]
            gabarito = str(question.get("gabarito", "") or "")
            if gabarito in alt_lengths and median_length > 0:
                answer_length = alt_lengths[gabarito]
                if answer_length >= max(140, int(median_length * 2.2)):
                    add_issue(
                        issues,
                        "warning",
                        "answer_length_outlier",
                        "A alternativa correta é muito mais longa que as demais.",
                        {
                            "gabarito": gabarito,
                            "answer_length": answer_length,
                            "median_length": median_length,
                        },
                    )

    gabarito = question.get("gabarito")
    if not isinstance(gabarito, str) or gabarito not in ALT_KEYS:
        add_issue(issues, "error", "invalid_gabarito", "Campo 'gabarito' inválido; esperado A-E.")
    elif isinstance(alternativas, dict) and not normalize_space(str(alternativas.get(gabarito, ""))):
        add_issue(
            issues,
            "error",
            "gabarito_without_alternative",
            "O gabarito aponta para uma alternativa vazia ou inexistente.",
            {"gabarito": gabarito},
        )

    decision = "approved"
    if any(issue.severity == "error" for issue in issues):
        decision = "rejected"
    elif issues:
        decision = "flagged"

    return ValidationResult(
        index=index,
        question_id=question_id,
        decision=decision,
        content_hash=content_hash,
        issues=issues,
    )


def apply_cross_question_checks(questions: list[Any], results: list[ValidationResult]) -> None:
    by_stem_options: dict[str, list[int]] = defaultdict(list)
    by_stem_only: dict[str, list[int]] = defaultdict(list)

    for index, question in enumerate(questions, start=1):
        if not isinstance(question, dict):
            continue

        stem = normalize_space(str(question.get("enunciado", "")))
        alternativas = question.get("alternativas") or {}
        canonical_stem_only = normalize_text(stem)
        canonical_stem_options = json.dumps(
            {
                "enunciado": normalize_text(stem),
                "alternativas": {
                    key: normalize_text(str(alternativas.get(key, ""))) for key in ALT_KEYS
                },
            },
            ensure_ascii=False,
            sort_keys=True,
        )

        by_stem_only[canonical_stem_only].append(index)
        by_stem_options[canonical_stem_options].append(index)

    by_index = {result.index: result for result in results}

    for duplicate_indexes in by_stem_options.values():
        if len(duplicate_indexes) < 2:
            continue

        gabaritos = {str((questions[idx - 1] or {}).get("gabarito", "")) for idx in duplicate_indexes}
        severity = "error" if len(gabaritos) > 1 else "warning"
        code = "conflicting_duplicate_question" if len(gabaritos) > 1 else "duplicate_question"
        message = (
            "Questões idênticas com gabaritos diferentes."
            if len(gabaritos) > 1
            else "Questão duplicada no mesmo arquivo."
        )

        for idx in duplicate_indexes:
            add_issue(
                by_index[idx].issues,
                severity,
                code,
                message,
                {"duplicate_indexes": duplicate_indexes},
            )

    for duplicate_indexes in by_stem_only.values():
        if len(duplicate_indexes) < 2:
            continue
        for idx in duplicate_indexes:
            add_issue(
                by_index[idx].issues,
                "warning",
                "duplicate_stem",
                "Mesmo enunciado aparece em mais de uma questão no arquivo.",
                {"duplicate_indexes": duplicate_indexes},
            )

    for result in results:
        if any(issue.severity == "error" for issue in result.issues):
            result.decision = "rejected"
        elif result.issues:
            result.decision = "flagged"
        else:
            result.decision = "approved"


class GeminiAuditor:
    def __init__(self, model: str, max_concurrent: int, strict: bool):
        self.model = model
        self.max_concurrent = max_concurrent
        self.strict = strict

        api_key = os.getenv("GEMINI_API_KEY") or os.getenv("GOOGLE_API_KEY")
        if not api_key:
            raise SystemExit("GEMINI_API_KEY (ou GOOGLE_API_KEY) não configurada para auditoria LLM.")
        if genai is None or types is None:
            raise SystemExit(
                "Dependência do Gemini não instalada. Rode: pip install -r tools/requirements-ai.txt"
            )

        self.client = genai.Client(api_key=api_key)

    def build_prompt(self, question: dict[str, Any]) -> str:
        payload = {
            "area": question.get("area"),
            "tema": question.get("tema"),
            "dificuldade": question.get("dificuldade"),
            "enunciado": question.get("enunciado"),
            "alternativas": question.get("alternativas"),
            "gabarito": question.get("gabarito"),
            "comentario": question.get("comentario"),
            "reference": question.get("reference"),
        }
        return f"""
Você é um revisor técnico de banco de questões médicas.
Analise a questão abaixo e responda apenas com JSON válido.

Critérios:
- existe uma única alternativa claramente defensável?
- há coerência entre enunciado, alternativas, gabarito e comentário?
- a redação está objetiva, sem ambiguidade estrutural?
- a dificuldade parece compatível com o rótulo informado?
- há sinal de erro factual relevante ou mistura indevida de conceitos?

Saída obrigatória:
{{
  "decision": "pass|flag|reject",
  "summary": "resumo curto",
  "issues": [
    {{
      "severity": "warning|error",
      "code": "snake_case",
      "message": "motivo objetivo"
    }}
  ]
}}

Se houver dúvida razoável sobre gabarito único, use pelo menos "flag".

QUESTAO:
{json.dumps(payload, ensure_ascii=False, indent=2)}
""".strip()

    async def audit_question(self, question: dict[str, Any]) -> LlmAudit:
        prompt = self.build_prompt(question)
        response = await asyncio.to_thread(
            self.client.models.generate_content,
            model=self.model,
            contents=prompt,
            config=types.GenerateContentConfig(
                temperature=0.1,
                top_p=0.95,
                response_mime_type="application/json",
            ),
        )

        raw_text = getattr(response, "text", "") or ""
        try:
            data = json.loads(raw_text)
        except json.JSONDecodeError:
            return LlmAudit(
                status="flag",
                summary="Resposta LLM inválida.",
                issues=[
                    Issue(
                        severity="warning",
                        code="llm_invalid_json",
                        message="A auditoria semântica retornou JSON inválido.",
                    )
                ],
                raw={"raw_text": raw_text[:4000]},
            )

        decision = str(data.get("decision", "flag")).strip().lower()
        if decision not in {"pass", "flag", "reject"}:
            decision = "flag"

        issues: list[Issue] = []
        for item in data.get("issues", []) or []:
            if not isinstance(item, dict):
                continue
            severity = str(item.get("severity", "warning")).strip().lower()
            if severity not in {"warning", "error"}:
                severity = "warning"
            issues.append(
                Issue(
                    severity=severity,
                    code=str(item.get("code", "llm_issue")).strip() or "llm_issue",
                    message=str(item.get("message", "Questão sinalizada pela auditoria LLM.")).strip(),
                )
            )

        return LlmAudit(
            status=decision,
            summary=str(data.get("summary", "") or "").strip() or None,
            issues=issues,
            raw=data,
        )

    async def audit_all(self, questions: list[Any], results: list[ValidationResult]) -> None:
        semaphore = asyncio.Semaphore(self.max_concurrent)

        async def worker(question: dict[str, Any], result: ValidationResult) -> None:
            if result.decision == "rejected":
                return

            async with semaphore:
                audit = await self.audit_question(question)
            result.llm_audit = audit

            if audit.summary:
                add_issue(result.issues, "warning", "llm_summary", audit.summary)

            for issue in audit.issues:
                severity = issue.severity
                if self.strict and audit.status == "reject" and severity == "warning":
                    severity = "error"
                add_issue(result.issues, severity, f"llm_{issue.code}", issue.message)

            if audit.status in {"flag", "reject"} and not audit.issues and audit.summary:
                add_issue(
                    result.issues,
                    "warning" if audit.status == "flag" or not self.strict else "error",
                    "llm_flag_without_detail",
                    "A auditoria LLM sinalizou a questão para revisão.",
                )

            if any(issue.severity == "error" for issue in result.issues):
                result.decision = "rejected"
            elif result.issues:
                result.decision = "flagged"
            else:
                result.decision = "approved"

        await asyncio.gather(
            *(
                worker(question, result)
                for question, result in zip(questions, results)
                if isinstance(question, dict)
            )
        )


def serialize_issue(issue: Issue) -> dict[str, Any]:
    return asdict(issue)


def serialize_result(result: ValidationResult, question: dict[str, Any]) -> dict[str, Any]:
    return {
        "index": result.index,
        "question_id": result.question_id,
        "decision": result.decision,
        "content_hash": result.content_hash,
        "issues": [serialize_issue(issue) for issue in result.issues],
        "llm_audit": {
            "status": result.llm_audit.status,
            "summary": result.llm_audit.summary,
            "issues": [serialize_issue(issue) for issue in result.llm_audit.issues],
            "raw": result.llm_audit.raw,
        }
        if result.llm_audit
        else None,
        "question": question,
    }


def write_outputs(
    input_path: Path,
    output_dir: Path,
    questions: list[Any],
    results: list[ValidationResult],
    llm_enabled: bool,
) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    stem = input_path.stem

    approved_questions: list[dict[str, Any]] = []
    flagged_items: list[dict[str, Any]] = []
    rejected_items: list[dict[str, Any]] = []

    issue_counter: Counter[str] = Counter()
    severity_counter: Counter[str] = Counter()

    for question, result in zip(questions, results):
        for issue in result.issues:
            issue_counter[issue.code] += 1
            severity_counter[issue.severity] += 1

        if result.decision == "approved":
            if isinstance(question, dict):
                approved_questions.append(question)
            continue

        item = serialize_result(result, question if isinstance(question, dict) else {"raw": question})
        if result.decision == "flagged":
            flagged_items.append(item)
        else:
            rejected_items.append(item)

    report = {
        "meta": {
            "input": str(input_path),
            "output_dir": str(output_dir),
            "llm_enabled": llm_enabled,
            "total": len(results),
            "approved": len(approved_questions),
            "flagged": len(flagged_items),
            "rejected": len(rejected_items),
        },
        "issue_counts": dict(issue_counter.most_common()),
        "severity_counts": dict(severity_counter),
        "results": [
            {
                "index": result.index,
                "question_id": result.question_id,
                "decision": result.decision,
                "content_hash": result.content_hash,
                "enunciado_preview": normalize_space(
                    str((question or {}).get("enunciado", "")) if isinstance(question, dict) else ""
                )[:220],
                "issues": [serialize_issue(issue) for issue in result.issues],
                "llm_audit": {
                    "status": result.llm_audit.status,
                    "summary": result.llm_audit.summary,
                    "issues": [serialize_issue(issue) for issue in result.llm_audit.issues],
                }
                if result.llm_audit
                else None,
            }
            for question, result in zip(questions, results)
        ],
    }

    (output_dir / f"{stem}.approved.json").write_text(
        json.dumps(approved_questions, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    (output_dir / f"{stem}.flagged.json").write_text(
        json.dumps(flagged_items, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    (output_dir / f"{stem}.rejected.json").write_text(
        json.dumps(rejected_items, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    (output_dir / f"{stem}.validation_report.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print()
    print(f"Total: {len(results)}")
    print(f"Aprovadas: {len(approved_questions)}")
    print(f"Sinalizadas: {len(flagged_items)}")
    print(f"Rejeitadas: {len(rejected_items)}")
    print(f"Saida: {output_dir}")
    if not llm_enabled:
        print("Dica: use --llm para auditar ambiguidade de gabarito e plausibilidade clinica.")


async def main() -> None:
    parser = argparse.ArgumentParser(
        description="Valida qualidade estrutural e semantica de questoes em JSON."
    )
    parser.add_argument(
        "input",
        nargs="?",
        default=str(DEFAULT_INPUT),
        help="Arquivo JSON de entrada. Default: storage/imports/questions.json",
    )
    parser.add_argument(
        "--output-dir",
        default=None,
        help="Diretorio para approved/flagged/rejected/report. Default: <pasta do input>/validation",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=0,
        help="Limita a quantidade de questoes processadas.",
    )
    parser.add_argument(
        "--llm",
        action="store_true",
        help="Ativa auditoria semantica com Gemini apos as regras objetivas.",
    )
    parser.add_argument(
        "--llm-model",
        default=os.getenv("QUESTION_VALIDATOR_MODEL") or os.getenv("GEMINI_MODEL", "gemini-2.5-flash"),
        help="Modelo Gemini para a auditoria LLM.",
    )
    parser.add_argument(
        "--llm-max-concurrent",
        type=int,
        default=int(os.getenv("QUESTION_VALIDATOR_MAX_CONCURRENT", "4")),
        help="Numero maximo de auditorias LLM em paralelo.",
    )
    parser.add_argument(
        "--llm-strict",
        action="store_true",
        help="Promove rejeicoes do LLM a erro duro.",
    )
    args = parser.parse_args()

    input_path = Path(args.input).expanduser().resolve()
    if not input_path.exists():
        raise SystemExit(f"Arquivo nao encontrado: {input_path}")

    output_dir = (
        Path(args.output_dir).expanduser().resolve()
        if args.output_dir
        else input_path.parent / "validation"
    )

    raw = json.loads(input_path.read_text(encoding="utf-8"))
    if not isinstance(raw, list):
        raise SystemExit("O arquivo de entrada deve ser um array JSON.")

    questions = raw[: args.limit] if args.limit and args.limit > 0 else raw
    results = [validate_question(index, question) for index, question in enumerate(questions, start=1)]
    apply_cross_question_checks(questions, results)

    if args.llm:
        auditor = GeminiAuditor(
            model=args.llm_model,
            max_concurrent=args.llm_max_concurrent,
            strict=args.llm_strict,
        )
        await auditor.audit_all(questions, results)

    write_outputs(
        input_path=input_path,
        output_dir=output_dir,
        questions=questions,
        results=results,
        llm_enabled=args.llm,
    )


if __name__ == "__main__":
    asyncio.run(main())
