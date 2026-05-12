import json
import os
from pathlib import Path
from urllib.parse import urlparse

import requests
import urllib3
from requests.exceptions import SSLError
from slugify import slugify

ROOT = Path(__file__).resolve().parents[1]
LINKS_PATH = ROOT / "storage" / "imports" / "pdf_links.json"
PDF_DIR = ROOT / "storage" / "imports" / "pdfs"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; RevalidaStudyCrawler/0.1; educational-public-docs)"
}
ALLOW_INSECURE_SSL_FALLBACK = os.getenv("QUESTIONS_ALLOW_INSECURE_SSL_FALLBACK", "1").strip().lower() not in {"0", "false", "no"}
INSECURE_SSL_FALLBACK_HOSTS = {"download.inep.gov.br"}

def safe_filename(item):
    filename = item.get("filename") or Path(urlparse(item["pdf_url"]).path).name
    if not filename.lower().endswith(".pdf"):
        filename = slugify(item.get("link_text") or item.get("source_name") or "document") + ".pdf"
    prefix = slugify(f"{item.get('institution','fonte')}-{item.get('exam','prova')}")[:60]
    return f"{prefix}-{filename}"


def should_allow_insecure_fallback(url: str) -> bool:
    host = (urlparse(url).hostname or "").strip().lower()
    return ALLOW_INSECURE_SSL_FALLBACK and host in INSECURE_SSL_FALLBACK_HOSTS


def download_content(url: str) -> bytes:
    try:
        response = requests.get(url, headers=HEADERS, timeout=60)
        response.raise_for_status()
        return response.content
    except SSLError as exc:
        if not should_allow_insecure_fallback(url):
            raise

        urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
        print("  aviso: SSL falhou; tentando novamente sem verificação para este host")
        response = requests.get(url, headers=HEADERS, timeout=60, verify=False)
        response.raise_for_status()
        return response.content

def main():
    links = json.loads(LINKS_PATH.read_text(encoding="utf-8"))
    if not isinstance(links, list) or not links:
        raise SystemExit("Nenhum link em storage/imports/pdf_links.json. Rode o crawl antes.")

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
                out.write_bytes(download_content(item["pdf_url"]))
            except Exception as e:
                print(f"  erro: {e}")
                continue

        manifest.append({**item, "local_path": str(out.relative_to(ROOT))})

    if not manifest:
        raise SystemExit(
            "Nenhum PDF foi baixado com sucesso. Verifique SSL/rede ou ajuste QUESTIONS_ALLOW_INSECURE_SSL_FALLBACK."
        )

    (ROOT / "storage" / "imports" / "pdf_manifest.json").write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2),
        encoding="utf-8"
    )

    print("\nOK: manifest salvo em storage/imports/pdf_manifest.json")

if __name__ == "__main__":
    main()
