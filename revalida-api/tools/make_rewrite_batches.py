#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_INPUT = ROOT / "storage" / "imports" / "questions.for-rewrite.json"
DEFAULT_OUTPUT_DIR = ROOT / "storage" / "imports" / "json" / "rewrite_batches"

ALTERNATIVES_PROMPT_TEMPLATE = """
Você é um revisor técnico de questões médicas para Revalida/Residência.

TAREFA:
Reescreva somente as alternativas de cada questão abaixo.

OBJETIVO:
- melhorar alternativas curtas, telegráficas, vagas ou desequilibradas
- tornar as alternativas mais homogêneas em extensão e formato
- preservar tema, enunciado, eixo decisório e gabarito
- deixar as erradas plausíveis, mas incorretas ou menos adequadas
- evitar alternativas caricatas ou obviamente descartáveis

REGRAS CRÍTICAS:
- NÃO altere o enunciado
- NÃO altere o gabarito
- NÃO mude o tema central da questão
- NÃO invente detalhe técnico não sustentado pelo enunciado
- mantenha todas as alternativas na mesma categoria lógica
- evite rótulos secos quando for possível escrever algo mais informativo
- a alternativa correta não pode ficar nitidamente mais longa ou mais específica que as demais
- se a reescrita segura não for possível, marque `rewrite_status` como `manual_review`

FORMATO DE SAÍDA:
Responda APENAS com JSON válido, no formato:
[
  {{
    "id": 123,
    "rewrite_status": "rewritten",
    "rewrite_version": 1,
    "rewritten_alternatives": {{
      "A": "...",
      "B": "...",
      "C": "...",
      "D": "...",
      "E": "..."
    }},
    "answer_changed": false,
    "risk_flags": [],
    "rewrite_notes": [
      "frase curta explicando a reescrita"
    ]
  }}
]

QUESTÕES:
{payload}
"""

COMMENTS_PROMPT_TEMPLATE = """
Você é um revisor técnico de comentários de questões médicas para Revalida/Residência.

TAREFA:
Reescreva somente o comentário justificativo de cada questão abaixo.

OBJETIVO:
- explicar por que a alternativa correta está correta
- explicar por que cada uma das erradas está errada ou é menos adequada
- melhorar densidade justificativa e rastreabilidade do gabarito
- evitar comentário que apenas repete o enunciado

REGRAS CRÍTICAS:
- preserve enunciado, gabarito e alternativas
- não mude o sentido clínico da questão
- se houver risco de ambiguidade clínica, registre em `risk_flags`
- se perceber possível conflito médico real, marque `rewrite_status` como `manual_review`

FORMATO DE SAÍDA:
Responda APENAS com JSON válido, no formato:
[
  {{
    "id": 123,
    "rewrite_status": "rewritten",
    "rewrite_version": 1,
    "rewritten_comment": "...",
    "answer_changed": false,
    "risk_flags": [],
    "rewrite_notes": [
      "frase curta explicando o reforço do comentário"
    ]
  }}
]

QUESTÕES:
{payload}
"""


def chunked(items: list, size: int) -> list[list]:
    return [items[i : i + size] for i in range(0, len(items), size)]


def build_alternatives_payload(items: list[dict]) -> list[dict]:
    payload = []
    for item in items:
        payload.append(
            {
                "id": item["id"],
                "area": item.get("area"),
                "tema": item.get("tema"),
                "dificuldade": item.get("dificuldade"),
                "enunciado": item.get("enunciado"),
                "original_alternatives": item.get("original_alternatives") or item.get("alternativas"),
                "gabarito": item.get("gabarito"),
                "original_comment": item.get("original_comment") or item.get("comentario"),
                "rewrite_reason": item.get("rewrite_reason"),
                "risk_flags": item.get("risk_flags", []),
            }
        )
    return payload


def build_comments_payload(items: list[dict]) -> list[dict]:
    payload = []
    for item in items:
        payload.append(
            {
                "id": item["id"],
                "area": item.get("area"),
                "tema": item.get("tema"),
                "dificuldade": item.get("dificuldade"),
                "enunciado": item.get("enunciado"),
                "alternatives": item.get("rewritten_alternatives")
                or item.get("original_alternatives")
                or item.get("alternativas"),
                "gabarito": item.get("gabarito"),
                "original_comment": item.get("original_comment") or item.get("comentario"),
                "risk_flags": item.get("risk_flags", []),
            }
        )
    return payload


def main():
    parser = argparse.ArgumentParser(
        description="Cria prompts em lote para reescrita de alternativas/comentários de questões."
    )
    parser.add_argument(
        "input",
        nargs="?",
        default=str(DEFAULT_INPUT),
        help="Arquivo JSON exportado para reescrita.",
    )
    parser.add_argument(
        "--mode",
        choices=["alternatives", "comments"],
        default="alternatives",
        help="Tipo de prompt a gerar.",
    )
    parser.add_argument(
        "--batch-size",
        type=int,
        default=20,
        help="Quantidade de questões por prompt.",
    )
    parser.add_argument(
        "--output-dir",
        default=str(DEFAULT_OUTPUT_DIR),
        help="Diretório de saída para os arquivos .prompt.txt",
    )
    args = parser.parse_args()

    input_path = Path(args.input).expanduser().resolve()
    if not input_path.exists():
        raise SystemExit(f"Arquivo não encontrado: {input_path}")

    items = json.loads(input_path.read_text(encoding="utf-8"))
    if not isinstance(items, list):
        raise SystemExit("O arquivo de entrada deve ser um array JSON.")

    output_dir = Path(args.output_dir).expanduser().resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    mode_prefix = "rewrite_alternatives" if args.mode == "alternatives" else "rewrite_comments"
    builder = build_alternatives_payload if args.mode == "alternatives" else build_comments_payload
    template = (
        ALTERNATIVES_PROMPT_TEMPLATE
        if args.mode == "alternatives"
        else COMMENTS_PROMPT_TEMPLATE
    )

    for index, batch in enumerate(chunked(items, max(1, args.batch_size)), start=1):
        payload = builder(batch)
        prompt = template.format(
            payload=json.dumps(payload, ensure_ascii=False, indent=2)
        )
        out = output_dir / f"{mode_prefix}_batch_{index:03d}.prompt.txt"
        out.write_text(prompt, encoding="utf-8")
        print(f"[prompt] {out}")

    print("\nOK: prompts de reescrita criados.")


if __name__ == "__main__":
    main()
