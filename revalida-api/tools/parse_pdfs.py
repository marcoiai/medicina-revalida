import json
from pathlib import Path

import fitz  # PyMuPDF

ROOT = Path(__file__).resolve().parents[1]
MANIFEST_PATH = ROOT / "storage" / "imports" / "pdf_manifest.json"
TEXT_DIR = ROOT / "storage" / "imports" / "text"

def main():
    manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    if not isinstance(manifest, list) or not manifest:
        raise SystemExit("Nenhum PDF disponível em storage/imports/pdf_manifest.json para converter em texto.")

    TEXT_DIR.mkdir(parents=True, exist_ok=True)

    parsed = []
    for item in manifest:
        pdf_path = ROOT / item["local_path"]
        out_path = TEXT_DIR / (pdf_path.stem + ".txt")

        print(f"[parse] {pdf_path.name}")
        try:
            doc = fitz.open(pdf_path)
            chunks = []
            for page_number, page in enumerate(doc, start=1):
                text = page.get_text()
                chunks.append(f"\n\n--- PAGE {page_number} ---\n\n{text}")
            out_path.write_text("".join(chunks), encoding="utf-8")
            parsed.append({**item, "text_path": str(out_path.relative_to(ROOT))})
        except Exception as e:
            print(f"  erro: {e}")

    (ROOT / "storage" / "imports" / "text_manifest.json").write_text(
        json.dumps(parsed, ensure_ascii=False, indent=2),
        encoding="utf-8"
    )

    if not parsed:
        raise SystemExit("Nenhum PDF pôde ser convertido em texto.")

    print("\nOK: textos salvos em storage/imports/text/")
    print("Manifest: storage/imports/text_manifest.json")

if __name__ == "__main__":
    main()
