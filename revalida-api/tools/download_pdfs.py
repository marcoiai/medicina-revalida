import json
from pathlib import Path
from urllib.parse import urlparse

import requests
from slugify import slugify

ROOT = Path(__file__).resolve().parents[1]
LINKS_PATH = ROOT / "storage" / "imports" / "pdf_links.json"
PDF_DIR = ROOT / "storage" / "imports" / "pdfs"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; RevalidaStudyCrawler/0.1; educational-public-docs)"
}

def safe_filename(item):
    filename = item.get("filename") or Path(urlparse(item["pdf_url"]).path).name
    if not filename.lower().endswith(".pdf"):
        filename = slugify(item.get("link_text") or item.get("source_name") or "document") + ".pdf"
    prefix = slugify(f"{item.get('institution','fonte')}-{item.get('exam','prova')}")[:60]
    return f"{prefix}-{filename}"

def main():
    links = json.loads(LINKS_PATH.read_text(encoding="utf-8"))
    PDF_DIR.mkdir(parents=True, exist_ok=True)

    manifest = []
    for i, item in enumerate(links, 1):
        filename = safe_filename(item)
        out = PDF_DIR / filename

        if out.exists() and out.stat().st_size > 0:
            print(f"[skip] {out.name}")
        else:
            print(f"[{i}/{len(links)}] baixando {item['pdf_url']}")
            try:
                r = requests.get(item["pdf_url"], headers=HEADERS, timeout=60)
                r.raise_for_status()
                out.write_bytes(r.content)
            except Exception as e:
                print(f"  erro: {e}")
                continue

        manifest.append({**item, "local_path": str(out.relative_to(ROOT))})

    (ROOT / "storage" / "imports" / "pdf_manifest.json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2),
        encoding="utf-8"
    )

    print("\nOK: manifest salvo em storage/imports/pdf_manifest.json")

if __name__ == "__main__":
    main()
