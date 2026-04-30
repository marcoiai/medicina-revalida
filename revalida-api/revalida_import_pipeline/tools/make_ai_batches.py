import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEXT_MANIFEST = ROOT / "storage" / "imports" / "text_manifest.json"
BATCH_DIR = ROOT / "storage" / "imports" / "json" / "ai_batches"

PROMPT_TEMPLATE = """
Você é um médico professor criando questões ORIGINAIS para Revalida/Residência.
Use o texto-base abaixo apenas como referência de tema e estilo. NÃO copie enunciados.
Gere até 10 questões inéditas em JSON válido, no formato:

[
  {{
    "area": "Clínica Médica | Pediatria | GO | Cirurgia | Preventiva/SUS",
    "tema": "...",
    "dificuldade": "Fácil | Média | Difícil",
    "enunciado": "...",
    "alternativas": {{
      "A": "...",
      "B": "...",
      "C": "...",
      "D": "...",
      "E": "..."
    }},
    "gabarito": "A|B|C|D|E",
    "comentario": "Explique por que a alternativa correta é correta e comente as armadilhas.",
    "origin": "official_based",
    "status": "draft",
    "reference": "{reference}"
  }}
]

TEXTO-BASE:
{text}
"""

def main():
    manifest = json.loads(TEXT_MANIFEST.read_text(encoding="utf-8"))
    BATCH_DIR.mkdir(parents=True, exist_ok=True)

    for item in manifest:
        text_path = ROOT / item["text_path"]
        text = text_path.read_text(encoding="utf-8", errors="ignore")

        max_chars = 12000
        chunks = [text[i:i+max_chars] for i in range(0, min(len(text), max_chars * 8), max_chars)]

        reference = f"{item.get('institution','')} - {item.get('exam','')} - {item.get('page_url','')}"
        for idx, chunk in enumerate(chunks, 1):
            prompt = PROMPT_TEMPLATE.format(reference=reference, text=chunk)
            out = BATCH_DIR / f"{text_path.stem}_batch_{idx:02d}.prompt.txt"
            out.write_text(prompt, encoding="utf-8")
            print(f"[prompt] {out}")

    print("\nOK: prompts criados em storage/imports/json/ai_batches/")
    print("Cole cada prompt na IA, revise o JSON retornado e junte em storage/imports/questions.json")

if __name__ == "__main__":
    main()
