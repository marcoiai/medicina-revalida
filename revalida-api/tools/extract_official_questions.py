#!/usr/bin/env python3
import argparse
import json
import re
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEXT_MANIFEST_PATH = ROOT / "storage" / "imports" / "text_manifest.json"
OUTPUT_PATH = ROOT / "storage" / "imports" / "questions.json"

QUESTION_RE = re.compile(r"^QUESTÃO\s+(\d+)$", re.IGNORECASE)
ALT_LINE_RE = re.compile(r"^([A-E])\s+(.+)$")
EDITION_RE = re.compile(r"(\d{4}_\d)")
ANNULLED_MARKERS = {"̶", "-", "—", "–"}


def normalize_line(line: str) -> str:
    normalized = unicodedata.normalize("NFKC", line or "")
    return re.sub(r"\s+", " ", normalized).strip()


def extract_edition_key(filename: str) -> str | None:
    match = EDITION_RE.search(filename or "")
    if not match:
        return None
    return match.group(1)


def is_objective_proof(item: dict) -> bool:
    filename = (item.get("filename") or "").lower()
    return "_pv_objetiva" in filename


def is_objective_answer_key(item: dict) -> bool:
    filename = (item.get("filename") or "").lower()
    return "_gb_objetiva" in filename


def is_discursive_proof(item: dict) -> bool:
    filename = (item.get("filename") or "").lower()
    return "_pv_discursiva" in filename


def is_discursive_answer_key(item: dict) -> bool:
    filename = (item.get("filename") or "").lower()
    return "_pep_discursiva" in filename


def choose_preferred_proof(candidates: list[dict]) -> dict | None:
    if not candidates:
        return None

    def score(item: dict) -> tuple[int, int]:
        filename = (item.get("filename") or "").lower()
        return (
            1 if "regular" in filename else 0,
            0 if "ampliada" in filename else 1,
        )

    return max(candidates, key=score)


