import json
from pathlib import Path
from urllib.parse import urljoin, urlparse

import requests
from bs4 import BeautifulSoup
from slugify import slugify

ROOT = Path(__file__).resolve().parents[1]
SOURCES_PATH = ROOT / "tools" / "sources.json"
OUT_PATH = ROOT / "storage" / "imports" / "pdf_links.json"

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; RevalidaStudyCrawler/0.1; educational-public-docs)"
}

def is_pdf_url(url: str) -> bool:
    path = urlparse(url).path.lower()
    return path.endswith(".pdf") or ".pdf?" in url.lower()

def main():
    sources = json.loads(SOURCES_PATH.read_text(encoding="utf-8"))
    found = []

    for source in sources:
        url = source["url"]
        print(f"[crawl] {source['name']} -> {url}")

        try:
            res = requests.get(url, headers=HEADERS, timeout=30)
            res.raise_for_status()
        except Exception as e:
            print(f"  erro: {e}")
            continue

        soup = BeautifulSoup(res.text, "html.parser")

        for a in soup.find_all("a", href=True):
            href = urljoin(url, a["href"])
            text = " ".join(a.get_text(" ", strip=True).split())

            if is_pdf_url(href):
                filename = Path(urlparse(href).path).name or f"{slugify(text)[:80]}.pdf"
                found.append({
                    "source_name": source.get("name"),
                    "institution": source.get("institution"),
                    "exam": source.get("exam"),
                    "year": source.get("year"),
                    "page_url": url,
                    "pdf_url": href,
                    "link_text": text,
                    "filename": filename
                })

    unique = {}
    for item in found:
        unique[item["pdf_url"]] = item

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    OUT_PATH.write_text(json.dumps(list(unique.values()), ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"\nOK: {len(unique)} PDF(s) encontrados")
    print(f"Salvo em: {OUT_PATH}")

if __name__ == "__main__":
    main()
