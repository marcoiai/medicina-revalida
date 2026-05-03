#!/usr/bin/env python3
# -*- coding: utf-8 -*-
import argparse
import json
import re
from collections import OrderedDict
from collections import deque
from pathlib import Path
from urllib.parse import parse_qs, unquote, urljoin, urlparse, urlunparse

import requests
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SOURCES_PATH = ROOT / "tools" / "sources.json"
DEFAULT_OUTPUT_PATH = ROOT / "storage" / "imports" / "official_sources.discovered.json"
SEARCH_ENGINE_URL = "https://html.duckduckgo.com/html/"
SEARCH_TIMEOUT = 6
PAGE_TIMEOUT = 12

HEADERS = {
    "User-Agent": "Mozilla/5.0 (compatible; RevalidaOfficialSourceFinder/1.0; educational-public-docs)"
}

DEFAULT_KEYWORDS = [
    "revalida",
    "provas e gabaritos",
    "gabarito",
    "prova",
    "questão",
    "questoes",
    "exame",
    "residência médica",
    "residencia medica",
    "residencia médica",
]

DEFAULT_ALLOWED_HOSTS = (
    "gov.br",
    "inep.gov.br",
    "ebserh.gov.br",
    "fuvest.br",
)

DEFAULT_SEARCH_QUERIES = [
    'site:gov.br "provas e gabaritos" residência médica',
    'site:gov.br revalida provas gabaritos',
    'site:gov.br "gabarito" "residência médica"',
    'site:gov.br "prova" "gabarito" "residência médica"',
    'site:gov.br "padrão de resposta" residência médica',
    'site:gov.br "residência médica" "prova" "gabarito"',
]

BLOCK_WORDS = (
    "resultado",
    "convocação",
    "convocacao",
    "edital",
    "cronograma",
    "lista",
    "homologação",
    "homologacao",
)


def normalize_url(url: str) -> str:
    parsed = urlparse(url.strip())
    scheme = parsed.scheme or "https"
    netloc = parsed.netloc.lower()
    path = re.sub(r"/{2,}", "/", parsed.path or "/")
    return urlunparse((scheme, netloc, path.rstrip("/"), "", "", ""))


def host_is_official(hostname: str, allowed_hosts: tuple[str, ...]) -> bool:
    host = (hostname or "").lower()
    return any(host == allowed or host.endswith(f".{allowed}") or allowed in host for allowed in allowed_hosts)


def url_is_pdf(url: str) -> bool:
    path = urlparse(url).path.lower()
    return path.endswith(".pdf") or ".pdf?" in url.lower()


def looks_relevant(text: str, href: str, keywords: list[str]) -> bool:
    label = f"{text} {href}".lower()
    if any(word in label for word in BLOCK_WORDS):
        return False
    return any(keyword in label for keyword in keywords)


def fetch_html(url: str) -> str | None:
    try:
        response = requests.get(url, headers=HEADERS, timeout=PAGE_TIMEOUT)
        response.raise_for_status()
    except Exception:
        return None
    return response.text


def parse_source_file(path: Path) -> list[dict]:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return []
    return data if isinstance(data, list) else []


def extract_duckduckgo_url(href: str) -> str | None:
    parsed = urlparse(href)
    query = parse_qs(parsed.query)
    if "uddg" in query and query["uddg"]:
        return unquote(query["uddg"][0])
    return href if href.startswith("http") else None


def search_web(query: str, max_results: int, allowed_hosts: tuple[str, ...], quiet: bool = False, timeout: int = SEARCH_TIMEOUT) -> list[dict]:
    try:
        response = requests.get(SEARCH_ENGINE_URL, params={"q": query}, headers=HEADERS, timeout=timeout)
        response.raise_for_status()
    except Exception as exc:
        if not quiet:
            print(f"  busca falhou: {exc}")
        return []

    soup = BeautifulSoup(response.text, "html.parser")
    candidates: list[dict] = []
    seen: set[str] = set()

    for link in soup.select("a.result__a[href]"):
        href = extract_duckduckgo_url(link.get("href", ""))
        if not href:
            continue

        href = normalize_url(href)
        if href in seen:
            continue
        seen.add(href)

        parsed = urlparse(href)
        if not host_is_official(parsed.hostname or "", allowed_hosts):
            continue

        title = " ".join(link.get_text(" ", strip=True).split())
        candidates.append({
            "name": title or query,
            "institution": parsed.hostname or "search",
            "exam": "Residência Médica",
            "url": href,
            "source": "search",
        })

        if len(candidates) >= max_results:
            break

    return candidates