def load_manifest() -> list[dict]:
    if not TEXT_MANIFEST_PATH.exists():
        raise SystemExit("Manifesto não encontrado em storage/imports/text_manifest.json.")

    try:
        manifest = json.loads(TEXT_MANIFEST_PATH.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise SystemExit("Manifesto de textos inválido.") from exc

    if not isinstance(manifest, list) or not manifest:
        raise SystemExit("Nenhum texto disponível em storage/imports/text_manifest.json.")

    return manifest


def strip_leading_item_code(lines: list[str]) -> list[str]:
    if lines and re.match(r"^\d+\.\s*ITEM\b", lines[0], re.IGNORECASE):
        return lines[1:]
    return lines


def sanitize_question_lines(raw_text: str) -> list[str]:
    lines: list[str] = []
    for raw_line in raw_text.splitlines():
        line = normalize_line(raw_line)
        if not line:
            continue
        if line.startswith("--- PAGE "):
            continue
        if line in {"PROVA OBJETIVA", "ÁREA LIVRE"}:
            continue
        if line.upper().startswith("QUESTIONÁRIO DE PERCEPÇÃO SOBRE A PROVA"):
            break
        if "PRIMEIRA EDIÇÃO" in line.upper() or "SEGUNDA EDIÇÃO" in line.upper():
            continue
        if re.fullmatch(r"EDIÇÃO\s+\d{4}/\d", line, re.IGNORECASE):
            continue
        lines.append(line)
    return lines


def split_question_blocks(lines: list[str]) -> list[tuple[int, list[str]]]:
    blocks: list[tuple[int, list[str]]] = []
    current_number: int | None = None
    current_lines: list[str] = []

    for line in lines:
        match = QUESTION_RE.match(line)
        if match:
            if current_number is not None and current_lines:
                blocks.append((current_number, current_lines))
            current_number = int(match.group(1))
            current_lines = []
            continue

        if current_number is not None:
            current_lines.append(line)

    if current_number is not None and current_lines:
        blocks.append((current_number, current_lines))

    return blocks


def pick_alternative_sequence(lines: list[str]) -> list[tuple[str, int]]:
    candidates: list[tuple[int, str]] = []
    for index, line in enumerate(lines):
        match = ALT_LINE_RE.match(line)
        if match:
            candidates.append((index, match.group(1)))

    best_sequence: list[tuple[str, int]] = []
    best_score: tuple[int, int, int] | None = None

    for expected_letters in (["A", "B", "C", "D", "E"], ["A", "B", "C", "D"]):
        for start_index, (_, letter) in enumerate(candidates):
            if letter != "A":
                continue

            sequence: list[tuple[str, int]] = []
            expected_index = 0

            for line_index, candidate_letter in candidates[start_index:]:
                if candidate_letter != expected_letters[expected_index]:
                    continue

                sequence.append((candidate_letter, line_index))
                expected_index += 1

                if expected_index == len(expected_letters):
                    break

            if expected_index != len(expected_letters):
                continue

            score = (sequence[-1][1], len(sequence), sequence[0][1])
            if best_score is None or score > best_score:
                best_score = score
                best_sequence = sequence

    return best_sequence


def parse_question_block(lines: list[str]) -> tuple[str, dict[str, str]] | None:
    sequence = pick_alternative_sequence(lines)
    if len(sequence) < 4:
        return None

    positions = {letter: index for letter, index in sequence}
    ordered_letters = [letter for letter, _ in sequence]
    start_of_alternatives = sequence[0][1]

    stem = normalize_line(" ".join(lines[:start_of_alternatives]))
    if not stem:
        return None

    alternatives: dict[str, str] = {}
    for offset, letter in enumerate(ordered_letters):
        start = positions[letter]
        end = positions[ordered_letters[offset + 1]] if offset + 1 < len(ordered_letters) else len(lines)
        segment = lines[start:end]
        if not segment:
            return None

        first_line_match = ALT_LINE_RE.match(segment[0])
        if not first_line_match:
            return None

        alternative_lines = [first_line_match.group(2), *segment[1:]]
        alternatives[letter] = normalize_line(" ".join(alternative_lines))

    if any(not alternatives.get(letter) for letter in ordered_letters[:4]):
        return None

    return stem, alternatives


def parse_proof_questions(text_path: Path) -> dict[int, dict[str, str]]:
    raw_text = text_path.read_text(encoding="utf-8")
    lines = sanitize_question_lines(raw_text)
    blocks = split_question_blocks(lines)

    parsed: dict[int, dict[str, str]] = {}
    for question_number, block_lines in blocks:
        parsed_block = parse_question_block(block_lines)
        if not parsed_block:
            continue

        stem, alternatives = parsed_block
        parsed[question_number] = {
            "enunciado": stem,
            "alternativas": alternatives,
        }

    return parsed


def sanitize_discursive_proof_lines(raw_text: str) -> list[str]:
    lines: list[str] = []
    collecting = False
    skipping_scratch = False

    for raw_line in raw_text.splitlines():
        line = normalize_line(raw_line)
        if not line:
            continue
        if line.startswith("--- PAGE "):
            continue

        question_match = QUESTION_RE.match(line)
        if question_match:
            collecting = True
            skipping_scratch = False
            lines.append(line)
            continue

        if not collecting:
            continue

        if line.startswith("RASCUNHO"):
            skipping_scratch = True
            continue

        if skipping_scratch:
            continue

        if line == "ÁREA LIVRE":
            continue
        if "PRIMEIRA EDIÇÃO" in line.upper() or "SEGUNDA EDIÇÃO" in line.upper():
            continue
        if re.fullmatch(r"EDIÇÃO\s+\d{4}[/.]\d", line, re.IGNORECASE):
            continue
        if line.upper() == "PROVA DISCURSIVA":
            continue
        if re.fullmatch(r"\d+", line):
            continue

        lines.append(line)

    return lines


def parse_discursive_proof_questions(text_path: Path) -> dict[int, str]:
    raw_text = text_path.read_text(encoding="utf-8")
    lines = sanitize_discursive_proof_lines(raw_text)
    blocks = split_question_blocks(lines)

    parsed: dict[int, str] = {}
    for question_number, block_lines in blocks:
        cleaned_lines = strip_leading_item_code(block_lines)
        stem = "\n".join(cleaned_lines).strip()
        if stem:
            parsed[question_number] = stem

    return parsed


def sanitize_answer_key_lines(raw_text: str) -> list[str]:
    lines: list[str] = []
    for raw_line in raw_text.splitlines():
        line = normalize_line(raw_line)
        if not line:
            continue
        if line.startswith("--- PAGE "):
            continue
        lines.append(line)
    return lines


def normalize_answer_token(token: str) -> str | None:
    cleaned = normalize_line(token)
    if cleaned in ANNULLED_MARKERS:
        return None

    match = re.fullmatch(r"[A-E]", cleaned, re.IGNORECASE)
    if match:
        return match.group(0).upper()

    return None


def parse_answer_key(text_path: Path) -> dict[int, str | None]:
    raw_text = text_path.read_text(encoding="utf-8")
    lines = sanitize_answer_key_lines(raw_text)

    answers: dict[int, str | None] = {}
    current_numbers: list[int] = []
    current_answers: list[str | None] = []
    mode: str | None = None

    def flush() -> None:
        nonlocal current_numbers, current_answers
        for question_number, answer in zip(current_numbers, current_answers):
            answers[question_number] = answer
        current_numbers = []
        current_answers = []

    for line in lines:
        if line.lower() == "questão":
            flush()
            mode = "numbers"
            continue

        if line.lower() == "gabarito":
            mode = "answers"
            continue

        if mode == "numbers" and re.fullmatch(r"[\d ]{1,15}", line):
            current_numbers.extend(int(token) for token in line.split() if token.isdigit())
            continue

        if mode == "answers":
            current_answers.extend(normalize_answer_token(token) for token in line.split())
            if len(current_answers) == len(current_numbers):
                flush()
                mode = None
            continue

    flush()
    return answers


def sanitize_discursive_answer_lines(raw_text: str) -> list[str]:
    lines: list[str] = []
    collecting = False

    for raw_line in raw_text.splitlines():
        line = normalize_line(raw_line)
        if not line:
            continue
        if line.startswith("--- PAGE "):
            continue

        question_match = QUESTION_RE.match(line)
        if question_match:
            collecting = True
            lines.append(line)
            continue

        if not collecting:
            continue

        if "PRIMEIRA EDIÇÃO" in line.upper() or "SEGUNDA EDIÇÃO" in line.upper():
            continue
        if re.fullmatch(r"EDIÇÃO\s+\d{4}[/.]\d", line, re.IGNORECASE):
            continue
        if line.upper() in {"PROVA DISCURSIVA", "PADRÃO DE RESPOSTAS – DEFINITIVO", "PADRÃO DE RESPOSTAS - DEFINITIVO"}:
            continue
        if re.fullmatch(r"\d+", line):
            continue

        lines.append(line)

    return lines


def is_discursive_response_marker(line: str) -> bool:
    upper = line.upper()
    return "PADRÃO DE RESPOSTA" in upper or "PADRÃO RESPOSTA" in upper


def find_discursive_response_marker(lines: list[str]) -> tuple[int, int]:
    for index, line in enumerate(lines):
        if is_discursive_response_marker(line):
            return index, 1

        upper = line.upper()
        next_line = lines[index + 1].upper() if index + 1 < len(lines) else ""
        if upper in {"PADRÃO DE", "PADRAO DE", "PADRÃO", "PADRAO"} and "RESPOSTA" in next_line:
            return index, 2

    return -1, 0


def trim_reference_lines(lines: list[str]) -> list[str]:
    for index, line in enumerate(lines):
        if line.upper().startswith("REFERÊNCIA BIBLIOGRÁFICA") or line.upper().startswith("REFERENCIA BIBLIOGRAFICA"):
            return lines[:index]
    return lines


def parse_discursive_answer_key(text_path: Path) -> dict[int, dict[str, str]]:
    raw_text = text_path.read_text(encoding="utf-8")
    lines = sanitize_discursive_answer_lines(raw_text)
    blocks = split_question_blocks(lines)

    parsed: dict[int, dict[str, str]] = {}
    for question_number, block_lines in blocks:
        cleaned_lines = strip_leading_item_code(block_lines)
        marker_index, marker_length = find_discursive_response_marker(cleaned_lines)
        if marker_index == -1:
            continue

        prompt_lines = cleaned_lines[:marker_index]
        answer_lines = cleaned_lines[marker_index + marker_length:]
        while answer_lines and answer_lines[0].upper() == "RASCUNHO":
            answer_lines = answer_lines[1:]
        answer_lines = trim_reference_lines(answer_lines)

        prompt = "\n".join(prompt_lines).strip()
        official_answer = "\n".join(answer_lines).strip()
        if not prompt or not official_answer:
            continue

        parsed[question_number] = {
            "enunciado": prompt,
            "official_answer": official_answer,
        }

    return parsed


def build_question_payload(
    edition_key: str,
    question_number: int,
    question_data: dict[str, str | dict[str, str]],
    answer: str,
    proof_meta: dict,
    answer_key_meta: dict,
) -> dict:
    year, edition = edition_key.split("_", 1)
    label = f"{year}/{edition}"
    alternatives = dict(question_data["alternativas"])

    for letter in ("A", "B", "C", "D", "E"):
        alternatives.setdefault(letter, "")

    question_source = {
        "name": f"Revalida INEP {label} - Prova Objetiva Oficial",
        "institution": proof_meta.get("institution") or "INEP",
        "exam": proof_meta.get("exam") or "Revalida",
        "year": int(year),
        "url": proof_meta.get("pdf_url") or proof_meta.get("page_url"),
        "file_path": proof_meta.get("local_path"),
    }

    reference_parts = [
        "Revalida",
        f"Edição {label}",
        "Primeira fase",
        "Prova objetiva oficial",
        f"Questão {question_number}",
        proof_meta.get("pdf_url"),
        answer_key_meta.get("pdf_url"),
    ]
    reference = " | ".join(str(part) for part in reference_parts if part)

    return {
        "area": f"Revalida {label}",
        "tema": f"Objetiva oficial · Q{question_number:02d}",
        "dificuldade": "Oficial",
        "question_type": "multiple_choice",
        "enunciado": str(question_data["enunciado"]),
        "alternativas": alternatives,
        "gabarito": answer,
        "comentario": (
            f"Gabarito definitivo oficial do INEP: alternativa {answer}. "
            f"Questão importada integralmente da prova objetiva do Revalida {label}."
        ),
        "official_answer": None,
        "origin": "official_verbatim",
        "status": "reviewed",
        "reference": reference,
        "tags": [
            "revalida",
            "primeira_fase",
            "objetiva",
            "oficial",
            f"edicao_{edition_key}",
        ],
        "question_source": question_source,
    }


def build_discursive_question_payload(
    edition_key: str,
    question_number: int,
    proof_text: str,
    official_answer: str,
    proof_meta: dict,
    answer_key_meta: dict,
) -> dict:
    year, edition = edition_key.split("_", 1)
    label = f"{year}/{edition}"

    question_source = {
        "name": f"Revalida INEP {label} - Prova Discursiva Oficial",
        "institution": proof_meta.get("institution") or "INEP",
        "exam": proof_meta.get("exam") or "Revalida",
        "year": int(year),
        "url": proof_meta.get("pdf_url") or proof_meta.get("page_url"),
        "file_path": proof_meta.get("local_path"),
    }

    reference_parts = [
        "Revalida",
        f"Edição {label}",
        "Primeira fase",
        "Prova discursiva oficial",
        f"Questão {question_number}",
        proof_meta.get("pdf_url"),
        answer_key_meta.get("pdf_url"),
    ]
    reference = " | ".join(str(part) for part in reference_parts if part)

    return {
        "area": f"Revalida {label}",
        "tema": f"Discursiva oficial · Q{question_number:02d}",
        "dificuldade": "Oficial",
        "question_type": "discursive",
        "enunciado": proof_text,
        "alternativas": {"A": "", "B": "", "C": "", "D": "", "E": ""},
        "gabarito": "A",
        "comentario": "Padrão de resposta oficial do INEP disponível no campo de resposta oficial.",
        "official_answer": official_answer,
        "origin": "official_verbatim",
        "status": "reviewed",
        "reference": reference,
        "tags": [
            "revalida",
            "primeira_fase",
            "discursiva",
            "oficial",
            f"edicao_{edition_key}",
        ],
        "question_source": question_source,
    }


def extract_questions(limit_editions: int = 0) -> tuple[list[dict], list[str]]:
    manifest = load_manifest()
    objective_proofs_by_edition: dict[str, list[dict]] = {}
    objective_answer_keys_by_edition: dict[str, dict] = {}
    discursive_proofs_by_edition: dict[str, list[dict]] = {}
    discursive_answer_keys_by_edition: dict[str, dict] = {}

    for item in manifest:
        filename = item.get("filename") or ""
        edition_key = extract_edition_key(filename)
        if not edition_key:
            continue

        if is_objective_proof(item):
            objective_proofs_by_edition.setdefault(edition_key, []).append(item)
        elif is_objective_answer_key(item):
            objective_answer_keys_by_edition[edition_key] = item
        elif is_discursive_proof(item):
            discursive_proofs_by_edition.setdefault(edition_key, []).append(item)
        elif is_discursive_answer_key(item):
            discursive_answer_keys_by_edition[edition_key] = item

    editions = sorted(
        set(objective_proofs_by_edition.keys()) | set(discursive_proofs_by_edition.keys()),
        reverse=True,
    )
    if limit_editions > 0:
        editions = editions[:limit_editions]

    extracted_questions: list[dict] = []
    warnings: list[str] = []

    for edition_key in editions:
        objective_total = 0
        objective_proof_meta = choose_preferred_proof(objective_proofs_by_edition.get(edition_key, []))
        objective_answer_key_meta = objective_answer_keys_by_edition.get(edition_key)
        if objective_proof_meta and objective_answer_key_meta:
            proof_path = ROOT / str(objective_proof_meta["text_path"])
            answer_key_path = ROOT / str(objective_answer_key_meta["text_path"])
            if proof_path.exists() and answer_key_path.exists():
                proof_questions = parse_proof_questions(proof_path)
                answer_key = parse_answer_key(answer_key_path)

                for question_number in sorted(proof_questions.keys()):
                    answer = answer_key.get(question_number)
                    if answer is None:
                        continue

                    extracted_questions.append(
                        build_question_payload(
                            edition_key,
                            question_number,
                            proof_questions[question_number],
                            answer,
                            objective_proof_meta,
                            objective_answer_key_meta,
                        )
                    )
                    objective_total += 1

        discursive_total = 0
        discursive_proof_meta = choose_preferred_proof(discursive_proofs_by_edition.get(edition_key, []))
        discursive_answer_key_meta = discursive_answer_keys_by_edition.get(edition_key)
        if discursive_proof_meta and discursive_answer_key_meta:
            proof_path = ROOT / str(discursive_proof_meta["text_path"])
            answer_key_path = ROOT / str(discursive_answer_key_meta["text_path"])
            if proof_path.exists() and answer_key_path.exists():
                discursive_questions = parse_discursive_proof_questions(proof_path)
                discursive_answers = parse_discursive_answer_key(answer_key_path)

                available_numbers = sorted(set(discursive_questions.keys()) & set(discursive_answers.keys()))
                for question_number in available_numbers:
                    extracted_questions.append(
                        build_discursive_question_payload(
                            edition_key,
                            question_number,
                            discursive_questions[question_number],
                            discursive_answers[question_number]["official_answer"],
                            discursive_proof_meta,
                            discursive_answer_key_meta,
                        )
                    )
                    discursive_total += 1

        if objective_total == 0 and discursive_total == 0:
            warnings.append(f"[skip] {edition_key}: nenhum material oficial utilizável foi encontrado.")
            continue

        warnings.append(
            f"[ok] {edition_key}: {objective_total} objetiva(s) e {discursive_total} discursiva(s) oficial(is) extraída(s)."
        )

    return extracted_questions, warnings


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Extrai questões oficiais do Revalida, incluindo objetiva e discursiva, usando o material do INEP."
    )
    parser.add_argument(
        "--output",
        default=str(OUTPUT_PATH),
        help="Arquivo JSON de saída para as questões extraídas.",
    )
    parser.add_argument(
        "--limit-editions",
        type=int,
        default=0,
        help="Limita a quantidade de edições processadas, da mais recente para a mais antiga.",
    )
    args = parser.parse_args()

    output_path = Path(args.output)
    questions, logs = extract_questions(limit_editions=args.limit_editions)

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(questions, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    for line in logs:
        print(line)

    print(f"\nOK: {len(questions)} questão(ões) oficial(is) salvas em {output_path}")


if __name__ == "__main__":
    main()