def crawl_seed(
    seed: dict,
    allowed_hosts: tuple[str, ...],
    keywords: list[str],
    max_depth: int,
    max_pages: int,
    *,
    quiet: bool = False,
) -> list[dict]:
    start_url = seed.get("url")
    if not start_url:
        return []

    start_url = normalize_url(str(start_url))
    start_host = urlparse(start_url).hostname or ""
    if not host_is_official(start_host, allowed_hosts):
        return []

    visited: set[str] = set()
    queue = deque([(start_url, 0)])
    matches: list[dict] = []

    while queue and len(visited) < max_pages:
        url, depth = queue.popleft()
        url = normalize_url(url)
        if url in visited or depth > max_depth:
            continue
        visited.add(url)

        if not quiet and len(visited) % 10 == 0:
            print(f"  páginas visitadas: {len(visited)}/{max_pages}")

        html = fetch_html(url)
        if not html:
            continue

        soup = BeautifulSoup(html, "html.parser")
        page_text = soup.get_text(" ", strip=True)
        page_label = f"{seed.get('name', '')} {page_text}".lower()
        if any(keyword in page_label for keyword in keywords):
            matches.append({
                "name": seed.get("name"),
                "institution": seed.get("institution"),
                "exam": seed.get("exam"),
                "year": seed.get("year"),
                "url": url,
                "source": "discovered",
            })

        for a in soup.find_all("a", href=True):
            href = urljoin(url, a["href"])
            href = normalize_url(href)
            parsed = urlparse(href)
            if not host_is_official(parsed.hostname or "", allowed_hosts):
                continue
            if url_is_pdf(href):
                text = " ".join(a.get_text(" ", strip=True).split())
                if looks_relevant(text, href, keywords):
                    matches.append({
                        "name": seed.get("name"),
                        "institution": seed.get("institution"),
                        "exam": seed.get("exam"),
                        "year": seed.get("year"),
                        "url": href,
                        "source": "pdf",
                    })
                continue
            if depth < max_depth:
                queue.append((href, depth + 1))

    return matches


def main() -> int:
    parser = argparse.ArgumentParser(description="Descobre fontes oficiais a partir de seeds institucionais")
    parser.add_argument("--sources", default=str(DEFAULT_SOURCES_PATH), help="Arquivo JSON com seeds de fontes")
    parser.add_argument("--out", default=str(DEFAULT_OUTPUT_PATH), help="Arquivo de saída")
    parser.add_argument("--max-depth", type=int, default=2, help="Profundidade máxima de crawl interno")
    parser.add_argument("--max-pages", type=int, default=80, help="Máximo de páginas por seed")
    parser.add_argument("--search-results", type=int, default=10, help="Quantidade máxima de resultados por consulta")
    parser.add_argument("--search-timeout", type=int, default=SEARCH_TIMEOUT, help="Timeout em segundos para cada consulta de busca")
    parser.add_argument("--keywords", default=",".join(DEFAULT_KEYWORDS), help="Palavras-chave separadas por vírgula")
    parser.add_argument("--quiet", action="store_true", help="Reduz a verbosidade do progresso")
    args = parser.parse_args()

    seed_path = Path(args.sources)
    seeds = parse_source_file(seed_path)
    if not seeds:
        raise SystemExit(f"Nenhuma seed válida encontrada em {seed_path}")

    allowed_hosts = DEFAULT_ALLOWED_HOSTS
    keywords = [item.strip().lower() for item in args.keywords.split(",") if item.strip()]

    discovered: list[dict] = []
    seen_urls: set[str] = set()
    seeds_by_url: OrderedDict[str, dict] = OrderedDict()

    for seed in seeds:
        url = normalize_url(str(seed.get("url") or ""))
        if url and url not in seeds_by_url:
            seeds_by_url[url] = seed

    search_queries = list(DEFAULT_SEARCH_QUERIES)
    for query_index, query in enumerate(search_queries, start=1):
        if not args.quiet:
            print(f"[search {query_index}/{len(search_queries)}] {query}")
        for result in search_web(
            query,
            max_results=max(1, args.search_results),
            allowed_hosts=allowed_hosts,
            quiet=args.quiet,
            timeout=max(1, args.search_timeout),
        ):
            url = normalize_url(str(result.get("url") or ""))
            if not url or url in seeds_by_url:
                continue
            seeds_by_url[url] = result
        if not args.quiet:
            print(f"  seeds acumuladas: {len(seeds_by_url)}")

    all_seeds = list(seeds_by_url.values())
    total_seeds = len(all_seeds)
    for index, seed in enumerate(all_seeds, start=1):
        if not args.quiet:
            print(f"[discover {index}/{total_seeds}] {seed.get('name')} -> {seed.get('url')}")
        for item in crawl_seed(seed, allowed_hosts, keywords, args.max_depth, args.max_pages, quiet=args.quiet):
            normalized = normalize_url(item["url"])
            if normalized in seen_urls:
                continue
            seen_urls.add(normalized)
            discovered.append(item)
        if not args.quiet:
            print(f"  concluído: {seed.get('name')}")

    out_path = Path(args.out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(
        json.dumps(discovered, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )

    print(f"OK: {len(discovered)} fonte(s)/link(s) oficiais sugeridos")
    print(f"Saída: {out_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
